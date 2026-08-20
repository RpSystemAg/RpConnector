#!/usr/bin/env python3
"""Every $wpdb->prepare() must pass exactly as many arguments as its SQL has
placeholders.

Why this check exists
---------------------
When the counts disagree WordPress does not raise. `wpdb::prepare()` calls
`_doing_it_wrong()` and returns an empty string, so `$wpdb->query('')` returns
false and the caller reads that as "nothing matched". The statement never runs
and nothing in the logs says so.

That is how `recover_stale_tasks()` sat broken: `status IN (%s, %s, %s)` against
two lease-holding states, so six placeholders received five arguments and no
stale lease was ever recovered. A task claimed by a device that then died stayed
`leased` forever. `php -l` passes it, the SQL parses as valid MySQL, and unit
tests with a mocked $wpdb pass -- only counting the two sides catches it.

What it deliberately does not do
--------------------------------
A call is counted statically when the placeholder count is knowable: the SQL
must be built from string literals plus expressions that cannot themselves
contain a placeholder (a table-name helper, `$wpdb->options`). Dynamic SQL and
dynamic argument lists are reported as requiring runtime review; they remain in
the inventory and are not described as excluded or passed by this static proof.

Exit code 1 on any mismatch.
"""
import re
import sys
from pathlib import Path

PLUGIN = Path(__file__).resolve().parent.parent / "prstudio-unified-control"

# %s %d %f %F %i, optionally positional (%1$s) or padded (%'05d). Not %%.
PLACEHOLDER = re.compile(r"%(?:\d+\$)?(?:'.)?[-+]?\d*(?:\.\d+)?[sdfFi]")

STRING_LITERAL = re.compile(r"'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\"")

# Expressions that resolve to an identifier (a table or column name). These can
# never introduce a placeholder, so SQL concatenated from them stays countable.
IDENTIFIER_EXPR = re.compile(
    r"^(?:"
    r"(?:self|static|parent)::\w+\(\s*\)"      # self::tasks_table()
    r"|[A-Z_][A-Z0-9_]*::\w+\(\s*\)"           # PRSTUDIO_UC_Store::table()
    r"|\$wpdb->\w+"                            # $wpdb->options
    r"|\$this->\w+\(\s*\)"
    r")$"
)

# A bare "$table"-ish variable interpolated inside a double-quoted string. Same
# reasoning as above, but only for names that clearly denote an identifier --
# "$sql" or "$where" could carry placeholders and must not be waved through.
IDENTIFIER_VAR = re.compile(r"^\$(?:\w*table\w*|\w*tbl\w*|prefix)$", re.IGNORECASE)


def mask(src: str):
    """Return (structure, code).

    structure: comments AND string contents blanked -- safe for finding
               brackets and top-level commas.
    code:      comments blanked, strings intact -- safe for reading literals.

    Both keep the original offsets so reported line numbers point at real code.
    """
    structure = list(src)
    code = list(src)
    i, n = 0, len(src)

    def blank(target, a, b):
        for k in range(a, b):
            if src[k] != "\n":
                target[k] = " "

    while i < n:
        c = src[i]
        if c == "/" and i + 1 < n and src[i + 1] == "/":
            j = src.find("\n", i)
            j = n if j < 0 else j
            blank(structure, i, j)
            blank(code, i, j)
            i = j
        elif c == "#" and not (i + 1 < n and src[i + 1] == "["):
            j = src.find("\n", i)
            j = n if j < 0 else j
            blank(structure, i, j)
            blank(code, i, j)
            i = j
        elif c == "/" and i + 1 < n and src[i + 1] == "*":
            j = src.find("*/", i + 2)
            j = n if j < 0 else j + 2
            blank(structure, i, j)
            blank(code, i, j)
            i = j
        elif c in "'\"":
            quote = c
            j = i + 1
            while j < n:
                if src[j] == "\\":
                    j += 2
                    continue
                if src[j] == quote:
                    break
                j += 1
            j = min(j, n - 1)
            blank(structure, i + 1, j)
            i = j + 1
        else:
            i += 1
    return "".join(structure), "".join(code)


def top_level_split(structure: str, start: int, end: int, sep: str):
    """Split [start,end) on `sep` characters that sit at bracket depth zero."""
    parts, depth, cur = [], 0, start
    for i in range(start, end):
        ch = structure[i]
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif ch == sep and depth == 0:
            parts.append((cur, i))
            cur = i + 1
    parts.append((cur, end))
    return [(a, b) for a, b in parts if structure[a:b].strip()]


def count_placeholders(code_slice: str, structure_slice: str, offset: int):
    """Count placeholders in a concatenated SQL expression.

    Returns (count, None) when countable, or (None, reason) when not.
    """
    total = 0
    for a, b in top_level_split(structure_slice, 0, len(structure_slice), "."):
        piece = code_slice[a:b].strip()
        if not piece:
            continue
        literal = STRING_LITERAL.fullmatch(piece)
        if literal:
            body = piece[1:-1]
            # Interpolation only happens in double-quoted strings.
            if piece[0] == '"':
                for var in re.findall(r"\$\w+(?:->\w+)?|\{\$[^}]+\}", body):
                    name = var.strip("{}")
                    if not IDENTIFIER_VAR.match(name.split("->")[0]):
                        return None, f"interpolates {var} into the SQL"
            total += len(PLACEHOLDER.findall(body.replace("%%", "")))
            continue
        if IDENTIFIER_EXPR.match(piece):
            continue
        return None, f"SQL is not fully literal ({piece[:44]})"
    return total, None


def main() -> int:
    problems, skipped, checked = [], [], 0

    for path in sorted(PLUGIN.rglob("*.php")):
        src = path.read_text(encoding="utf-8", errors="replace")
        structure, code = mask(src)

        for m in re.finditer(r"->prepare\s*\(", structure):
            open_paren = m.end() - 1
            depth, close = 0, -1
            for i in range(open_paren, len(structure)):
                if structure[i] == "(":
                    depth += 1
                elif structure[i] == ")":
                    depth -= 1
                    if depth == 0:
                        close = i
                        break
            if close < 0:
                continue

            args = top_level_split(structure, open_paren + 1, close, ",")
            if not args:
                continue

            line = src.count("\n", 0, m.start()) + 1
            where = f"{path.relative_to(PLUGIN.parent).as_posix()}:{line}"

            a0, b0 = args[0]
            placeholders, reason = count_placeholders(code[a0:b0], structure[a0:b0], a0)
            if placeholders is None:
                skipped.append(f"{where}  {reason}")
                continue

            rest = [code[a:b].strip() for a, b in args[1:]]
            if any(a.startswith("...") for a in rest):
                skipped.append(f"{where}  argument list uses a spread")
                continue
            if len(rest) == 1 and placeholders != 1 and not STRING_LITERAL.fullmatch(rest[0]):
                # wpdb::prepare() also accepts a single array of arguments.
                if re.fullmatch(r"\$\w+|array\s*\(.*|\[.*", rest[0], re.S):
                    skipped.append(f"{where}  single argument may be an array of {placeholders}")
                    continue

            checked += 1
            if placeholders != len(rest):
                snippet = " ".join(" ".join(code[a0:b0].split()).split())[:100]
                problems.append(
                    f"{where}\n"
                    f"      {placeholders} placeholder(s) but {len(rest)} argument(s)\n"
                    f"      {snippet}"
                )

    print(f"wpdb::prepare arity: {checked} call(s) counted, {len(skipped)} require dynamic review")
    for s in skipped:
        print(f"  DYNAMIC_REVIEW {s}")

    if problems:
        print(f"\n{len(problems)} MISMATCH(ES) -- these queries silently do nothing:", file=sys.stderr)
        for p in problems:
            print(f"  {p}", file=sys.stderr)
        return 1

    print("no arity mismatches")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
