#!/usr/bin/env bash
# Source-level guards of the student path (discuss-student plan, C5 and F3).
#   C5: the AMD sources never post to "*" and never use browser storage (the token lives in memory only).
#   F3: the server side keeps no state and caches nothing (ARCH-065, COMP-001): no $SESSION, no MUC, no
#       user preferences in classes/ and the student endpoints.
# Usage: assert-student-guards.sh (from the repository root)
set -euo pipefail

fail=0

check() {
  local label="$1" pattern="$2"
  shift 2
  local paths=()
  for p in "$@"; do
    [ -e "$p" ] && paths+=("$p")
  done
  [ "${#paths[@]}" -eq 0 ] && return 0
  local hits
  hits="$(grep -rnE --include='*.js' --include='*.php' -e "${pattern}" "${paths[@]}" || true)"
  if [ -n "${hits}" ]; then
    echo "::error::${label}"
    echo "${hits}"
    fail=1
  fi
}

check "postMessage to any origin ('*') in amd/src" "postMessage\([^)]*['\"]\*['\"]" amd/src
check "browser storage in amd/src" "(localStorage|sessionStorage|indexedDB)" amd/src
check "server-side state or cache in the student path" \
  "(\\\$SESSION|cache::make|\\\\cache\b|\\\\cache::|set_user_preference)" classes ajax.php transcript.php

if [ "${fail}" -ne 0 ]; then
  exit 1
fi
echo "Student guards OK"
