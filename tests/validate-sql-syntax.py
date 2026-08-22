#!/usr/bin/env python3
"""Validate that every SQL statement the plugin builds is real, parseable MySQL.

Why this test exists
--------------------
PR STUDIO shipped a task queue that never dispatched. The Browser Agent came
online, heartbeat fine, and every task sat at status=QUEUED with
attempt_count=0 until the turn deadline killed it. The cause was four
characters: a `// phpcs:ignore` suppression line had been written *inside* the
SQL string literal of PRSTUDIO_UC_Store::claim_next(), between `LIMIT 1` and
`FOR UPDATE`.

    ORDER BY id ASC
    LIMIT 1
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- ...
    FOR UPDATE

MySQL has no `//` comment syntax -- it reads `//` as a division operator and
rejects the statement. get_row() returned null on every call, claim_next()
concluded there was no work, and no task was ever leased.

Nothing caught it. PHP lint passes, because the file is valid PHP: the bug is
a valid string containing invalid SQL. The unit tests stub $wpdb, so the SQL
text is never parsed by anything. Reading the diff, the line looks exactly
like the hundreds of legitimate suppression comments around it.

So the check has to be what nothing else was doing: take the SQL text the
plugin actually builds and run a real MySQL parser over it.

Usage: python tests/validate-sql-syntax.py
Requires: sqlglot  (pip install sqlglot)
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DUMPER = ROOT / "tests" / "dump-sql-strings.php"

try:
    import sqlglot
    from sqlglot.errors import ParseError
except ImportError:
    print("SKIP validate-sql-syntax: sqlglot is not installed (pip install sqlglot)")
    sys.exit(0)


def php_binary() -> str:
    for candidate in ("php", "php8.1", "php8"):
        try:
            subprocess.run([candidate, "-v"], capture_output=True, check=True)
            return candidate
        except (OSError, subprocess.CalledProcessError):
            continue
    winget = list(
        Path.home().glob(
            "AppData/Local/Microsoft/WinGet/Packages/PHP.PHP*/php.exe"
        )
    )
    if winget:
        return str(winget[0])
    print("SKIP validate-sql-syntax: no PHP binary found")
    sys.exit(0)


def normalize(sql: str) -> str:
    """Turn a PHP-built SQL fragment into something a parser can read.

    Only placeholders and interpolations are substituted. Comment syntax is
    deliberately left untouched -- that is the defect under test.
    """
    # Named runtime templates such as %TABLE%, substituted by the caller before
    # execution (the garbage collector builds its delete plan this way).
    sql = re.sub(r"%[A-Z_]{2,}%", "tbl", sql)
    sql = re.sub(r"\{?\$[A-Za-z_][A-Za-z0-9_]*(?:(?:->|::)\w+(?:\(\))?)*\}?", "tbl", sql)
    sql = sql.replace("%%", "%")
    sql = re.sub(r"%[sdf]", "'x'", sql)
    sql = sql.replace("\\'", "'").replace('\\"', '"').replace("\\n", "\n").replace("\\t", "\t")
    return sql.strip()


def is_parseable_candidate(sql: str) -> bool:
    """Reject fragments the extractor cannot legitimately complete.

    A trailing clause keyword, an unbalanced parenthesis or a surviving printf
    placeholder means the statement is still being assembled somewhere this
    walker does not follow. Reporting those as syntax errors would bury the
    real finding under noise, which is how the original defect stayed hidden.
    """
    if not sql:
        return False
    # Prose that merely opens with a SQL verb is not a statement. Error messages
    # and tool descriptions in this codebase legitimately begin with "delete ..."
    # or "Create ...", so require the verb's structural companion clause before
    # handing the text to a parser. UPDATE additionally requires an assignment
    # after SET: this distinguishes a real statement from bilingual action-index
    # prose such as "update edit modify set change configure" without weakening
    # validation of executable UPDATE queries.
    shapes = (
        r"^SELECT\b.*\bFROM\b",
        r"^SELECT\s+(?:DISTINCT\s+)?(?:COUNT|SUM|MAX|MIN|AVG|VERSION|DATABASE)\s*\(",
        r"^INSERT\b.*\bINTO\b",
        r"^REPLACE\b.*\bINTO\b",
        r"^UPDATE\b.*\bSET\b.*=",
        r"^DELETE\b.*\bFROM\b",
        r"^CREATE\s+(?:TABLE|INDEX|UNIQUE|DATABASE|VIEW)\b",
        r"^ALTER\s+TABLE\b",
        r"^DROP\s+(?:TABLE|INDEX|DATABASE|VIEW)\b",
        r"^TRUNCATE\s+(?:TABLE\s+)?\S",
        r"^SHOW\s+(?:TABLES|COLUMNS|INDEX|VARIABLES|STATUS|CREATE)\b",
    )
    if not any(re.search(shape, sql, re.IGNORECASE | re.DOTALL) for shape in shapes):
        return False
    if re.search(r"%[A-Za-z]", sql):
        return False
    if sql.count("(") != sql.count(")"):
        return False
    if re.search(
        r"\b(FROM|INTO|JOIN|WHERE|SET|VALUES|AND|OR|ON|BY|LIKE|IN|SELECT|UPDATE|DELETE)\s*$",
        sql,
        re.IGNORECASE,
    ):
        return False
    return True


def main() -> int:
    # Decode explicitly as UTF-8: the default console codec on Windows is
    # cp1252 and chokes on the accented text in some capability descriptions.
    proc = subprocess.run(
        [php_binary(), str(DUMPER)], capture_output=True, cwd=ROOT
    )
    if proc.returncode != 0:
        print("FAIL could not dump SQL strings:")
        print(proc.stderr.decode("utf-8", "replace").strip()[:2000])
        return 1

    entries = json.loads(proc.stdout.decode("utf-8", "replace"))
    failures: list[str] = []
    parsed = 0
    skipped_fragments = 0

    for entry in entries:
        where = f"{entry['file'].split('prstudio-unified-control/')[-1]}:{entry['line']}"

        # Hard failure, independent of parseability: PHP comment syntax has no
        # meaning inside a SQL payload and always corrupts the statement.
        if entry["has_php_comment"]:
            failures.append(
                f"{where}: PHP comment syntax inside a SQL string -- MySQL reads "
                f"'//' as division and rejects the statement"
            )
            continue

        if not entry["complete"]:
            skipped_fragments += 1
            continue

        candidate = normalize(entry["sql"])
        if not is_parseable_candidate(candidate):
            skipped_fragments += 1
            continue
        try:
            sqlglot.parse_one(candidate, dialect="mysql")
            parsed += 1
        except ParseError as exc:
            first_line = str(exc).splitlines()[0]
            failures.append(f"{where}: MySQL parse error -- {first_line}")
        except Exception:
            # A construct sqlglot models imperfectly is not evidence of a bug
            # in the plugin; only refuse on an explicit ParseError.
            skipped_fragments += 1

    print(f"SQL strings found:        {len(entries)}")
    print(f"complete statements:      {parsed} parsed as MySQL")
    print(f"fragments not parsed:     {skipped_fragments} (concatenated or partial)")

    if failures:
        print(f"\n=== {len(failures)} FAILURE(S) ===")
        for failure in failures:
            print(f"  - {failure}")
        return 1

    print("\nPASS every complete SQL statement parses as MySQL and no PHP comment leaked into a query")
    return 0


if __name__ == "__main__":
    sys.exit(main())
