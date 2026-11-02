#!/usr/bin/env bash
# Local self-test of assert-release-tag.sh: a matching tag passes, bad tags fail.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
printf '<?php\n$plugin->release      = '"'"'0.1.0'"'"';\n' > "${tmp}/version.php"

fail=0
expect() { # expect <0|1> <tag>
  "${here}/assert-release-tag.sh" "$2" "${tmp}/version.php" >/dev/null 2>&1
  rc=$?
  if [ "${rc}" -ne "$1" ]; then echo "FAIL: tag '$2' exit ${rc}, expected $1"; fail=1; else echo "ok: tag '$2' exit ${rc}"; fi
}
expect 0 v0.1.0
expect 1 v0.1.1
expect 1 0.1.0
expect 1 v0.1.0-rc1
expect 1 ""
"${here}/assert-release-tag.sh" v0.1.0 "${tmp}/missing.php" >/dev/null 2>&1 && { echo "FAIL: missing version.php accepted"; fail=1; } || echo "ok: missing version.php refused"
exit "${fail}"
