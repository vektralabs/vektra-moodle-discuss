# Implementation Plan: local_vektradiscuss teacher path and privacy: activity settings section, teacher pages, grader download proxy, setup check, Privacy API provider

**ID**: 20261101-154950-discuss-teacher-privacy
**Status**: active
**Branch**: N/A
**Created**: 2026-11-01T14:49:50Z
**Updated**: 2026-11-01T20:58:19Z
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
   cannot be carried out throws; it never returns as if it had succeeded.

## Design Notes

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
  `moodle_exception`, so tool_dataprivacy records the request as failed and an admin sees it. If the
  DPO records a retention exemption (OQ-020/OQ-033), the plugin reads an `erasure_exempt` response
  and throws a dedicated, explained exception; it never returns normally without deleting.
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

### A. Settings section in the activity form
- [ ] A1. Verify on 4.3 and 5.2 envs: the three `coursemodule_*` callbacks fire for quiz and assign, or which `\core_course\hook\*` replace them; whether `mod/quiz:viewoverrides` and `mod/assign:viewoverrides` exist on both. Record results in the plan Notes before coding A2.
- [ ] A2. `lib.php` callbacks (+ `db/hooks.php` if A1 requires) delegating to `classes/local/form/settings_section.php`: GET config on render, frozen/unavailable rendering, create-form defaults, all fields from Design Notes, help strings incl. the pasted-rubric line and the D3 sentence (REQ-077 AC), computed opening condition, end date, "N sessioni attive" with a link to the terminate action, review-options warning from Vektra.
- [ ] A3. `amd/src/charcounter.js`: live counter for solution and pasted rubric (20,000), announced politely at thresholds; built into `amd/build`.
- [ ] A4. `settings_section::validate()` for `coursemodule_validation` (BR-013 mirror, messages naming ranges) and `settings_section::save()` for `edit_post_actions` (etag rule, PUT with If-Match, solution/rubric only when replaced, unset from `$data` afterwards, Vektra field errors -> notification naming fields).
- [ ] A5. PHPUnit `tests/local/form/settings_section_test.php` with mocked curl: GET failure -> save sends 0 requests (mock queue untouched); unchanged fields -> 0 PUT; solution untouched -> PUT body has no `solution` key; replace -> present; remove -> `null`; RAG never true in any PUT body; 20,000 accepted / 20,001 refused locally; cap 4/121 refused, 5/120 accepted; Vektra 422 with field errors -> notification lists each field.
- [ ] A6. Behat `tests/behat/teacher_settings.feature` (stub from P2 D1): new quiz shows defaults and frozen enable; edit form shows "impostata" without text in page source; outage -> frozen section and stub received 0 PUT; refused enable (stub returns BR-007 error) -> error notification, stub config unchanged.

### B. Teacher page
- [ ] B1. `local_vektradiscuss_extend_settings_navigation()` adding "Discussioni" to quiz/assign module settings for `:viewtranscripts`; `teacher.php?cmid=` with `classes/output/teacher_page.php` renderable + `templates/teacher_sessions.mustache`.
- [ ] B2. Session list from `GET /activities/{cmid}/sessions`: per student sessions #1..#N, state and close reason (incl. access_revoked), delivery status incl. bounces and "studente non avvisato via email" (REQ-092), reset count and reasons, gate panel (effective opening, override holder name only with the override-view capability, C7 fallback reason), SHOULD hint for suspended/unenrolled holder when Vektra reports it.
- [ ] B3. Actions as POST forms (sesskey, `:manage`): reset with mandatory reason and the continuity opt-in (default OFF, REQ-087(b)); terminate with confirmation stating N and mandatory reason (REQ-081); extra time in minutes or % with the 240-min total stated and >+100% confirmation (BR-013), shown only before start; opening date + attestation (REQ-091); resend (no recipient field exists in the form or request). Vektra refusals (reset on ACTIVE, after end date with pointer to the date field, extra time on ACTIVE/CLOSED, opening date moved earlier) shown as notifications with the localized reason.
- [ ] B4. Grader download `teacher_transcript.php` via `transcript_proxy` with the teacher-key download token.
- [ ] B5. PHPUnit `tests/local/teacher_actions_test.php`: each action's exact request body (actor present, no recipient parameter on resend, reason present); missing reason -> 0 requests; user without `:manage` -> exception and 0 requests; login-as -> refused, 0 requests; teacher of course B with cmid of course A -> `require_login` failure, 0 requests.
- [ ] B6. Behat `tests/behat/teacher_page.feature`: list renders all columns from stub fixtures; reset reason visible in list; override holder name hidden for a non-editing teacher without the capability; terminate confirmation shows N.

### C. Setup check page
- [ ] C1. `admin/setupcheck.php` + `settings.php` registration + template rendering REQ-079 items by name, privacy items, key-configured flags (never values).
- [ ] C2. PHPUnit/Behat: stub returns one MISSING item -> that item named; transport failure -> generic error, no item shown as OK.

### D. Privacy provider
- [ ] D1. Settings `privacyexportkey`, `privacyerasekey`; client roles `privacy_export`, `privacy_erase`; lang strings for metadata.
- [ ] D2. Provider metadata + `get_contexts_for_userid` + `get_users_in_context` + `export_user_data` with hash verification per Design Notes.
- [ ] D3. Delete methods with post-delete verification and throw-on-anything-else; dedicated exception strings for "key not configured", "Vektra refused", "verification found N remaining", "erasure exempt (DPO)".
- [ ] D4. PHPUnit `tests/privacy/provider_test.php` (extends `\core_privacy\tests\provider_testcase`): metadata contains the external location; export writes bytes sha256-equal to the mock; hash mismatch throws; delete with missing key throws; delete with 200 but verification returning 1 transcript throws; delete success makes exactly 2 requests (DELETE + verification GET); exempt -> throws; cross-user: export for user B never includes A's records (mock asserts the requested userid).
- [ ] D5. Live (plan-4 env + real stack): create a discussion for a fixture student, unenrol them, run `admin/tool/dataprivacy` export -> zip contains transcripts #1..#N sha256-equal to the in-course copy (REQ-093(b)); run deletion -> Vektra row counts for that subject's sessions, snapshots, turns, transcripts = 0 (SQL counts recorded), export afterwards contains 0 transcripts.
- [ ] D6. Live negative: revoke the erase key in Vektra, run a deletion request -> the tool_dataprivacy request ends in failed state (DB status recorded), not completed.

### E. Docs and wrap-up
- [ ] E1. README: teacher guide (settings fields, write-only solution, RAG off), required keys and scopes table (mint, teacher, privacy-export, privacy-erase; admin key never), privacy section (what leaves Moodle, export/delete behaviour, DPO exemption path); CHANGELOG `[Unreleased]`; raise `maturity` to BETA only after D5/D6 pass.
- [ ] E2. Live teacher E2E on 4.3 and 5.2 (quiz + assign): enable, cap change, extra time +50% on 30 min -> Vektra deadline 45 min (REQ-078 AC, read from the session row), terminate with N=2 -> 2 CLOSED(terminated_by_teacher) rows and 2 transcripts, reset -> session #2 row, grader download sha256-equal to the student copy (P2 E3).

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
- [ ] Vektra unreachable while editing an activity -> 0 PUT requests on save (stub log) and config unchanged (dormant-capability guard)
- [ ] No PUT body ever carries RAG ON (ARCH-072)
- [ ] Non-grader and other-course teacher get an authorization error and cause 0 Vektra requests; Vektra also refuses a forged actor (live) (REQ-076, NFR-014)
- [ ] Reset reason visible in the list, absent from the transcript bytes (grep = 0) (REQ-076, REQ-087)
- [ ] Terminate with N active sessions -> N CLOSED(terminated_by_teacher) rows and N transcripts (row counts) (REQ-081)
- [ ] +50% on a 30-min cap -> 45-min server deadline (session row) (REQ-078)
- [ ] Opening date earlier than the activity close -> field error; attestation required on every edit (REQ-091)
- [ ] FAILED transcript downloadable by a grader; served with attachment, nosniff, CSP sandbox, no-store; 0 bytes on non-200 (REQ-076, BR-016)
- [ ] Setup check against a misconfigured service account names the failing item (REQ-079)
- [ ] Unenrolled student's privacy export contains #1..#N, sha256-equal to the in-course copy (REQ-093)
- [ ] Privacy deletion -> 0 rows for the subject in Vektra (SQL count); a deletion that fails or cannot be verified leaves the request in failed state, never completed (OQ-033)

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
