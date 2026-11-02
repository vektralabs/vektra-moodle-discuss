#!/usr/bin/env bash
# Check out the block_vektra dependency at a pinned ref and verify it satisfies version.php.
# Usage: checkout-block-vektra.sh <repo-url-or-path> <ref> <strict|lenient> <dest> [plugin version.php]
#   ref    a v* tag, a 40-hex commit SHA, or (lenient only) a branch name.
#   strict (main, tags, PRs to main): a moving branch is refused, so a release is never tested or built
#          against whatever develop happens to be that day.
#   lenient (develop, PRs to develop): a branch is allowed but the commit it resolved to is printed.
# The block's `$plugin->version` must be >= the version this plugin depends on.
set -euo pipefail

repo="${1:?repo}"; ref="${2:?ref}"; mode="${3:?strict|lenient}"; dest="${4:?dest}"
versionfile="${5:-version.php}"

if [[ "${ref}" =~ ^[0-9a-f]{40}$ ]]; then
  kind=sha
elif [[ "${ref}" =~ ^v[0-9] ]]; then
  kind=tag
else
  kind=branch
fi
if [ "${kind}" = branch ] && [ "${mode}" = strict ]; then
  echo "::error::BLOCK_VEKTRA_REF='${ref}' is a moving branch; pin a v* tag or a commit SHA for main, tags and PRs to main"
  exit 1
fi

rm -rf "${dest}"
mkdir -p "${dest}"
git -C "${dest}" init -q
git -C "${dest}" remote add origin "${repo}"
git -C "${dest}" fetch -q --depth 1 origin "${ref}" || { echo "::error::cannot fetch ${ref} from ${repo}"; exit 1; }
git -C "${dest}" checkout -q FETCH_HEAD
sha="$(git -C "${dest}" rev-parse HEAD)"
echo "block_vektra pinned to ${kind} '${ref}' = commit ${sha}"
rm -rf "${dest}/.git"

required="$(sed -n "s/.*'block_vektra'[[:space:]]*=>[[:space:]]*\\([0-9]*\\).*/\\1/p" "${versionfile}" | head -1)"
have="$(sed -n 's/^\$plugin->version[[:space:]]*=[[:space:]]*\([0-9]*\).*/\1/p' "${dest}/version.php" | head -1)"
if [ -z "${required}" ] || [ -z "${have}" ]; then
  echo "::error::cannot read the block_vektra version (required='${required}', found='${have}')"
  exit 1
fi
if [ "${have}" -lt "${required}" ]; then
  echo "::error::block_vektra at ${ref} has version ${have}, this plugin requires >= ${required}"
  exit 1
fi
echo "block_vektra version ${have} satisfies >= ${required}"
