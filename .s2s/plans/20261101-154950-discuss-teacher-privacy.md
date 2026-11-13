# Implementation Plan: local_vektradiscuss teacher path and privacy: activity settings section, teacher pages, grader download proxy, setup check, Privacy API provider

**ID**: 20261101-154950-discuss-teacher-privacy
**Status**: active
**Branch**: `feat/discuss-teacher-settings` > `-page` > `-download` > `feat/discuss-privacy` > `feat/discuss-setupcheck` (stacked; one PR from the last)
**Created**: 2026-11-01T14:49:50Z
**Updated**: 2026-11-13T00:30:00Z
**Approved**: 2026-11-01 (coordinator)

## Traceability

**Source**: COMP-001, ARCH-065, ARCH-067
**Source Type**: architecture

## References

### Requirements
- REQ-076: Teacher in-Moodle session list and transcript download @../vektra-stack/.s2s/requirements.md
- REQ-077: Teacher configuration in the activity settings page @../vektra-stack/.s2s/requirements.md
- REQ-078: Per-student extra time (accommodations) @../vektra-stack/.s2s/requirements.md
- REQ-079: Site admin setup check for the dedicated service account @../vektra-stack/.s2s/requirements.md
- REQ-081: Teacher disables discussion, or terminates active sessions @../vektra-stack/.s2s/requirements.md
- REQ-087: Teacher reset: grant a new session #N @../vektra-stack/.s2s/requirements.md
- REQ-089: Privacy configuration (enable refused when unset; surfaced by the form and the setup check) @../vektra-stack/.s2s/requirements.md
- REQ-090: Teacher preview mode (could; not built, see Notes) @../vektra-stack/.s2s/requirements.md
- REQ-091: Manual opening-date fallback when the effective close cannot be read @../vektra-stack/.s2s/requirements.md
- REQ-092: Student notification when a reset is granted (teacher list shows "studente non avvisato via email") @../vektra-stack/.s2s/requirements.md
- REQ-093: Student transcript access after loss of course access (b: Privacy API export) @../vektra-stack/.s2s/requirements.md
- BR-013: Teacher input validation @../vektra-stack/.s2s/requirements.md
- BR-016: Transcript format, naming and safe serving @../vektra-stack/.s2s/requirements.md
- NFR-014: NFR-SEC: attempt isolation (cross-course and cross-user negative cases from the teacher and privacy side) @../vektra-stack/.s2s/requirements.md

### Architecture
- COMP-001: local_vektradiscuss (settings section, teacher pages, streaming download proxy, Privacy provider) @../vektra-stack/.s2s/architecture.md
- ARCH-065: thin plugin; BR-013 validated in Vektra @../vektra-stack/.s2s/architecture.md
- ARCH-067: transcript download token under assess:teacher for graders @../vektra-stack/.s2s/architecture.md
- ARCH-072: RAG for discussions ships locked OFF @../vektra-stack/.s2s/architecture.md
- INT-001: teacher, transcript, privacy and setup-check endpoints @../vektra-stack/.s2s/architecture.md
- OQ-033: privacy erasure vs exam-evidence retention (design default: erasure endpoint) @../vektra-stack/.s2s/architecture.md
- OQ-034: teacher config re-display (design default: write-only) @../vektra-stack/.s2s/architecture.md

### Decisions
- ADR-0027: thin Moodle local plugin @../vektra-stack/.s2s/decisions/ADR-0027-assessment-discussion-as-a-top-level-vektra-assess.md
- ADR-0030: token contract, transcript download token @../vektra-stack/.s2s/decisions/ADR-0030-discussion-token-contract-and-ui-embedding-separat.md
- ADR-0031: envelope encryption; solution sealed to the worker key (basis for write-only) @../vektra-stack/.s2s/decisions/ADR-0031-envelope-encryption-with-live-session-key-hand-off.md
- ADR-0028 amendment (vektra-stack): repo layout @../vektra-stack/.s2s/decisions/ADR-0028-assess-trust-domains-and-deployment-topology-asses.md
- ADR-0001 (vektra-moodle-discuss): repo layout and client @.s2s/decisions/ADR-0001-local-vektradiscuss-repo-layout.md

### Dependencies
- 20261101-154950-discuss-foundation (this repo): scaffold, client, settings, capabilities, CI.
- 20261101-154950-discuss-student (this repo): `transcript_proxy` (reused for graders), Behat Vektra stub.
- 20261101-154950-assess-transcript-delivery (vektra-stack): download token, transcript endpoint, resend.
- 20261101-154950-assess-teacher-ops (vektra-stack): PUT config (BR-013), session list, reset, terminate, extra time, opening date, setup check, RAG locked OFF.
- 20261101-154950-assess-privacy-retention (vektra-stack): privacy export and erasure endpoints, assess:privacy-export / assess:privacy-erase scopes.

## Overview

The teacher and data-protection side of the plugin, all of it a client of Vektra:
1. A "Discussione con l'assistente" section in the quiz and assignment settings form that loads the
   activity config from Vektra and forwards changes with `PUT /activities/{cmid}/config`.
   Validation is Vektra's (BR-013); the form mirrors ranges only for field-level feedback. The
   solution is write-only (OQ-034 default). RAG is shown locked OFF (ARCH-072).
2. A per-activity "Discussioni" teacher page: session list (REQ-076), reset (REQ-087), terminate
   (REQ-081), extra time (REQ-078), manual opening date with attestation (REQ-091), resend,
   grader download through the streaming proxy (BR-016).
3. A site admin "Verifica configurazione" page (REQ-079).
4. A real Privacy API provider: metadata with an external location, export through the Vektra
   privacy-export endpoint, deletion through the erasure endpoint (OQ-033 default). A delete that
   cannot be carried out throws an explicit exception, which Moodle sends to the DPOs; it never returns
   as if it had succeeded.

## Design Notes

**Contract alignment (2026-11-12).** The notes below were written before the vektra-stack teacher and
privacy plans landed (plans 11a/11b, #208, 12a). Where they disagree, this block wins; source of
truth is vektra-stack `docs/reference/api.md` on develop.
- Config `GET/PUT /api/v1/assess/activities/{cmid}/config` (teacher key). GET needs the headers
  `X-Assess-Actor`, `X-Assess-Course`, `X-Assess-Activity-Type` (`quiz`/`assign`); it returns
  `version`, `configured`, `enabled`, `cap_minutes`, `rubric_source`, `show_solution`,
  `rag_enabled` (always false), `available_until`, `solution_state` / `pasted_rubric_state` /
  `transcript_recipients_state` (`impostata` / `non impostata`), `transcript_recipients_count`,
  `opening {at, source, fallback_required}`, `warnings`, `active_sessions`. No text, no addresses.
- There is no ETag: concurrency is `expected_version` in the PUT body (0 for a never-saved
  activity); `409 ERR-ASSESS-023` (`details.current_version`) means reload, nothing saved. Read
  "etag" below as "the version read on render": no PUT without it.
- PUT body: `actor_userid`, `courseid`, `activity_type`, `expected_version` required; every other
  field optional, ABSENT = unchanged. `null` clears only the write-only fields, `cap_minutes`
  and `available_until`; never send `null` for `enabled`, `show_solution`, `rag_enabled`,
  `rubric_source`. `rubric_source`: `none` / `pasted` (+ `lms` for assign). `rag_enabled`: only
  `false`. Write-only: `solution`, `pasted_rubric` (20,000 code points after NFC, control
  characters other than newline and tab removed), `transcript_recipients` (1-3 addresses, each in
  the operator's allowed domains). Field errors: `400 ERR-ASSESS-022` `details.fields[{field, code,
  range?, allowed_domains?, index?, reason?, opening_at?}]`; enable refusals arrive as codes on
  `enabled` (`quiz_without_close_date`, `assignment_without_cutoff`, `team_submission`,
  `marking_guide`, `lms_facts_unreadable`, `privacy_unset`, `setup_check_red`).
  `403 ERR-ASSESS-021`: `editor_check_stale` retry after a few seconds, `not_an_editor` no retry.
  `400 ERR-ASSESS-014` `teacher_gate_hint`: send course and type.
- Recipients replace "graders implicit + free text": enable needs at least one address; the form
  defaults to `$USER->email`, and the allowed domains come back on a field error.
- Opening fallback is its own endpoint, `PUT .../opening-fallback` {actor_userid, courseid,
  activity_type, opening_at, attestation: true, attestation_text_version}, shown only when
  `opening.fallback_required` is true (`gate_fallback` below is gone). A `source` value the plugin
  does not know means "no opening date".
- Teacher operations (sessions list with cursor header, reset, terminate with
  `expected_active_count` and `409 ERR-ASSESS-025`, extra time with `minutes` xor `percent` and
  `confirm_over_100`) all carry `actor_userid`, `courseid`, `activity_type`. The reset reason is
  shown only in the list. No student email on reset (REQ-092 out of v1): the list says so.
- Grader download: `POST /transcripts/{tid}/download-token` with the teacher key and
  `{"actor_userid"}`; resend: `POST /transcripts/{tid}/resend` with `{"actor_userid"}` only.
- Setup check is asynchronous: `POST /sites/{site}/setup-check` -> `202 {report_id}` (one per site
  per 5 minutes, `429 ERR-ASSESS-002`), then `GET .../setup-check/{report_id}` until `status` is
  `done`; items `{name, result, reason_code}`.
- Privacy endpoints: GET `/privacy/subjects/{userid}/transcripts` (export key, 10 per page,
  `X-Assess-Cursor`, items carry `sha256` and `content_base64`), GET
  `/privacy/activities/{cmid}/subjects` (500 per page), DELETE `/privacy/subjects/{userid}` and
  DELETE `/privacy/activities/{cmid}/subjects` (optional `{"userids"}`), erase key. Success is
  `{"outcome": "erased" | "retained", counts}`; `503 ERR-ASSESS-027` `details.reason: pending` is
  retried with the same request (idempotent), `failed` throws. `retained` (exam-evidence mode)
  throws the dedicated exception.
- Pre-session privacy block (`pre_session.privacy {notice_url, notice_version, retention_days,
  legal_basis_label, summary, mailbox_notice}`) is rendered by the student path; recording the
  shown `notice_version` at Start needs a mint field that vektra-stack has not added yet (P3-4,
  ask vektra-stack).

**Form integration matrix**: legacy callbacks in `lib.php`:
`local_vektradiscuss_coursemodule_standard_elements($formwrapper, $mform)`,
`local_vektradiscuss_coursemodule_validation($fromform, $fields)`,
`local_vektradiscuss_coursemodule_edit_post_actions($data, $course)`. Task A1 verifies on 4.3 and
5.2 that these still fire, or whether 5.x provides `\core_course\hook\*` replacements that must be
registered in `db/hooks.php` instead (same two-version pattern as P2). Section rendered only for
`quiz` and `assign`, only with `local/vektradiscuss:configure` and the site kill switch on.

**Load-before-write rule (dormant-capability guard)**: the form calls `GET
/api/v1/assess/activities/{cmid}/config` (teacher key, 2 s timeout) when rendering. If that call
fails, the section shows "Vektra non raggiungibile: impostazioni della discussione non
modificabili ora", fields are frozen, and a hidden `vektradiscuss_etag` stays empty. In
`edit_post_actions`, no PUT is sent unless the etag is present and a field changed, so a Vektra
outage while a teacher edits the quiz title can never reset the discussion config to defaults. The
PUT carries the etag (If-Match) so that two concurrent editors cannot silently overwrite each other.

**New activities**: no cmid exists at validation time, and Vektra reads close dates and review
options from Moodle via WS (authoritative), so it cannot validate a not-yet-saved activity. On the
create form the section shows defaults (enable OFF, mostra soluzione OFF, RAG OFF, end date empty,
REQ-077 AC) and the note "Salva l'attività, poi riapri le impostazioni per abilitare la
discussione"; enable is frozen.

**Validation, two layers**:
- `coursemodule_validation`: local mirror of BR-013 syntactic ranges for field-level errors (cap
  5-120 integer; solution and pasted rubric <= 20,000 characters, with a live counter in the AMD
  module; empty pasted rubric with source "pasted"; end date after the computed opening Vektra
  reported on load). Messages name the range. This mirror is advisory and never relaxes Vektra.
- `edit_post_actions`: the authoritative `PUT`. Vektra's field errors (incl. rules depending on the
  just-saved activity: BR-007 close date or cut-off, team submission, marking guide, privacy unset,
  admin check red) cannot become form field errors at this point; they are shown with
  `\core\notification::error()` naming each field and the range, the Vektra config stays unchanged
  (enable stays OFF), and the teacher is returned to the settings page. Drift flagged in Notes.

**Solution write-only (OQ-034 default)**: GET returns `solution_set: bool`, never text. The form
shows "Soluzione: impostata / non impostata", a "Sostituisci" checkbox revealing an empty textarea,
and "Rimuovi la soluzione". PUT sends `solution` only when replaced (or `null` on remove). The
textarea is never pre-filled, never echoed on a validation error (cleared on redisplay), and the
value is removed from `$data` after the PUT so later callbacks and events do not carry it. The
pasted rubric gets the same write-only treatment: it may be shown to students (BR-011), but the
API seals it to the worker key with the solution (OQ-034 default), so the form shows only
"impostata (N caratteri)".
PARAM_RAW for both (PARAM_TEXT would strip `a<b`-style math); Vektra treats them as untrusted plain
text.

**Show solution toggle**: default OFF, help text with the D3 sentence; a warning notice renders
when ON. **RAG field**: rendered frozen OFF with "Non disponibile in questa versione" (ARCH-072);
never sent as ON. **Recipients** (REQ-082): graders are implicit; the free-text field is sent as is
and Vektra checks the allowlist.

**Opening-date fallback (REQ-091)**: shown only when GET reports `gate_fallback: true`. Fields:
`Apertura discussione` date-time and the attestation checkbox "Confermo che nessuno studente ha
tempo/proroga oltre questa data" with the warning that later extensions cannot be detected. Both
are required together; the attestation is re-ticked on every edit (never pre-ticked). The same
control exists on the teacher page.

**Teacher calls**: all with the `teacher` key and `actor_userid` = `$USER->id` (Vektra re-verifies
the grader relationship through fresh gate_inputs, INT-001). Plugin-side first filter:
`require_login($course, false, $cm)`, `require_capability` (`:viewtranscripts` for reading,
`:manage` for writes), `require_sesskey()` on every POST, refusal under login-as (a teacher using
"login as" acts as the student, not as a teacher). Mandatory reasons are validated non-empty in the
form and by Vektra. Student names are resolved in Moodle from user ids (`fullname()`), never sent
by Vektra. The override holder name in the gate panel is displayed only if the viewer has
`mod/quiz:viewoverrides` / `mod/assign:viewoverrides` (A1 verifies these exist on 4.3 and 5.2;
otherwise the `manageoverrides` capabilities); never the reason.

**Grader download**: `POST /transcripts/{tid}/download-token` with the teacher key and actor ->
`transcript_proxy` from P2 (stream only, attachment, nosniff, CSP sandbox, no-store, Referrer-Policy;
never File API / pluginfile). FAILED transcripts are downloadable (REQ-076).

**Privacy provider** (`classes/privacy/provider.php`): implements `\core_privacy\local\metadata\provider`,
`\core_privacy\local\request\plugin\provider`, `\core_privacy\local\request\core_userlist_provider`.
- Metadata: `add_external_location_link('vektra_assess', [...], 'privacy:metadata:vektra_assess')`
  (user id, course and activity ids, attempt/submission content snapshot, conversation turns,
  transcripts, delivery metadata). No Moodle tables.
- `get_contexts_for_userid`: `GET /privacy/subjects/{userid}/transcripts` (privacy-export key) ->
  cmids -> `context_module`; cmids no longer present -> `context_user` of the subject, so data of
  deleted activities stays exportable.
- `export_user_data`: same endpoint; per context, `writer::with_context($ctx)->export_data()` for
  metadata and `export_custom_file()` for each transcript's bytes. Before writing, the plugin checks
  sha256(bytes) == Vektra-reported hash; mismatch throws.
- `delete_data_for_user` / `delete_data_for_users` / `delete_data_for_all_users_in_context`:
  `DELETE /privacy/subjects/{userid}` (privacy-erase key), then re-query the export endpoint and
  require 0 transcripts for the affected cmids (verify, do not trust the 200). Any failure, missing
  key, or a non-subject-wide request that the endpoint cannot scope (see Notes) throws a
  `moodle_exception` with an explicit message. Moodle's privacy manager catches it and sends it to
  the DPOs; the request still completes (a provider cannot fail it, see D6), and Vektra alerts its
  operator on a failed or long-pending erasure. If the DPO records a retention exemption
  (OQ-020/OQ-033), Vektra answers `outcome: retained` and the plugin throws a dedicated, explained
  exception; it never returns normally without deleting. (Implemented: per activity with the
  person's id whenever the request names activities; subject-wide only through the user context and
  only when no existing activity of the person was left out; verification through the activity's
  subject list and the person's transcript list.)
- Two new settings (`privacyexportkey`, `privacyerasekey`), separate from mint/teacher keys, using
  the foundation's `setting_assess_key` (refuses the admin key and duplicates); `assess_client`
  roles `privacy_export`, `privacy_erase`.

**Setup check page** (`admin/setupcheck.php`, `admin_externalpage` under localplugins,
`local/vektradiscuss:setupcheck`): button "Verifica configurazione" -> `POST
/sites/{siteid}/setup-check`; renders each REQ-079 item by name with OK/MISSING, plus the
privacy-settings items (REQ-089). Result only, no detail beyond the item names. Also shows which
plugin keys are configured (never their values) and a local check that `uiurl` is a distinct HTTPS
origin.

## Tasks

**PR slices (user order, 2026-11-12).** P3-1 settings section (A, plus the opening-fallback form);
P3-2 teacher page: sessions list, reset, terminate, extra time (B1-B3, B5, B6); P3-3 grader
download and resend (B4, plus P2 E3 hash-equal); P3-4 privacy (D, plus pre-session notice
version); P3-5 setup check page, docs, live E2E (C, E).

### A. Settings section in the activity form
- [x] A1. Verify on 4.3 and 5.2 envs: the three `coursemodule_*` callbacks fire for quiz and assign, or which `\core_course\hook\*` replace them; whether `mod/quiz:viewoverrides` and `mod/assign:viewoverrides` exist on both. Record results in the plan Notes before coding A2.
- [x] A2. `lib.php` callbacks (+ `db/hooks.php` if A1 requires) delegating to `classes/local/form/settings_section.php`: GET config on render, frozen/unavailable rendering, create-form defaults, all fields from Design Notes, help strings incl. the pasted-rubric line and the D3 sentence (REQ-077 AC), computed opening condition, end date, "N sessioni attive" with a link to the terminate action, review-options warning from Vektra.
- [x] A3. `amd/src/charcounter.js`: live counter for solution and pasted rubric (20,000), announced politely at thresholds; built into `amd/build`.
- [x] A4. `settings_section::validate()` for `coursemodule_validation` (BR-013 mirror, messages naming ranges) and `settings_section::save()` for `edit_post_actions` (etag rule, PUT with If-Match, solution/rubric only when replaced, unset from `$data` afterwards, Vektra field errors -> notification naming fields).
- [x] A5. PHPUnit `tests/local/form/settings_section_test.php` with mocked curl: GET failure -> save sends 0 requests (mock queue untouched); unchanged fields -> 0 PUT; solution untouched -> PUT body has no `solution` key; replace -> present; remove -> `null`; RAG never true in any PUT body; 20,000 accepted / 20,001 refused locally; cap 4/121 refused, 5/120 accepted; Vektra 422 with field errors -> notification lists each field.
- [x] A6. Behat `tests/behat/teacher_settings.feature` (stub from P2 D1): new quiz shows defaults and frozen enable; edit form shows "impostata" without text in page source; outage -> frozen section and stub received 0 PUT; refused enable (stub returns BR-007 error) -> error notification, stub config unchanged.

### B. Teacher page
- [x] B1. `local_vektradiscuss_extend_settings_navigation()` adding "Discussioni" to quiz/assign module settings for `:viewtranscripts`; `teacher.php?cmid=` with `classes/output/teacher_page.php` renderable + `templates/teacher_sessions.mustache`.
- [x] B2. Session list from `GET /activities/{cmid}/sessions`: per student sessions #1..#N, state and close reason (incl. access_revoked), delivery status incl. bounces and "studente non avvisato via email" (REQ-092), reset count and reasons, gate panel (effective opening, override holder name only with the override-view capability, C7 fallback reason), SHOULD hint for suspended/unenrolled holder when Vektra reports it.
- [x] B3. Actions as POST forms (sesskey, `:manage`): reset with mandatory reason and the continuity opt-in (default OFF, REQ-087(b)); terminate with confirmation stating N and mandatory reason (REQ-081); extra time in minutes or % with the 240-min total stated and >+100% confirmation (BR-013), shown only before start; opening date + attestation (REQ-091); resend (no recipient field exists in the form or request). Vektra refusals (reset on ACTIVE, after end date with pointer to the date field, extra time on ACTIVE/CLOSED, opening date moved earlier) shown as notifications with the localized reason.
- [x] B4. Grader download `teacher_transcript.php` via `transcript_proxy` with the teacher-key download token.
- [x] B5. PHPUnit `tests/local/teacher_actions_test.php`: each action's exact request body (actor present, no recipient parameter on resend, reason present); missing reason -> 0 requests; user without `:manage` -> exception and 0 requests; login-as -> refused, 0 requests; teacher of course B with cmid of course A -> `require_login` failure, 0 requests.
- [x] B6. Behat `tests/behat/teacher_page.feature`: list renders all columns from stub fixtures; reset reason visible in list; override holder name hidden for a non-editing teacher without the capability; terminate confirmation shows N.

### C. Setup check page
- [x] C1. `admin/setupcheck.php` + `settings.php` registration + template rendering REQ-079 items by name, privacy items, key-configured flags (never values).
- [x] C2. PHPUnit/Behat: stub returns one MISSING item -> that item named; transport failure -> generic error, no item shown as OK.

### D. Privacy provider
- [x] D1. Settings `privacyexportkey`, `privacyerasekey`; client roles `privacy_export`, `privacy_erase`; lang strings for metadata.
- [x] D2. Provider metadata + `get_contexts_for_userid` + `get_users_in_context` + `export_user_data` with hash verification per Design Notes.
- [x] D3. Delete methods with post-delete verification and throw-on-anything-else; dedicated exception strings for "key not configured", "Vektra refused", "verification found N remaining", "erasure exempt (DPO)".
- [x] D4. PHPUnit `tests/privacy/provider_test.php` (extends `\core_privacy\tests\provider_testcase`): metadata contains the external location; export writes bytes sha256-equal to the mock; hash mismatch throws; delete with missing key throws; delete with 200 but verification returning 1 transcript throws; delete success makes exactly 2 requests (DELETE + verification GET); exempt -> throws; cross-user: export for user B never includes A's records (mock asserts the requested userid).
- [x] D5. Live (plan-4 env + real stack): create a discussion for a fixture student, unenrol them, run `admin/tool/dataprivacy` export -> zip contains transcripts #1..#N sha256-equal to the in-course copy (REQ-093(b)); run deletion -> Vektra row counts for that subject's sessions, snapshots, turns, transcripts = 0 (SQL counts recorded), export afterwards contains 0 transcripts.
- [x] D6. Live negative: revoke the erase key in Vektra, run a deletion request -> the provider throws an explicit exception, the site's DPOs receive Moodle's failure notification with its text, and Vektra's rows are unchanged (count before/after). Moodle completes the request anyway: `core_privacy\manager::handled_component_class_callback()` catches any Throwable from a provider, notifies the observer (tool_dataprivacy emails the DPOs) and returns null (privacy/classes/manager.php:609 on 4.3.11; same on 5.2). Decided by the coordinator 2026-11-13 (OQ-033): DPO notification on the Moodle side, operator alert on the Vektra side.

### E. Docs and wrap-up
- [x] E1. README: teacher guide (settings fields, write-only solution, RAG off), required keys and scopes table (mint, teacher, privacy-export, privacy-erase; admin key never), privacy section (what leaves Moodle, export/delete behaviour, DPO exemption path); CHANGELOG `[Unreleased]`; raise `maturity` to BETA only after D5/D6 pass.
- [x] E2. (quiz on 4.3; assignments not on F1) Live teacher E2E on 4.3 and 5.2 (quiz + assign): enable, cap change, extra time +50% on 30 min -> Vektra deadline 45 min (REQ-078 AC, read from the session row), terminate with N=2 -> 2 CLOSED(terminated_by_teacher) rows and 2 transcripts, reset -> session #2 row, grader download sha256-equal to the student copy (P2 E3).

## State & Data Lifecycle

| State element | Created by | Updated by | Invalidated/Deleted by |
|---------------|-----------|------------|------------------------|
| Form-held config etag (`vektradiscuss_etag` hidden field) | A2 on GET | each form render | end of form submission; no persistence |
| Solution / pasted rubric plaintext in PHP request memory | A4 (form submit) | never | unset from `$data` right after PUT; never written to Moodle DB, logs, events or session |
| Download token (grader) | B4 (server memory) | never | end of request; single-use, TTL <= 2 min in Vektra |
| Settings rows `privacyexportkey`, `privacyerasekey` | D1 (admin) | admin overwrite (rotation) | uninstall |
| Privacy export files in the tool_dataprivacy zip | D2 via core writer | never | tool_dataprivacy export expiry (core) |

Discussion config, sessions, transcripts and audit live only in Vektra; the plugin adds no tables.

## Acceptance Criteria

- [ ] New activity: enable, mostra soluzione and RAG OFF, end date empty (REQ-077)
- [ ] Enable refused by Vektra (no close date / no cut-off / team submission / marking guide / empty pasted rubric / privacy unset) -> error naming the field; Vektra config unchanged (GET after shows enable OFF) (REQ-077, BR-013, REQ-089)
- [ ] BR-013 boundaries 20,000/20,001, cap 4/5/120/121, total 240/241 min, +100%/+101% confirmation produce the expected accept/refuse on both layers (BR-013)
- [ ] Solution text never appears in page source after save, in Moodle DB (grep of a DB dump for a canary string = 0), or in logs; status shows "impostata" (OQ-034)
- [x] Vektra unreachable while editing an activity -> 0 PUT requests on save (stub log) and config unchanged (dormant-capability guard)
- [x] No PUT body ever carries RAG ON (ARCH-072)
- [ ] Non-grader and other-course teacher get an authorization error and cause 0 Vektra requests; Vektra also refuses a forged actor (live) (REQ-076, NFR-014)
- [x] Reset reason visible in the list, absent from the transcript bytes (grep = 0) (REQ-076, REQ-087)
- [x] Terminate with N active sessions -> N CLOSED(terminated_by_teacher) rows and N transcripts (row counts) (REQ-081)
- [x] +50% on a 30-min cap -> 45-min server deadline (session row) (REQ-078)
- [ ] Opening date earlier than the activity close -> field error; attestation required on every edit (REQ-091)
- [ ] FAILED transcript downloadable by a grader; served with attachment, nosniff, CSP sandbox, no-store; 0 bytes on non-200 (REQ-076, BR-016)
- [x] Setup check against a misconfigured service account names the failing item (REQ-079)
- [x] Unenrolled student's privacy export contains #1..#N, sha256-equal to the in-course copy (REQ-093)
- [x] Privacy deletion -> 0 rows for the subject in Vektra (SQL count); a deletion that fails or cannot be verified throws an explicit exception that Moodle sends to the DPOs (the request still completes, see D6), and Vektra alerts its operator (OQ-033)

## Testing Approach

PHPUnit for form section, teacher actions and the privacy provider with mocked curl; refusals
proven by an untouched mock queue and exact request bodies. Behat with the P2 Vektra stub for
rendering and the stub request log. Live checks (D5, D6, E2) in the plan-4 envs with the real stack,
verified by Vektra SQL row counts and sha256 of downloaded/exported bytes, not by HTTP 200 or the
tool_dataprivacy UI status alone.

### Test Infrastructure

- **Required**: moodle-plugin-ci (Postgres, chrome); P2 in-site Vektra stub; Moodle 4.3 and 5.2 docker envs with quiz/assign fixtures and students; vektra-stack with teacher-ops, transcript-delivery and privacy-retention endpoints; psql access to Vektra Postgres for row counts
- **Provided by**: 20261101-154950-discuss-foundation (CI), 20261101-154950-discuss-student D1 (stub), 20261101-154950-assess-moodle-ws (envs, fixtures), 20261101-154950-assess-teacher-ops, -transcript-delivery, -privacy-retention (endpoints)
- **False-pass guard**: Behat scenarios assert stub request counts; live steps record SQL counts and hashes in the PR and are reported BLOCKED naming the missing endpoint when it is absent; foundation D2 fails CI on 0 tests

### NFR Verification

| NFR | Dataset/Load | Tool | Environment | Pass criterion |
|-----|--------------|------|-------------|----------------|
| NFR-014 | teacher of another course, non-grader, forged actor, privacy export for user B | PHPUnit mock queue, Behat stub log, live Vektra audit/row counts | CI + plan-4 envs (4.3, 5.2) | 0 Vektra requests for Moodle-refused cases; Vektra 403 for forged actor; 0 foreign records in exports |

## Integration Notes

- Requires from 20261101-154950-assess-teacher-ops a `GET /activities/{cmid}/config` returning
  non-secret fields, `solution_set`, rubric length, `gate_fallback`, computed opening, active
  session count, review-options warning and an etag; ratified in INT-001 on 2026-11-01.
- Teacher endpoints take `actor_userid`; the exact field and the error codes for "not a grader",
  "after end date", "session active" come from plan 11.
- Setup-check scope: INT-001 says "operator or site admin" without a plugin scope; this plan uses
  the teacher key with a site-admin actor. Confirm with plan 11 / plan 1, or add a narrow scope.
- Privacy: (a) `GET /privacy/activities/{cmid}/subjects` for `get_users_in_context` and (b)
  `DELETE /privacy/activities/{cmid}/subjects` (optional userids) for `delete_data_for_users` /
  `delete_data_for_all_users_in_context` were added to INT-001 and to
  20261101-154950-assess-privacy-retention on 2026-11-01. Still needed: (c) a stable
  `erasure_exempt` response. Until the endpoints are deployed those methods throw (never silently no-op).

## Notes

- A1 (2026-11-12, both dev envs): `coursemodule_standard_elements`, `coursemodule_validation`,
  `coursemodule_edit_post_actions` (and `coursemodule_definition_after_data`) are still called from
  `course/moodleform_mod.php` / `course/modlib.php` on 4.3.11 and 5.2.4, with no deprecation; the
  5.x `\core_course\hook\after_form_*` hooks cover the course form only. So lib.php callbacks,
  no `db/hooks.php` entry. Capabilities: `mod/quiz:viewoverrides` exists on both,
  `mod/assign:viewoverrides` on neither: use `mod/assign:manageoverrides` for assignments. Vektra
  itself only shows the override holder to `mod/quiz:manageoverrides` holders.
- Drift: REQ-077 wants field-level errors, but rules that depend on the just-saved activity
  (BR-007 close date changed in the same save) and every rule on a create form cannot be validated
  by Vektra before Moodle saves. Errors after save are notifications; enable stays OFF. Product to
  confirm this is acceptable.
- Resolved 2026-11-01: COMP-001 "Requires" now lists assess:privacy-erase. Plugin needs four narrow keys in total.
- OQ-033 / OQ-020: erasure is implemented per the design default; if the DPO grants an exam-evidence
  exemption, deletions end in an explained failure, never in silent success.
- OQ-034: write-only solution implemented; re-display would need a Vektra-side symmetric key.
- REQ-090 (could) is not built: ARCH-067 says "no v1 preview", and a preview is a second
  context-assembly path that must pass the full anti-leak suite first. Teacher page shows the
  alternative (test-student account + reset). BACKLOG item.
- Risk: activity duplication, backup/restore and deletion get a new or no cmid; Vektra config is not
  carried (duplicates start disabled: fail closed) and data of deleted activities waits for the
  retention purge. Consider a `pre_course_module_delete` notification later (BACKLOG).

**Implementation notes (2026-11-13).**
- One PR for the whole plan (the repository rule: one PR per plan), from the top of the stacked
  branches; the commits stay sliced P3-1 to P3-5. CodeRabbit's free tier allows about one review an
  hour, and the whole plan is 39 files.
- Local review (`/code-review`) of each slice, all findings fixed or answered in the commits:
  P3-1 10, P3-2 10, P3-3 10, P3-4 10.
- B3 opening date: the teacher page does not repeat the opening-fallback form; when Vektra needs a
  date it shows Vektra's reason and links to the activity settings, where the form is (A).
- B3 extra time: offered only when Vektra accepts it (no session yet, or a granted reset not started);
  a separate form covers students who have not started yet (REQ-078 accommodations).
- B4 resend: offered only for a transcript delivered at least once (Vektra resends a round).
- Grader download and resend check that the transcript belongs to the activity whose capability Moodle
  checked, by finding it in Vektra's sessions list (the link carries its page). vektra-stack agreed to
  accept the activity id in the download-token and resend bodies (separate vektra-stack PR); switch to
  it when merged.
- D: privacy calls work with the kill switch off; the user context is always listed; subject-wide
  erasure only through the user context and only when no existing activity was left out; verification
  through the activity's subjects and the person's activities (no file download). D6 decided by the
  coordinator (OQ-033), see the task.
- Pre-session privacy block rendered (state_dto/state_view/presession). Recording the shown
  notice_version at Start waits for vektra-stack (`privacy_notice_version` in the mint body, its
  own PR; the mint is strict, so the plugin must not send it before the merge). Then: send it, and
  give vektra-stack the plugin version so it raises the minimum.
- Follow-up after #9 (release 0.2.0, version 2026111302): the Start mint sends the notice version shown
  (#211), and an invalid one refuses the mint rather than being dropped; downloads and resends send
  `cmid` (#212) and the plugin's own ownership lookups are gone (one call per download). Decided: `cmid`
  is a required argument of both mints, since it is now the only activity binding. Deploy order: Vektra
  with #211/#212 first. By design: 404 ERR-ASSESS-017 for another activity's transcript shows "not ready",
  as Vektra makes it indistinguishable from an unknown transcript; the grader fallback file name takes the
  student id and session number from the link (a label only, the bytes come from Vektra).

**Live evidence for release 0.2.0 (F1 at vektra-stack develop aa4f2fd4 with #209/#211/#212/#214, plugin develop
fb4fe9f, 2026-11-13, headless Chromium).**
- Notice version: a live Start on each site audited `assess_start` with `presented_notice_version` equal to
  `notice_version` (`f1-dev-1`): 5.2 cmid 3 (second student, session #1); 4.3 cmid 2 (first student,
  session #2 after a new session granted from the Discussions page). Both finished, transcripts generated
  and delivered.
- Activity id: student (owner) and grader downloads on both sites, copies sha256-equal (5.2 `75a21034...`,
  4.3 `846f007e...`). The grader link of the 5.2 cmid 3 transcript replayed through cmid 2 (another quiz
  the grader grades): Vektra 404 with `assess_ownership_mismatch_rejected` / `transcript_download_token_cmid`,
  no token, no bytes; the page says "not ready" (by design, above).
- Pending live checks, waiting for F1 after vektra-stack #209 (F1's 4.3 WS token was missing on
  2026-11-12: setup check `no_token`): D5, D6, E2, and the student plan's F1 and E3 (moved here when
  #3 merged).

**Live evidence on F1 (vektra-stack develop 5d27ff6f + provider wheel, 2026-11-13, headless Chromium).**
- Student plan F1, both sites: the button reached by keyboard; the pre-session screen dismissed -> 0 new
  session rows; Start -> exactly one row; sandboxed iframe, no token in the page HTML; Finish ->
  CLOSED(finished); state 6 with the transcript link; the student downloads the BR-016 file.
- Student plan E3: student and grader copies sha256-equal (4.3 `0cce291c...`, 5.2 `7a25fa64...`, the
  grader's through teacher_transcript.php); another student's mint key on the tid -> 403 ERR-AUTH-003,
  +1 audit row `assess_ownership_mismatch_rejected`, no token; through the plugin the other student gets
  `unavailable` without a download-token call.
- E2 (4.3, partial): the settings form read Vektra's values and saved a cap change (version 2 -> 3);
  +50% extra time for a student without a session (pre-session duration 60 min on a 40-min cap); a new
  session granted with a reason after a first `gate_inputs_stale` (the page said to retry), the student
  then `reset_granted` / next #2; the reason shown in the list. After vektra-stack fixed F1's model limits (a reasoning model spent its
  600-token budget on reasoning; `provider_unavailable` rollbacks from 23:32Z): the extra time on the
  session row (duration_s 3600 on cap_s 2400), and terminate from the page with the confirmed count 2 ->
  2 sessions CLOSED(terminated_by_teacher), 2 transcripts generated. One Start rolled back as
  `part_b_rejected` (`part_b_labels`, the model's opening refused by Vektra's guard) and passed on retry;
  reported. Assignments not run live (no assignment configured on F1).
- D5 (through `core_privacy\manager`, as tool_dataprivacy calls it): contexts user + quiz; the export zip
  holds the transcript sha256-equal to both downloads; the deletion leaves 0 sessions and 0 transcripts
  of the person on the site.
- D6: an erase key Vektra refuses -> the provider throws, `tool_dataprivacy\manager_observer` notifies the
  DPO with the plugin's message ("Vektra could not erase..."), Vektra's rows unchanged.

**Live evidence for the activity list (F1 at vektra-stack develop 3a9684f8, plugin version 2026111303,
2026-11-13, through `core_privacy\manager`).**
- Export on both sites for both students: contexts user + quiz; per-activity records with sessions,
  extra time (4.3 second student: 1200 s), new sessions granted and technical counts; transcript files
  sha256-equal to the earlier downloads (4.3 `846f007e...`, 5.2 `7a25fa64...`, `75a21034...`). A
  session exported while STARTING (`turns: []`), then ACTIVE (2 turns), then CLOSED with its transcript.
- Deletion of the 4.3 second student (data in one activity, request covering it): one
  `assess_erasure` with reason `subject`; before 1 session, 1 extra time, 10 start attempts, after 0;
  the verification read listed 0 activities.

**Open points (2026-11-13).**
- Privacy inventory: done with vektra-stack #215 (follow-up after #13, version 2026111303). Contexts,
  export and erasure use `GET /privacy/subjects/{userid}/activities`, the same set Vektra's erasures
  remove. Decided: the export writes everything it can, then throws `privacy_export_incomplete` naming
  `transcript_pending` / `transcript_failed` / `transcript_missing` (the DPO is notified; `pending` means
  run it again); `transcript_purged` is retention, not a failure. Erasure is subject-wide only when the
  approved targets cover every listed activity, otherwise per activity; verification re-reads the list.
  The erase key is checked before any read. Open for the DPO (OQ-020): reset reasons, teacher notes and
  `granted_by` are not exported by Vektra; deleting a person while a transcript failed or is missing
  removes content that was never exportable.
- `privacy_notice_version` in the mint (vektra-stack #211, merged): a follow-up PR sends the version
  the pre-session screen showed; Start then refuses with `409 ERR-ASSESS-029 privacy_notice_changed`
  if the notice changed. After that release, give vektra-stack the plugin version.
- Grader download/resend ownership: switch to the `cmid` in the request bodies when vektra-stack
  merges it.
