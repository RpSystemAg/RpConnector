#!/usr/bin/env python3
"""LAW 9 measured with a real tokenizer instead of a divisor.

Why this exists
---------------
The tools/list ceiling is enforced by PRSTUDIO_UC_MCP_V5::tools_within_budget(),
which assembles the surface until it reaches TOOLS_LIST_TOKEN_BUDGET * 4 bytes.
Four bytes per token is a guess. Nothing had ever compared it to what a
tokenizer actually produces, so the law was being enforced against a number
nobody had checked.

Measured on 19 Aug 2026 against the emitted surface, o200k_base and cl100k_base
agree to within 0.2%: 19,617 bytes encode to 4,089 real tokens, a ratio of 4.80
bytes per token. The divisor is therefore conservative by about twenty percent
-- the surface is at 82% of the ceiling while the internal estimate reports 98%.

That is the safe direction, and it is not the point. The point is the direction
it could move. The ratio is a property of the text, not a constant: descriptions
with heavy punctuation, long snake_case identifiers, base64 payloads or non-Latin
characters all tokenize denser. If the ratio ever falls below 4.0, the divisor
starts under-counting, the assembler admits more than the ceiling allows, and
the failure is silent in the worst way -- the host does not reject the server,
it keeps the tools visible in the prompt and stops letting the model call them.
That presents as a permissions or protocol fault and sends the investigation
somewhere else entirely, which is exactly what LAW 9 exists to prevent.

So this asserts two things:

  1. the emitted surface is under 5,000 real tokens; and
  2. the divisor is still conservative -- the internal estimate is greater than
     or equal to the real count.

The second is the one that matters. It fails the moment the guess stops being
safe, while (1) would still be passing.

Both encodings are checked. They agree today; a future divergence is worth
seeing rather than averaging away.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BUDGET_TOKENS = 5000
ENCODINGS = ("o200k_base", "cl100k_base")

# Emits exactly what a host ingests for tool selection, after the budget
# assembler has run. Kept here rather than as a file under tests/ so it is not a
# second surface file that has to justify its own execution.
DUMP_PHP = r"""<?php
declare( strict_types = 1 );
define( 'PRSTUDIO_UC_TESTING', true );
require %s . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';

$method = new ReflectionMethod( 'PRSTUDIO_UC_MCP_V5', 'tools_within_budget' );
$method->setAccessible( true );
$budgeted = $method->invoke( null );

$surface = array_map(
    static function ( array $tool ): array {
        return array(
            'name'        => $tool['name'] ?? '',
            'description' => $tool['description'] ?? '',
            'inputSchema' => $tool['inputSchema'] ?? array(),
        );
    },
    $budgeted['tools']
);

echo json_encode(
    array(
        'surface'        => json_encode( $surface, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
        'emitted'        => count( $budgeted['tools'] ),
        'withheld'       => $budgeted['withheld'],
        'estimated'      => $budgeted['tokens'],
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
"""


def php_binary() -> str:
    """Locate PHP.

    RP_PHP_BIN wins so a machine without php on PATH can still run this bare.
    A missing interpreter is a hard failure: LAW 11 has no skip class, and a
    check that quietly passes when it could not run is the defect it exists to
    catch.
    """
    override = os.environ.get("RP_PHP_BIN", "").strip()
    if override:
        return override
    found = shutil.which("php")
    if not found:
        print(
            "no PHP interpreter: set RP_PHP_BIN or put php on PATH. "
            "This check cannot certify the token ceiling without running the assembler.",
            file=sys.stderr,
        )
        raise SystemExit(1)
    return found


def emitted_surface() -> dict:
    php = php_binary()
    with tempfile.TemporaryDirectory() as tmp:
        script = Path(tmp) / "dump-surface.php"
        script.write_text(DUMP_PHP % json.dumps(ROOT.as_posix()), encoding="utf-8")
        run = subprocess.run(
            [php, "-d", "error_reporting=E_ALL", "-f", str(script)],
            capture_output=True,
            text=True,
            timeout=120,
        )
    if run.returncode != 0:
        print(f"the surface assembler did not run (exit {run.returncode})", file=sys.stderr)
        print(run.stderr[-2000:], file=sys.stderr)
        raise SystemExit(1)
    try:
        return json.loads(run.stdout)
    except json.JSONDecodeError:
        print("the surface assembler produced no parseable JSON", file=sys.stderr)
        print(run.stdout[-2000:], file=sys.stderr)
        raise SystemExit(1)


def main() -> int:
    try:
        import tiktoken
    except ImportError:
        print(
            "tiktoken is required to measure the ceiling. Install it in the job that "
            "runs this check; estimating instead is what this replaces.",
            file=sys.stderr,
        )
        return 1

    data = emitted_surface()
    payload = data["surface"]
    payload_bytes = len(payload.encode("utf-8"))
    estimated = int(data["estimated"])

    print(
        f"emitted tools: {data['emitted']}  withheld: {data['withheld']}  "
        f"bytes: {payload_bytes}  internal estimate: {estimated} tokens"
    )

    problems = []
    for name in ENCODINGS:
        real = len(tiktoken.get_encoding(name).encode(payload))
        ratio = payload_bytes / real if real else 0.0
        headroom = BUDGET_TOKENS - real
        print(f"  {name:12} real tokens: {real:6d}  bytes/token: {ratio:.2f}  headroom: {headroom:+d}")

        if real >= BUDGET_TOKENS:
            problems.append(
                f"{name}: the emitted surface is {real} real tokens, at or over the "
                f"{BUDGET_TOKENS} ceiling. A host that enforces this keeps the tools "
                f"visible and stops letting the model call them."
            )
        if estimated < real:
            problems.append(
                f"{name}: the internal divisor now UNDER-counts -- it estimates "
                f"{estimated} tokens where the tokenizer measures {real} "
                f"(bytes/token has fallen to {ratio:.2f}, below the 4.0 the assembler "
                f"assumes). tools_within_budget() will admit more than the ceiling "
                f"allows, silently. Lower the divisor in "
                f"PRSTUDIO_UC_MCP_V5::tools_within_budget(); do not raise the budget."
            )

    if problems:
        print("", file=sys.stderr)
        for item in problems:
            print(f"  {item}", file=sys.stderr)
        return 1

    print("the emitted surface is under the ceiling and the divisor is still conservative")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
