# Implementation Plan: host the Moodle LMS provider (vektra-lms-moodle) under provider/

**ID**: 20261112-120000-lms-provider-move
**Status**: active
**Branch**: feat/lms-provider
**Created**: 2026-11-12T12:00:00Z
**Updated**: 2026-11-12T12:00:00Z
**Approved**: 2026-11-12 (coordinator, formal phase 2b request)

## Traceability

**Source**: vektra-stack ADR-0033 D6, OD-5, OD-6; vektra-stack plan 20261111-185453-assess-lms-separation, tasks 2b.1 to 2b.3
**Source Type**: decision

## References

### Decisions
- ADR-0033: the assessment vertical behind an `LmsProvider` Protocol; the Moodle provider leaves the platform @../vektra-stack/.s2s/decisions/

### Dependencies
- vektra-stack PR #207 (merged, develop 4896eeb2): the provider staged as its own distribution in `adapters/vektra-lms-moodle/`, settings renamed `VEKTRA_LMS_MOODLE_*`.
- After this plan: vektra-stack 2b.2 / 2b.3 remove `adapters/` and the `lms-moodle` group, and `publish.yml`'s `lms-moodle` variant installs the released wheel by pinned version and sha256.

## Overview

The distribution `vektra-lms-moodle` (package `vektra_lms_moodle`, entry point
`vektra.lms_providers: moodle`, depends on `vektra-shared` only) moves to a top-level `provider/`
tree of this repository, with its 328 recorded web-service responses. It stays out of the plugin
zip, gets its own Python CI against a pinned vektra-stack commit, keeps the weekly live contract
run against fresh Moodle 4.3 and 5.2 sites, and is released as a wheel with its sha256.

## Tasks

- [x] 1. Move `adapters/vektra-lms-moodle/` at vektra-stack 4896eeb2 to `provider/`, byte-identical, in one signed commit naming the source.
- [x] 2. Make the tree stand alone: pytest and coverage configuration in `provider/pyproject.toml` (they lived in the vektra-stack root), no `../../vektra-shared` path source, `test_entry_point.py` reads the stack through `VEKTRA_STACK_ROOT`, script usage and docstrings name `provider/`, version `0.9.0.dev1`, Apache-2.0 license kept.
- [x] 3. Pin the vektra-stack commit in `provider/vektra-stack.ref`.
- [x] 4. Exclude `provider/` from the plugin zip (`.gitattributes`) and assert it (`assert-release-zip.sh`: no `provider/`, no `.py`/`.pyc`/`.whl`), with a self-test (`test-release-zip.sh`) run by the release-zip job.
- [x] 5. `provider.yml`: tests, conformance kit and joint suites in the pinned checkout, `UV_NO_SYNC=1`, coverage gate 95%, JUnit check that the conformance kit and joint suites ran and were not skipped.
- [x] 6. `provider-live.yml`: weekly and manual live run (`-m moodle`, four `VEKTRA_ASSESS_MOODLE*` variables) against the vektra-moodle dev environments, failing when this version's live tests were skipped.
- [x] 7. `provider-release.yml`: tag `provider-v<version>` equal to the pyproject version, tests via `provider.yml`, wheel plus `.sha256` as a GitHub (pre-)release that is never marked latest, tested stack commit in the notes.
- [x] 8. Plugin help text: nothing names `VEKTRA_ASSESS_MOODLE_*`, `VEKTRA_ASSESS_WS_*` or `VEKTRA_ASSESS_BREAKER_*` (checked in this repository and in vektra-moodle); the old names appear only in the provider's own refusal map and its tests.
- [ ] 9. After merge, with the user's approval: tag `provider-v0.9.0.dev1`, publish the wheel, send version and sha256 to vektra-stack.

## Acceptance Criteria

- `provider/` at the move commit is byte-identical to vektra-stack 4896eeb2 `adapters/vektra-lms-moodle/`.
- Locally, against the pinned checkout: 1398 passed, 96 skipped (the live suites without sites, BLOCKED, and 12 recorded shapes with no `warnings[]`), coverage 98.07%; the JUnit check passes.
- Live, against the local dev sites: 43/43 per version with `VEKTRA_ENV=development`.
- The plugin zip has no `provider/` entry; the self-test fails a zip that has one.
- The wheel contains `vektra_lms_moodle` and its dist-info only, with the entry point and the Apache-2.0 license.

## Notes

- **History (decision):** imported as one signed move commit, not with history. A git-filter-repo trial gave 124 commits (30 merges), all clean (denylist 0, gitleaks only the known placeholder token in old commits), but the rewritten commits are unsigned and replaying them signed (`rebase -r`) conflicts on the merges; this repository's rule is signed commits. The history stays public in vektra-stack under `adapters/vektra-lms-moodle/` and, before 97ee56cc / 5a4095c9, under `vektra-assess/` and `scripts/assess_*_ws.py`. Agreed by the coordinator.
- **Version (agreed with the coordinator):** no vektra-stack release carries the LMS Protocol yet (v0.8.0 predates it), so the first wheel is `0.9.0.dev1`, tested against develop 4896eeb2. From vektra-stack v0.9.0 on, wheel `X.Y.Z` is tested against tag `vX.Y.Z`. The pin may move to the commit of vektra-stack #208 (opening logic, Protocol unchanged); not required.
- **Live suites need `VEKTRA_ENV=development`:** without it the setup-check item `ui_origin_distinct` is `MISSING lms_origin_not_https` against the plain-http dev sites (3 failures per version). vektra-stack's `assess-moodle-contract.yml` lacks it too, and has never run on schedule because it is not on vektra-stack's default branch. Reported to the coordinator.
- **License:** the provider keeps Apache-2.0 (vektra-stack's license) in this GPL-3.0 repository; `provider/LICENSE` and the wheel metadata say so.
- **Release is outward-facing:** tagging and publishing the wheel waits for the user's approval.
