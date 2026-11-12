#!/usr/bin/env bash
# Assert the content of a release zip (listing, not workflow status).
# Usage: assert-release-zip.sh <zip> <prefix>
set -euo pipefail

zip="$1"
prefix="$2"
list="$(unzip -Z1 "$zip")"

echo "${list}" | head -40

fail=0

# Must-have entries.
if ! grep -qx "${prefix}/version.php" <<<"${list}"; then
  echo "::error::${prefix}/version.php missing from the zip"
  fail=1
fi
if ! grep -q "^${prefix}/tests/" <<<"${list}"; then
  echo "::error::${prefix}/tests/ missing from the zip (Moodle plugins ship their tests)"
  fail=1
fi
# Compiled JS is shipped whenever there is JS source.
if grep -q "^${prefix}/amd/src/" <<<"${list}" && ! grep -q "^${prefix}/amd/build/" <<<"${list}"; then
  echo "::error::${prefix}/amd/build/ missing although amd/src/ is present"
  fail=1
fi

# Allowlist of the plugin surface, failing closed: any top-level entry that is not listed here is an
# unanticipated artefact (a deploy script, a secrets file, a new tool) and fails the job. Adding a new
# top-level file or directory to the plugin means adding it here on purpose.
allow_files=${ALLOW_FILES:-"version.php settings.php lib.php locallib.php ajax.php styles.css README.md CHANGELOG.md LICENSE thirdpartylibs.xml readme_moodle.txt"}
allow_dirs=${ALLOW_DIRS:-"classes db lang tests amd templates pix backup"}
unexpected=""
while IFS= read -r entry; do
  rel="${entry#"${prefix}"/}"
  [ -z "${rel}" ] || [ "${entry}" = "${prefix}/" ] && continue
  top="${rel%%/*}"
  if [ "${top}" = "${rel}" ]; then
    # A top-level name without a slash: must be an allowed file.
    case " ${allow_files} " in *" ${top} "*) continue ;; esac
  else
    case " ${allow_dirs} " in *" ${top} "*) continue ;; esac
  fi
  unexpected="${unexpected}${entry}"$'\n'
done <<<"${list}"
if [ -n "${unexpected}" ]; then
  echo "::error::Entries outside the plugin allowlist (fail closed):"
  printf '%s' "${unexpected}"
  fail=1
fi

# Forbidden entries (second layer, also catches artefacts nested inside allowed directories): development
# and tooling directories, local notes and .env files. It is not a secret scanner.
forbidden="$(grep -E '(^|/)(\.s2s|\.claude|\.github|\.superpowers|docker[^/]*|n8n|spike)/|(^|/)CTX-MEMORY\.md$|(^|/)\.env' <<<"${list}" || true)"
if [ -n "${forbidden}" ]; then
  echo "::error::Forbidden paths in the zip:"
  echo "${forbidden}"
  fail=1
fi

# The Moodle LMS provider (provider/) is a separate Python distribution released as a wheel: none of
# it, and no Python file or wheel from anywhere else, belongs in the plugin zip.
python_entries="$(grep -E "^${prefix}/provider/|\.(py|pyc|whl)$" <<<"${list}" || true)"
if [ -n "${python_entries}" ]; then
  echo "::error::Provider or Python entries in the zip (provider/ must be export-ignore):"
  echo "${python_entries}"
  fail=1
fi

if [ "${fail}" -ne 0 ]; then
  exit 1
fi
echo "Release zip content OK ($(wc -l <<<"${list}") entries)"
