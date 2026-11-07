#!/usr/bin/env bash
# Regression cases for assert-student-guards.sh: each planted file must be caught, the clean ones not.
# Usage: test-student-guards.sh (from the repository root)
set -euo pipefail

root="$(pwd)"
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT
fail=0

# expect <name> <expected exit 0|1> <relative path> <content>
expect() {
  local name="$1" want="$2" rel="$3" content="$4"
  local dir="${work}/${name}"
  mkdir -p "${dir}/$(dirname "${rel}")"
  printf '%s\n' "${content}" > "${dir}/${rel}"
  local got=0
  (cd "${dir}" && bash "${root}/.github/scripts/assert-student-guards.sh" >/dev/null 2>&1) || got=1
  if [ "${got}" != "${want}" ]; then
    echo "::error::guard case '${name}': expected exit ${want}, got ${got}"
    fail=1
  else
    echo "ok: ${name}"
  fi
}

expect wildcard-simple 1 amd/src/a.js "w.postMessage({a: 1}, '*');"
expect wildcard-nested-payload 1 amd/src/a.js "w.postMessage(buildPayload(f(x)), '*');"
expect wildcard-next-line 1 amd/src/a.js $'w.postMessage(\n    {type: "t", token: t},\n    "*"\n);'
expect wildcard-backtick 1 amd/src/a.js 'w.postMessage({a: 1}, `*`);'
expect no-target 1 amd/src/a.js "w.postMessage({a: 1});"
expect no-target-trailing-comma 1 amd/src/a.js "w.postMessage({a: 1},);"
expect wildcard-nested-dir 1 amd/src/sub/deep/a.js "w.postMessage({a: 1}, '*');"
expect star-in-payload-only 0 amd/src/a.js "w.postMessage({glob: '*'}, origin);"
expect star-in-comment-only 0 amd/src/a.js "// w.postMessage(x, '*');
w.postMessage(x, origin);"
expect local-storage 1 amd/src/a.js "localStorage.setItem('k', v);"
expect server-session 1 classes/a.php "<?php \$x = \$SESSION->y;"
expect server-cache 1 classes/a.php "<?php \$c = \\cache::make('a', 'b');"
expect clean 0 amd/src/a.js "w.postMessage({type: 'x'}, origin);"

exit "${fail}"
