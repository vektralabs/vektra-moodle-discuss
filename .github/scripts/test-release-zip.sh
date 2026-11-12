#!/usr/bin/env bash
# Local self-test of assert-release-zip.sh: a plugin-only zip passes; a zip carrying the provider
# tree, a Python file or a wheel fails.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT

fail=0
# expect <0|1> <label> <extra path>...: builds a zip of a minimal plugin plus the extra paths
expect() {
  local want="$1" label="$2"
  shift 2
  local root="${tmp}/${label}"
  mkdir -p "${root}/p/tests" "${root}/p/classes/privacy"
  echo '<?php' > "${root}/p/version.php"
  echo '<?php' > "${root}/p/tests/a_test.php"
  echo '<?php' > "${root}/p/classes/privacy/provider.php"
  local extra
  for extra in "$@"; do
    mkdir -p "$(dirname "${root}/p/${extra}")"
    echo x > "${root}/p/${extra}"
  done
  (cd "${root}" && zip -qr "${root}.zip" p)
  bash "${here}/assert-release-zip.sh" "${root}.zip" p >/dev/null 2>&1
  local rc=$?
  if [ "${rc}" -ne "${want}" ]; then echo "FAIL: ${label} exit ${rc}, expected ${want}"; fail=1; else echo "ok: ${label} exit ${rc}"; fi
}
expect 0 plugin-only
expect 1 provider-tree provider/pyproject.toml
expect 1 python-in-classes classes/helper.py
expect 1 wheel tests/vektra_lms_moodle-0.9.0.dev1-py3-none-any.whl
exit "${fail}"
