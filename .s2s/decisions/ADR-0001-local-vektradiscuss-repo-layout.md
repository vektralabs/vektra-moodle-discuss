# ADR-0001: local_vektradiscuss repository layout and HTTP client

**Status**: accepted
**Date**: 2026-11-01
**Context**: plan `20261101-154950-discuss-foundation` (task A1); covers COMP-001, ARCH-065, ARCH-067

## Decision 1: the plugin lives in its own repository (Option A)

`vektralabs/vektra-moodle-discuss` (public, GPL-3.0, `main` + `develop`) is the home of the
plugin `local_vektradiscuss`; its root is the plugin root, and the implementation plans live in
`.s2s/plans/`. The plugin is NOT added to `vektra-moodle`, whose root is `block_vektra`.

The decision and its rationale are recorded in vektra-stack, ADR-0028, amendment 2026-11-01
"Moodle-side deployment unit" (coordinator decision). This ADR points there instead of
repeating it.

### Why not a multi-plugin `vektra-moodle` (Option B)

- Every git-clone deployment of `block_vektra` (two production sites) would break.
- moodle-plugin-ci, the release zip and the docker bind mount all assume one plugin per root.
- The Moodle Plugin Directory expects one plugin per repository.
- The coupling between the plugins is two config reads (`apiurl`, `publicurl`); ARCH-067's
  minimum `plugin_version` check, not co-releasing, enforces compatibility with Vektra.

### Consequences

- Coordinated changes across both plugins are two PRs (rare).
- CI of this repo installs `block_vektra` first (`moodle-plugin-ci add-plugin`).
- `docker/` and `n8n/` never belong here; the release zip is built with `git archive` and
  `.gitattributes` `export-ignore`, and asserted in CI.

## Decision 2: own HTTP client, not a refactor of `block_vektra\vektra_client`

`block_vektra\vektra_client` has hard-coded paths and no generic `request()`, and its
`redact()`, `parse_error_envelope()` and `extract_error_object()` helpers are private.
Making it public and reusable would force a block release (and a raised `dependencies`
version) before any discuss work, and would put the exam path's semantics (streaming,
structured field errors for teacher forms) inside a widget plugin.

`\local_vektradiscuss\local\http\assess_client` therefore copies the three helpers, with a
source comment, and talks only to `/api/v1/assess/*`. This deviates from the original wording
of COMP-001 ("depends on block_vektra's client"), which was amended: the
`$plugin->dependencies` entry on `block_vektra` stays, for the `apiurl` / `publicurl`
settings reuse only.

### Consequences

- The three helpers exist twice until both plugins are stable (backlog item: dedupe).
- A fix to error-envelope parsing must be applied in both repositories until then.
- Moodle's `\curl` mock cannot simulate non-200 statuses or redirects, so the client sends
  through a small transport interface; unit tests use a scripted transport and a real
  loopback HTTP server covers the default `\curl` transport.
