#!/usr/bin/env python3
"""Fail when a postMessage() call in the given JS files has a wildcard or missing target origin.

Only the target-origin argument is inspected (the second top-level argument), so a '*' inside the
payload is fine, while nested payload expressions and calls split across lines are parsed. Comments
are skipped. Usage: check_postmessage_targets.py <file or directory>...; exit 1 on any finding.
"""

import re
import sys
from pathlib import Path

CALL = re.compile(r"\bpostMessage\s*\(")
WILDCARD = re.compile(r"""^(['"`])\*\1$""")


def strip_comments(src: str) -> str:
    """Blank out // and /* */ comments, keeping strings and line numbers intact."""
    out, i, n = [], 0, len(src)
    quote = None
    while i < n:
        c = src[i]
        if quote:
            out.append(c)
            if c == "\\" and i + 1 < n:
                out.append(src[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c in "'\"`":
            quote = c
            out.append(c)
            i += 1
        elif src.startswith("//", i):
            j = src.find("\n", i)
            j = n if j == -1 else j
            out.append(" " * (j - i))
            i = j
        elif src.startswith("/*", i):
            j = src.find("*/", i + 2)
            j = n if j == -1 else j + 2
            out.append("".join(ch if ch == "\n" else " " for ch in src[i:j]))
            i = j
        else:
            out.append(c)
            i += 1
    return "".join(out)


def call_arguments(src: str, start: int) -> list[str] | None:
    """Top-level arguments of the call whose '(' is at start, or None when unbalanced."""
    depth, quote, i = 0, None, start
    args, current = [], []
    while i < len(src):
        c = src[i]
        if quote:
            current.append(c)
            if c == "\\" and i + 1 < len(src):
                current.append(src[i + 1])
                i += 2
                continue
            if c == quote:
                quote = None
        elif c in "'\"`":
            quote = c
            current.append(c)
        elif c in "([{":
            if depth > 0:
                current.append(c)
            depth += 1
        elif c in ")]}":
            depth -= 1
            if depth == 0:
                args.append("".join(current).strip())
                return [a for a in args if a != ""]
            current.append(c)
        elif c == "," and depth == 1:
            args.append("".join(current).strip())
            current = []
        else:
            current.append(c)
        i += 1
    return None


def check(path: Path) -> list[str]:
    src = strip_comments(path.read_text(encoding="utf-8"))
    findings = []
    for m in CALL.finditer(src):
        line = src.count("\n", 0, m.start()) + 1
        args = call_arguments(src, m.end() - 1)
        if args is None:
            findings.append(f"{path}:{line}: unbalanced postMessage call")
        elif len(args) < 2:
            findings.append(f"{path}:{line}: postMessage without a target origin")
        elif WILDCARD.match(args[1]):
            findings.append(f"{path}:{line}: postMessage to any origin ('*')")
    return findings


def main(argv: list[str]) -> int:
    files = []
    for arg in argv:
        p = Path(arg)
        files.extend(sorted(p.rglob("*.js")) if p.is_dir() else [p])
    findings = [f for path in files for f in check(path)]
    for f in findings:
        print(f)
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
