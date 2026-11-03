# Implementation Plan: local_vektradiscuss foundation: repo layout, scaffold, assess HTTP client, settings, capabilities, CI and release packaging

**ID**: 20261101-154950-discuss-foundation
**Status**: completed
**Branch**: N/A
**Created**: 2026-11-01T14:49:50Z
**Updated**: 2026-11-01T20:58:19Z
**Approved**: 2026-11-01 (coordinator)

## Traceability

**Source**: COMP-001, ARCH-065, ARCH-067
**Source Type**: architecture

## References

### Requirements
- REQ-067: Server-authoritative discussion lifecycle per (student, activity) @../vektra-stack/.s2s/requirements.md
- REQ-079: Site admin setup check for the dedicated service account (key separation precondition) @../vektra-stack/.s2s/requirements.md
- REQ-084: Moodle 'login as' sessions cannot start or access a discussion (capability and client groundwork) @../vektra-stack/.s2s/requirements.md
- NFR-014: NFR-SEC: attempt isolation (narrow, site-bound keys; never the admin key) @../vektra-stack/.s2s/requirements.md

### Architecture
- COMP-001: local_vektradiscuss (Moodle local plugin) @../vektra-stack/.s2s/architecture.md
- ARCH-065: Vektra-authoritative split with a thin, non-authoritative Moodle local_ plugin @../vektra-stack/.s2s/architecture.md
- ARCH-067: Discussion token contract: separate key and aud, site-bound narrow scopes, positive login-as, minimum plugin_version @../vektra-stack/.s2s/architecture.md
- INT-001: Assess REST API (plugin and iframe to Vektra) @../vektra-stack/.s2s/architecture.md

### Decisions
- ADR-0027: Assessment discussion as a top-level vektra_assess module with a Vektra-authoritative split and a thin Moodle local plugin @../vektra-stack/.s2s/decisions/ADR-0027-assessment-discussion-as-a-top-level-vektra-assess.md
- ADR-0030: Discussion token contract and UI embedding @../vektra-stack/.s2s/decisions/ADR-0030-discussion-token-contract-and-ui-embedding-separat.md
- New (this plan, task A1): ADR-0001 local_vektradiscuss repository layout @.s2s/decisions/ADR-0001-local-vektradiscuss-repo-layout.md

### Dependencies
- 20261101-154950-assess-moodle-ws (vektra-stack): provides the working Moodle 4.3 and 5.2 docker dev envs and fixture seeding that tasks F1-F3 mount the plugin into. Tasks A-E do not need it and can start immediately.
- 20261101-154950-assess-foundations (vektra-stack, soft): defines the assess:mint / assess:teacher non-inheriting scopes and the ERR-ASSESS-* envelope. The client in this plan is testable with mocked responses before that lands; live smoke (F3) needs it.

## Overview

Lays the ground for the new Moodle local plugin `local_vektradiscuss`, the thin, non-authoritative
Moodle side of the assessment discussion feature (ARCH-065). Nothing student- or teacher-facing
ships here; P2 (20261101-154950-discuss-student) and P3 (20261101-154950-discuss-teacher-privacy)
build on it.

Delivered:
1. A recorded decision on where the plugin lives (today the vektra-moodle repo root IS
   `block_vektra`, and CI, release zip and the docker bind mount all assume one plugin at root).
2. The plugin scaffold: `version.php` (dependency on `block_vektra`, supported [403, 502]),
   capabilities in the course-module context, en/it language packs.
3. An assess HTTP client able to call any `/api/v1/assess/*` endpoint with a narrow key,
   never-throwing for page paths, redacting secrets, parsing the Vektra error envelope.
4. Plugin settings: reuse of `block_vektra/apiurl` and `publicurl`, and two separate secrets for
   `assess:mint` and `assess:teacher` keys; the block's admin key is never used.
5. CI: moodle-plugin-ci matrix 4.3/PHP 8.2 and 5.2/PHP 8.4 with phpunit, mustache and phpdoc on
   top of the existing validate/savepoints/behat, plus a clean release zip.
6. A small separate block_vektra hygiene task: the existing release zip leaks dev artefacts.

## Design Notes

**Repo layout: DECIDED 2026-11-01 (coordinator): Option A.** `vektralabs/vektra-moodle-discuss`
exists (public, GPL-3.0, `main` + `develop`) and is the home of the plugin AND of these plans
(`.s2s/plans/`). Recorded in ../vektra-stack/.s2s/decisions/ADR-0028 (amendment "Moodle-side
deployment unit"). The comparison below is kept for the record:

| | Option A: new repo `vektralabs/vektra-moodle-discuss` (root = local_vektradiscuss) | Option B: restructure vektra-moodle into a multi-plugin repo (`blocks/vektra/`, `local/vektradiscuss/`, shared `docker/`, `n8n/`) |
|---|---|---|
| moodle-plugin-ci | unchanged layout; `moodle-plugin-ci add-plugin vektralabs/vektra-moodle` pulls the dependency | `--plugin ./blocks/vektra` and `./local/vektradiscuss` as two installs; validate/phpcs per dir |
| Release | one zip per repo, trivially clean | release.yml must build two zips and two tags/version streams |
| Existing installs | untouched (block stays at root, git-clone installs keep working) | every git-clone deployment of `blocks/vektra` breaks; history moved via `git mv` |
| Moodle Plugin Directory | one plugin per repo, as the directory expects | not publishable from one repo without a split later |
| Coordinated changes | two PRs when both plugins change (rare: the discuss plugin reads only two block settings) | one PR |
| Dev env | docker compose mounts two repos | one mount, two subdirs |

Recommendation: **Option A**. The coupling between the two plugins is two config reads; the
block is in production on two sites (mooc, elearning) with git-based deploys; Option B breaks those
deploys to save a cross-repo checkout in CI. Option A also keeps the exam-evidence plugin on its own
release cadence (ARCH-067 requires a minimum `plugin_version` at mint; coupling it to block widget
releases would make every block release an exam-plugin release). Plans P1-P3 live in
`vektra-moodle-discuss/.s2s/plans/`. All paths below are relative to the plugin root.

**HTTP client: own class, not a block_vektra refactor.** `block_vektra\vektra_client` has
hard-coded paths, no generic `request()`, and `redact()` / `parse_error_envelope()` /
`extract_error_object()` are private. Refactoring it into a public reusable client forces a
block_vektra release and a raised `dependencies` version before any discuss work, and puts the
exam path's error semantics (streaming, structured field errors for teacher forms) in a widget
plugin. Decision: `\local_vektradiscuss\local\http\assess_client` copies the three helpers (with a
comment naming the source and a BACKLOG item to dedupe once both are stable). This deviates from
COMP-001 "depends on block_vektra's client": the `$plugin->dependencies` entry stays, for the
apiurl/publicurl settings reuse only. Recorded in ADR-0001 as a second decision.

**Client contract**:
- `request(string $method, string $path, array $query = [], ?array $body = null, string $keyrole = 'mint', array $opts = []): assess_response`.
  `$path` must start with `/api/v1/assess/` (asserted; no arbitrary URLs). `$keyrole` in
  {`mint`, `teacher`} selects the secret; P3 adds `privacy_export` and `privacy_erase`. A per-call
  `bearer` option replaces the key with a token (download tokens, P2/P3).
- `assess_response`: `status`, `ok` (2xx), decoded `json`, `error_code` / `message` /
  `details` from the REQ-010 envelope (legacy shape tolerated), `request_id`. Never throws on
  transport or HTTP errors: transport failure = status 0, `error_code = 'transport'`.
- `stream(string $path, string $bearer, callable $onheaders, callable $onchunk)`: CURLOPT
  write/header functions, used by the download proxies (P2/P3). Present here so the transport
  config (timeouts, no redirects, TLS verify) is defined once.
- Every request: `CURLOPT_FOLLOWLOCATION` off (redirects could leak the bearer, B.1), TLS verify
  on, timeout 5 s / connect 3 s default, overridable per call (forms pass 2 s), header
  `X-Vektra-Plugin-Version: <$plugin->release>` (ARCH-067 minimum plugin_version), JSON body.
- `debugging()` output and any log line pass through `redact()` (Authorization header, `token`,
  `solution`, `rubric` fields).

**Settings** (`settings.php`, `admin_settingpage` under `localplugins`):
- `apiurl` / `publicurl`: NOT duplicated; read via `get_config('block_vektra', ...)` and shown as
  read-only info with a link to the block settings.
- `uiurl` (optional): origin serving `/api/v1/assess/ui` (ARCH-076 distinct origin). Empty means
  `block_vektra/publicurl`. Must be `https://` unless `$CFG->debugdeveloper`.
- `siteid`: the site identifier issued by the Vektra operator with the keys (used in
  `/sites/{site}/setup-check`, P3).
- `mintkey`, `teacherkey`: `admin_setting_configpasswordunmask`. A custom
  `admin_setting` subclass rejects a value equal to `block_vektra/apikey` or to the other key
  ("never the admin key; never one key for both roles"). Vektra enforces this anyway: the admin
  superscope does not satisfy the non-inheriting assess:* scopes, so an admin key fails 403 rather
  than working by accident.
- `enabled` (site kill switch, default 0). Disabled = hooks render nothing, endpoints refuse.

**Capabilities** (`db/access.php`, all `CONTEXT_MODULE`):

| Capability | Type | Archetypes | Used by |
|---|---|---|---|
| `local/vektradiscuss:start` | write | student | P2 mint/start |
| `local/vektradiscuss:viewowntranscript` | read | student | P2 own transcript |
| `local/vektradiscuss:viewtranscripts` | read | teacher, editingteacher, manager | P3 list + download |
| `local/vektradiscuss:manage` | write | editingteacher, manager (RISK_PERSONAL) | P3 reset, terminate, extra time, opening date, resend |
| `local/vektradiscuss:configure` | write | editingteacher, manager | P3 settings section |
| `local/vektradiscuss:setupcheck` | read | manager (CONTEXT_SYSTEM) | P3 setup-check page |

Moodle capabilities are a first filter only; Vektra re-verifies graders through fresh gate_inputs
(INT-001) and decides.

## Tasks

### A. Layout decision and scaffold
- [x] A1. Repo layout is decided and the repo exists (2026-11-01). Remaining: write `.s2s/decisions/ADR-0001-local-vektradiscuss-repo-layout.md` (MADR) in vektra-moodle-discuss recording the client decision from Design Notes and pointing to the ADR-0028 amendment for layout; copy CODEOWNERS and PR template from vektra-moodle `.github/`; add `.gitattributes` export-ignore for `.s2s/`, `.claude/`, `CTX-MEMORY.md` from the first commit.
- [x] A2. `version.php`: `component = 'local_vektradiscuss'`, `version = 2026110100`, `requires = 2023100900` (4.3), `supported = [403, 502]`, `maturity = MATURITY_ALPHA`, `release = '0.1.0'`, `dependencies = ['block_vektra' => 2026103001]`. GPL header, `@package local_vektradiscuss`, `@copyright 2026 VektraLabs`, MOODLE_INTERNAL guard (repo conventions).
- [x] A3. `db/access.php` with the six capabilities in Design Notes; `lang/en/local_vektradiscuss.php` and `lang/it/local_vektradiscuss.php` with `pluginname`, capability strings, settings strings; `README.md` (install prerequisites: block_vektra, keys from operator, link to this repo's plans); `CHANGELOG.md` (Keep a Changelog, `[Unreleased]`).
- [x] A4. `classes/privacy/provider.php` placeholder implementing `\core_privacy\local\metadata\provider` with only `get_metadata()` declaring `add_external_location_link('vektra_assess', ...)` (fields: userid, courseid, cmid, attempt and submission content, conversation turns, transcript). Not a null_provider: data does leave the site. Export/delete come in P3; until then `local/vektradiscuss` stays MATURITY_ALPHA and `enabled` defaults 0, so no data flows.

> Note (A, 2026-11-02): `spike/` (the OQ-025 spike, already in the repo) is added to `.gitattributes`
> export-ignore as well, otherwise it would ship in the release zip. Settings and privacy strings
> were written with A3 so the later groups only use them. The A1 ADR records a third consequence
> found in group B: Moodle's `\curl::mock_response()` always answers HTTP 200, so the client sends
> through a small transport interface.

### B. Assess HTTP client
- [x] B1. `classes/local/http/assess_response.php` (immutable DTO) and `classes/local/http/assess_client.php` with `request()` per Design Notes, using Moodle `\curl`; copy `redact()`, `parse_error_envelope()`, `extract_error_object()` from `block_vektra\vektra_client` with a source comment.
- [x] B2. Enforce in `assess_client`: path prefix `/api/v1/assess/`, no redirects, TLS verify, `X-Vektra-Plugin-Version`, key selection by role, refusal (no HTTP call, `error_code = 'not_configured'`) when the role's key is empty or equals `block_vektra/apikey`.
- [x] B3. `stream()` with header/write callbacks; status line captured before the first body byte is forwarded; max-bytes guard (setting-free constant, 20 MB) aborting with `error_code = 'too_large'`.
- [x] B4. PHPUnit `tests/local/http/assess_client_test.php` using `\curl::mock_response()`: 2xx JSON, REQ-010 envelope 403/409/422/429 (error_code and details extracted), legacy envelope, transport failure (status 0, no exception), non-assess path rejected, empty key = 0 HTTP calls (assert mock queue untouched), admin-key value refused, Authorization absent from `debugging()` output (assert with `assertDebuggingCalled` message content).

> Note (B, 2026-11-02): Moodle's `\curl::mock_response()` answers every mocked call with HTTP 200 and
> the queued body, so it cannot simulate 302, 403/409/422/429 or a transport failure (the plan's B4
> assumed it could). Decision: `assess_client` sends through a small `transport` interface (default
> `curl_transport` on `\curl`, so Moodle's curl security helper stays in force). Unit tests use a
> scripted transport (`tests/fixtures/scripted_transport.php`) whose unconsumed queue proves "0 HTTP
> calls"; `tests/local/http/assess_client_http_test.php` proves the default transport over a real
> socket against a PHP built-in server playing the assess backend (redirect, error envelope,
> streaming, byte limit, connection failure). One test uses `\curl::mock_response()` to assert the
> transport options (no redirects, TLS verify). The client also refuses (not_configured) a key equal
> to the other role's key and a non-https API URL outside `$CFG->debugdeveloper`, and returns
> error_code `redirect_refused` on a 3xx. PHPUnit 11 (Moodle 5.2) reports 5 "metadata in doc-comments"
> deprecations: annotations are kept because Moodle 4.3 runs PHPUnit 9, which ignores attributes.

### C. Settings
- [x] C1. `settings.php` with `enabled`, `uiurl`, `siteid`, `mintkey`, `teacherkey`, read-only display of block_vektra apiurl/publicurl; `classes/admin/setting_assess_key.php` rejecting the admin-key value and duplicate keys; `classes/admin/setting_uiurl.php` (https, origin only, no path).
- [x] C2. `classes/local/config.php`: typed accessors (`api_url()`, `ui_origin()`, `key(string $role)`, `is_enabled()`), single place that reads `block_vektra` config. PHPUnit for fallbacks and refusals.

> Note (C, 2026-11-02): `classes/local/config.php` landed with group B because the client needs it; its tests
> (`tests/local/config_test.php`) and the settings classes and tests land here. `siteid` is stored as
> PARAM_ALPHANUMEXT (letters, digits, `_`, `-`); revisit if Vektra issues site ids with other characters
> (Integration Notes). The duplicate-key check compares against the other key as submitted in the same
> form (`s_local_vektradiscuss_<name>`), else the stored one, so two keys cannot be saved as equal in one go.
> The Behat feature (`tests/behat/settings.feature`) could not be run locally (no browser in the dev
> containers); it runs in CI (group D).

### D. CI
- [x] D1. `.github/workflows/moodle-plugin-ci.yml` (copied from vektra-moodle, same pinned action SHAs, Postgres 16, matrix MOODLE_403_STABLE/8.2 and MOODLE_502_STABLE/8.4): add `moodle-plugin-ci add-plugin vektralabs/vektra-moodle` (pinned to the tag carrying the required block version) before `install`; steps phplint, phpcs (`--max-warnings 0`), phpdoc, validate, savepoints, mustache, grunt (`--max-lint-warnings 0`, checks committed `amd/build`), phpunit (`--fail-on-warning`), behat `--profile chrome`.
- [x] D2. Guard against empty suites: a CI step fails if `phpunit` reports 0 tests or `behat` reports 0 scenarios for this component (moodle-plugin-ci passes on "no tests found"). Seed with one Behat smoke `tests/behat/settings.feature` (admin opens settings, admin-key value refused with the error string).
- [x] D3. `.github/workflows/ci.yml`: php -l on 8.2/8.3/8.4 and moodle-cs phpcs, as in vektra-moodle.

> Note (D, 2026-11-02): `block_vektra` 2026103001 (release 0.6.2) is not tagged yet (latest tag v0.6.0), so
> `add-plugin` pins `--branch develop` through `BLOCK_VEKTRA_REF` in the workflow; switch it to the tag
> once released (Integration Notes: the release must contain G1). `.moodle-plugin-ci.yml` excludes `spike/`
> from the checks. The empty-suite guards grep the runner output: PHPUnit `OK (N tests` or `Tests: N,` with
> N > 0 (PHPUnit 11 prints the latter when it reports deprecations) and Behat `N scenarios (` with N > 0.
> Not verifiable here: the workflows themselves run only on GitHub; locally I ran phpcs (moodle-cs, 0 errors
> 0 warnings), phplint, validate (moodle-plugin-ci) and the PHPUnit suite on both Moodle versions. phpdoc
> (moodlecheck needs an installed Moodle plugin), savepoints, mustache, grunt and Behat were not run locally.

### E. Release packaging
- [x] E1. `.gitattributes` with `export-ignore` for `.github/`, `.s2s/`, `.claude/`, `docker*/`, `CTX-MEMORY.md`, `*.local.md`, `.superpowers/`; `release.yml` builds the zip with `git archive --prefix=vektradiscuss/ HEAD` (tracked files only, so untracked `.env` files cannot leak), on tag `v*`.
- [x] E2. Zip content assertion in `ci.yml` (`release-zip` job): `vektradiscuss/version.php` present; `unzip -l` fails the job if any path matches `/(\.s2s|\.claude|\.github|docker|n8n)/|CTX-MEMORY\.md|\.env`; `tests/` and `amd/build/` present (Moodle ships tests).

> Note (E, 2026-11-02): E1 landed in group A (`.gitattributes`); `release.yml` and the `release-zip` job now
> share `.github/scripts/assert-release-zip.sh`, run on the `git archive` zip. The `amd/build/` presence check
> is conditional on `amd/src/` existing (there is no JavaScript yet); the plugin ships `tests/`. Verified: the
> real zip (40 entries) passes, and a hand-made zip with `.s2s/`, `docker/`, `.env` and `CTX-MEMORY.md` fails
> with exit 1. `.moodle-plugin-ci.yml` is export-ignored too.

### F. Dev environment and smoke (needs 20261101-154950-assess-moodle-ws)
- [x] F1. Add a compose override (or document the one plan 4 provides) that bind-mounts this repo read-only at `local/vektradiscuss` next to block_vektra at `blocks/vektra` in the 4.3 and 5.2 envs (5.2 uses the `public/` docroot: mount under `public/local/vektradiscuss`).
- [x] F2. Install on both envs via CLI upgrade; record `admin/cli/upgrade.php --non-interactive` output and `SELECT * FROM mdl_config_plugins WHERE plugin='local_vektradiscuss' AND name='version'` showing 2026110100 on each.
- [ ] F3. Live smoke (when plan 1 has landed): CLI script `cli/smoke.php` calling `GET /health/assess` and an assess endpoint with the mint key; assert the admin key returns 403 ERR-AUTH-* on an assess:mint endpoint (non-inheriting scope proven from the Moodle side). Record raw status codes.

> Note (F, 2026-11-02): F1 is `docker/compose.vektradiscuss.yml` (override, validated with `docker compose config`
> for 4.3 and 5.2; mount follows `MOODLE_DOCROOT`, so `public/local/vektradiscuss` on 5.2) plus `docker/README.md`.
> The running containers were NOT recreated: the plugin was copied in (`docker cp`/tar) and installed with
> `admin/cli/upgrade.php`. Evidence on both Moodle 4.3.11+ and 5.2.4+: dependency failure
> ("Dependencies check failed for local_vektradiscuss", exit 1, 0 rows in config_plugins) with
> `block_vektra` swapped for an absent plugin in a temporary copy of version.php; install of 2026110099 then
> upgrade to 2026110100 (`SELECT ... name='version'` = 2026110100 on each); admin tree location
> "Site administration > Local plugins > Vektra assessment discussion", `enabled` = 0 by default, 6
> capabilities (5 in CONTEXT_MODULE, setupcheck in CONTEXT_SYSTEM) and 10 role_capabilities rows;
> real web form: the block admin key (also with surrounding spaces), a key shared by both roles and an `http`
> UI origin are refused with their messages; clean `uninstall_plugins.php --run` (0 config rows, 0
> capabilities, 0 role_capabilities, no tables). The plugin was then reinstalled (version 2026110100).
> F3: `cli/smoke.php` exists (keys on the command line, raw statuses, exit 0/1/2). Against a local fake backend
> it records 200 (health), 200 (mint key), 403 ERR-AUTH-003 (admin key) and 202 (teacher setup-check) on both
> versions. Against the live vektra-stack dev instance (`:18000`, assess-foundations image: assess scopes
> migrated, but no `/api/v1/assess/*` route mounted, `/health/assess` needs a valid key) every call is
> 401/404, so the 403-versus-2xx pair on a real `assess:mint` endpoint is BLOCKED (no such endpoint exists
> before plan session-lifecycle; the setup-check endpoint is not in that image). F3 stays unticked.
> The plugin cannot call `/health/assess` through `assess_client` (outside `/api/v1/assess/`); the smoke
> script does it with a raw `\curl`.

### G. block_vektra hygiene (separate small PR in vektra-moodle, flagged)
- [x] G1. vektra-moodle: delete the stray root file `release` (stale 5.1-only `version.php` copy, version 2026071801); replace the rsync in `release.yml` and the `release-zip` job in `ci.yml` with `git archive` + `.gitattributes` export-ignore for `docker/`, `docker-moodle43/`, `n8n/`, `.s2s/`, `.claude/`, `CTX-MEMORY.md`, `CONTRIBUTING.md`; add the same forbidden-path assertion as E2. CHANGELOG `[Unreleased]` entry under Fixed. Verify by listing the built zip: 0 entries under the excluded paths (today it ships n8n instance configs for both production sites).

> Note (G, 2026-11-02): done in vektra-moodle on branch `fix/release-zip-docker` (from `develop`, commit e45c63b,
> not pushed, built in a temporary git worktree that was removed): `.gitattributes` export-ignore, `git archive`
> in `release.yml` and in the `release-zip` job of `ci.yml`, the same `assert-release-zip.sh`, and a CHANGELOG
> Fixed entry. Evidence: the old rsync zip of `develop` lists 29 entries under `.s2s/`, `.claude/`, `docker/`,
> `n8n/` (incl. `n8n/instances/*.json`) and `CONTRIBUTING.md`; the new zip has 0 of them (24 entries, `tests/`
> and `version.php` present). The stray root file `release` is not tracked on `develop`: it exists only as an
> untracked file in the developer's working tree, where the old rsync zip would have shipped it. Nothing to
> delete in git; the local file was left alone (the main working tree was not touched). Open: tag a block
> release that contains this fix, then pin `BLOCK_VEKTRA_REF` and the `dependencies` version to it.

> Note (code review, 2026-11-02): 10 findings fixed, each with a test that fails on the old code (verified by
> running the new tests against the pre-fix sources: 12 failures and 1 error). (1) a body that cannot be JSON
> encoded returns `invalid_body` with no call (JSON_THROW_ON_ERROR). (2) `redact()` no longer uses nested
> alternation: possessive patterns, every `preg_replace` wrapped so a PCRE failure yields a placeholder, never
> null; tested with a 280 KB value, through the client too. (3) redaction is structural: the embedded JSON is
> decoded, sensitive keys (token, solution, rubric, authorization, apikey, api_key, secret, password) are
> replaced at any depth and for any scalar type; unparseable text that mentions one is dropped entirely. (4)
> `prepare()` refuses with `disabled` before anything else (request and stream); `explicit_config` carries the
> switch; `cli/smoke.php` reads the site value and exits 2 unless `--ignore-disabled`. (5) the https rule no
> longer looks at `$CFG->debugdeveloper`: `config::allow_insecure()` is false for the site and true only through
> `explicit_config` (smoke `--allow-local`); `setting_uiurl` is https-only. (6) `ui_origin()` returns empty for
> a non-https origin from any source. (7) a body on GET is a coding_exception in the client and in
> `curl_transport`. (8) `viewtranscripts` RISK_PERSONAL, `setupcheck` RISK_CONFIG (`manage` unchanged), new
> `tests/capabilities_test.php`; version bumped to 2026110101 so installs pick it up (the dev containers were
> upgraded: riskbitmask 8, 8, 2 in `mdl_capabilities`). (9) `checkout-block-vektra.sh` (strict mode refuses a
> branch on main, tags and PRs to main; lenient logs the resolved SHA; fails when the block version is below the
> required one; the block goes in through `--extra-plugins`), `assert-release-tag.sh` in `release.yml` with a
> self-test run in `ci.yml`; both scripts were exercised locally (good and bad refs and tags). (10) `prepare()`
> takes `$accept`; the array_filter is gone.
> Deliberately not done: the workflow YAML itself (`--extra-plugins`, the `workflow_dispatch` input) has not
> run on GitHub, and the default ref is still the moving `develop` because 0.6.2 is not tagged (lenient mode
> logs the SHA; main will refuse it until a tag or SHA is set). Existing PHPUnit 11 doc-comment deprecations
> remain (now 9).

## State & Data Lifecycle

| State element | Created by | Updated by | Invalidated/Deleted by |
|---------------|-----------|------------|------------------------|
| `config_plugins` rows `local_vektradiscuss/{enabled,uiurl,siteid,mintkey,teacherkey}` | C1 (admin save) | admin settings page | plugin uninstall (core removes `config_plugins`); key rotation = admin overwrite; no copy elsewhere (no MUC, no `$SESSION`) |
| Capability definitions | A3 (`db/access.php` on install/upgrade) | version bump + upgrade | uninstall |
| Release zip | E1 (tag) | each tag | GitHub release deletion |

The plugin creates no DB tables (`db/install.xml` absent by design: ARCH-065, no state in Moodle).

## Acceptance Criteria

- [x] ADR-0001 exists in vektra-moodle-discuss and records the client decision; layout points to the ADR-0028 amendment (ARCH-065, COMP-001)
- [ ] Install succeeds on Moodle 4.3/PHP 8.2 and 5.2/PHP 8.4 in CI and in the plan-4 docker envs; installing without block_vektra fails with the dependency error (COMP-001)
- [x] With the mint key empty or set to the block admin key, `assess_client` makes 0 HTTP calls (asserted on the mock queue) and returns `not_configured`; the settings page refuses the admin-key value (ARCH-067, NFR-014)
- [ ] Live: the block admin key against an assess:mint endpoint returns 403 (raw status recorded), the mint key returns 2xx (ARCH-067)
- [x] No request follows a redirect: a mocked 302 to another host yields status 302 and 0 further requests (B.1 redirect defect)
- [x] `X-Vektra-Plugin-Version` is present on every request (ARCH-067 minimum plugin_version)
- [ ] CI runs phpunit, behat, mustache, phpdoc on both matrix legs and fails when either suite reports 0 tests
- [x] The discuss release zip and the block_vektra release zip contain 0 entries under `.s2s/`, `.claude/`, `docker*/`, `n8n/`, and no `CTX-MEMORY.md` (zip listing, not workflow status)

> Note (acceptance, 2026-11-02): not ticked, with the reason. Install: verified in the Moodle 4.3 and 5.2 docker
> envs, including the dependency error (F2 note); the CI half needs the first GitHub run. Live 403/2xx pair:
> BLOCKED (F3 note). CI runs phpunit/behat/mustache/phpdoc: workflows written, not yet run on GitHub; the
> 0-test guard was reasoned and its zip twin tested, but not exercised on a runner. The block zip item is
> proven on the unmerged `fix/release-zip-docker` branch.

## Testing Approach

PHPUnit (`advanced_testcase`, `resetAfterTest`) for client, config and settings classes, with
`\curl::mock_response()` queues; assertions on the number of consumed mock responses prove that a
refused call made no request. One Behat smoke for the settings page. Live smoke (F3) against the
real stack in the plan-4 envs, recorded as raw status codes in the PR description.

### Test Infrastructure

- **Required**: moodle-plugin-ci with Postgres 16 and chrome (CI); Moodle 4.3 and 5.2 docker envs with a reachable vektra-stack (F2, F3)
- **Provided by**: D1 (CI); 20261101-154950-assess-moodle-ws (docker envs); 20261101-154950-assess-foundations (live assess scopes for F3)
- **False-pass guard**: D2 fails CI on 0 executed tests or scenarios; F3 is reported BLOCKED in the PR when the stack lacks assess scopes (no 403 vs 2xx pair recorded), never as passed

### NFR Verification

| NFR | Dataset/Load | Tool | Environment | Pass criterion |
|-----|--------------|------|-------------|----------------|
| NFR-014 | block admin key, mint key, empty key | `cli/smoke.php` + PHPUnit mock queue | plan-4 docker env (4.3 and 5.2) + CI | admin key -> 403 on assess:mint endpoint; empty/admin-valued key -> 0 HTTP requests |

## Integration Notes

- Header name `X-Vektra-Plugin-Version` and the minimum-version rejection code must be agreed with
  20261101-154950-assess-foundations / -session-lifecycle (vektra-stack); if they choose a body
  field instead, only B2 changes.
- `siteid` must match how plan 1 binds keys to sites; if the key alone identifies the site, the
  setting becomes informational.
- block_vektra `dependencies` version: pin to the release that contains G1 so the hygiene fix ships
  first.

## Notes

- Resolved 2026-11-01: COMP-001 amended (own client; block_vektra dependency for settings only). Original drift: COMP-001 said the plugin depends on block_vektra's client; that class is not reusable
  (private helpers, hard-coded paths). This plan copies the helpers instead (ADR-0001).
- Drift: block_vektra's `classes/privacy/provider.php` is a null_provider although it sends user ids
  to Vektra; out of scope here, add a BACKLOG item in vektra-moodle.
- Risk: the block_vektra release zip currently ships `n8n/instances/{mooc,elearning}.json`,
  `.claude/` and `.s2s/`; G1 should go out before the next block release regardless of this feature.
- Risk: if Option B is chosen, existing git-clone deploys of `blocks/vektra` on both production
  sites must be migrated in a maintenance window; add that to the ADR consequences.

> Wave W1 close-out (2026-11-02): plan marked completed with three items deliberately left OPEN because they cannot be verified inside wave W1, each with its owner:
> (1) F3 and the criterion "block admin key against an assess:mint endpoint returns 403, the mint key 2xx": no `assess:mint` endpoint exists before plan assess-session-lifecycle (wave W3). `cli/smoke.php` is ready and exits 2 (BLOCKED) on a backend without it; re-run it when that endpoint lands. The equivalent property is already asserted in the backend on a live stack: a key holding `assess:mint` gets 403 ERR-AUTH-003 on the exact-scope setup-check route, and `admin` is refused wherever only `assess:*` is accepted (vektra-stack `vektra-assess/tests/integration/test_setup_check_api.py`, `vektra-shared` auth tests).
> (2) "CI runs phpunit, behat, mustache, phpdoc on both matrix legs and fails on 0 tests" and the CI half of "Install succeeds": GitHub Actions only; the first run of the workflows on the PR is the evidence. Behat, phpdoc and PHP 8.4 were not run locally.
> (3) `BLOCK_VEKTRA_REF` still defaults to `develop` until a block_vektra release containing the zip fix is tagged.

> Note (independent review, 2026-11-03): (a) MEDIUM fixed: `assert_path()` now rejects `%2e`, `%2f` and `%5c` in
> any case, and any `%` that does not start a valid escape; the four reported paths plus upper-case, backslash,
> bare-percent and broken-escape variants are in the `bad_path_provider`, and a `valid_path_provider` proves
> `rawurlencode()` of a uuid, an int, a space and `a:b@c` still passes. Identifiers that legitimately contain a
> slash or dot must therefore be passed as opaque ids by the caller, never as path fragments. (b) LOW decided:
> `cli/smoke.php` is a dev/staging tool, so `cli/` is `export-ignore`d. `assert-release-zip.sh` now has an
> allowlist of top-level files and directories (version.php, settings.php, lib.php, locallib.php, styles.css,
> README.md, CHANGELOG.md, LICENSE, thirdpartylibs.xml, readme_moodle.txt; classes, db, lang, tests, amd,
> templates, pix, backup) and fails closed on anything else; a zip with a planted `deploy.sh` and `secrets.yml`
> exits 1, as does one with `cli/` or `x.php`, and the real zip exits 0. A new top-level entry must be added to
> the allowlist on purpose. The workflows call the scripts with `bash ./.github/scripts/...` instead of relying on
> the exec bit. Not addressed: the reviewer's open items (capability enforcement, attempt ownership, server-side
> `%2f` decoding) belong to later waves or to the Vektra repo.
