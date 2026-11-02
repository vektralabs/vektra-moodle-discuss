#!/usr/bin/env bash
# Assert that a release tag matches $plugin->release in version.php.
# Usage: assert-release-tag.sh <tag> [path/to/version.php]
#   tag v0.1.0 is valid only when version.php says release = '0.1.0'.
set -euo pipefail

tag="${1:?usage: assert-release-tag.sh <tag> [version.php]}"
versionfile="${2:-version.php}"

release="$(sed -n "s/^\\\$plugin->release[[:space:]]*=[[:space:]]*'\\([^']*\\)'.*/\\1/p" "${versionfile}" | head -1)"
if [ -z "${release}" ]; then
  echo "::error::cannot read \$plugin->release from ${versionfile}"
  exit 1
fi
if [ "${tag}" != "v${release}" ]; then
  echo "::error::tag '${tag}' does not match \$plugin->release '${release}' in ${versionfile} (expected 'v${release}')"
  exit 1
fi
echo "Tag ${tag} matches release ${release}"
