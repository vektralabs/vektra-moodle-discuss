# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Plugin scaffold: `version.php` (Moodle 4.3 to 5.2, depends on `block_vektra`), six
  capabilities (five in the course-module context, `setupcheck` in the system context), English and Italian language packs, privacy
  provider declaring the external Vektra location.
- Assess HTTP client (`\local_vektradiscuss\local\http\assess_client`): assess-only paths,
  no redirects, TLS verification, per-role narrow keys, secret redaction, REQ-010 error
  envelope parsing, `X-Vektra-Plugin-Version` header, bounded streaming.
- Site settings: kill switch (off by default), discussion UI origin, site identifier,
  separate mint and teacher keys (the block admin key is refused).
- Student entry point on the quiz review, quiz summary and assignment pages: a placeholder injected
  through the `before_footer` callback (Moodle 4.3) or the `before_footer_html_generation` hook (4.4+),
  filled by the `local_vektradiscuss/entry` AMD module with the advisory state from Vektra (REQ-068
  states 1-11, plus "log in as" and the non-selected attempt), and a pre-session screen in a modal.
- Student AJAX endpoint (`ajax.php`): advisory state and server-side mint of the pre-session token,
  with the positive login-as assertion and the plugin version in the mint body.
- Discussion iframe on the Vektra UI origin with an origin- and source-checked postMessage handshake
  (`local_vektradiscuss/frame`) following the Vektra postMessage contract version 1: the UI page
  `/api/v1/assess/ui?site=<site id>`, every message `{v: 1, type: "vektra-assess-<name>"}`, the token sent
  with its mode (`start` or `resume`) and no session id. The entry point needs the site identifier.
- A student's own transcripts (REQ-088): the panel lists one entry per closed session, in any state, and
  `transcript.php` streams the file from Vektra with a single-use download token minted server-side. The
  plugin sends its own headers: attachment with the BR-016 file name or a fallback, `nosniff`, a CSP
  sandbox, `no-store` and `no-referrer`. Nothing is stored. The discussion iframe's download request
  (`vektra-assess-download-token-request`) is answered the same way, and the iframe now stays with its
  closing screen when the session closes.
- README: the Vektra-side configuration needed before enabling the plugin (TLS UI origin reachable
  from student browsers, `frame-ancestors` listing the Moodle origin, matching site identifier and keys).
- Activity settings section in the quiz and assignment form (REQ-077, REQ-091): enable, time limit,
  end date, rubric source, write-only solution, pasted rubric and transcript recipients, the solution
  toggle, RAG shown locked off, and the opening-date fallback with its attestation when Vektra needs
  it. Load before write, only the changed fields with the version read, Vektra's field errors named.
- Teacher *Discussions* page (REQ-076, REQ-081, REQ-087, REQ-078): sessions, deliveries, resets and
  extra time per student, the gate panel, new sessions, ending the running discussions with the
  confirmed count, extra time; grader transcript download and resend.
- Privacy: export and erase keys, a Moodle privacy provider that exports Vektra's exact transcript
  bytes (hash-checked) and deletes per activity with verification, failing loudly otherwise (OQ-033);
  the pre-session screen shows Vektra's privacy summary, legal basis, retention and notice version.
- Setup check page for the site administrator (REQ-079).
- The Start mint carries the privacy notice version the pre-session screen showed
  (`privacy_notice_version`); Vektra refuses the Start if the notice changed since (release 0.2.0).
- Privacy provider on Vektra's per-person activity list (vektra-stack #215): contexts for every activity
  with data, export of the sessions (turns of a session without a transcript), new sessions granted,
  extra time, retention tombstones and technical record counts, a named error for content that cannot be
  exported (after writing everything else), and per-activity erasure verified against the list.
- Transcript downloads (student and grader) and resends carry the activity id (`cmid`); Vektra refuses a
  transcript of another activity, so the plugin no longer pages the activity state or the sessions list
  to check ownership (release 0.2.0).
- Teacher pages ask Vektra to refresh the activity's LMS facts as they open (`POST
  /activities/{cmid}/gate-refresh` on the Discussions page, the activity settings and the grader download):
  fire and forget and silent, after the page's own read, so the first teacher action no longer meets a stale role check (vektra-stack #221).
- Every teacher call is sent once more when Vektra refuses on LMS facts it is still refreshing: the
  refusal carries `error.details.retry_after_s` (at most 5 seconds), the plugin waits that long and repeats
  the same request, and shows the second refusal as final. The forms say "Checking…" while the server
  waits. A Vektra without the hint keeps the previous one retry of a stale role check.
- The Discussions page shows, next to each session, how many replies the student flagged as wrong
  ("N flagged replies", REQ-103); a Vektra that does not send the count shows nothing.
- The student panel says why the discussion is not available yet after grading: no submitted work (assignment)
  or no finished attempt (quiz), grading not complete, regrading in progress, grade not published. While
  Vektra is still checking the activity it says "Checking…" and asks again after 1, 2 and 4 seconds, then
  falls back to "temporarily unavailable".
- The activity settings warn when "Let the assistant show the solution" is on, both under the option and
  after a save that turns it on (REQ-077); its help text carries the full warning on the solution.
- Course material search in the activity settings (FEAT-028): a switch, off by default, that replaces the
  locked text. Turning it on requires the teacher's confirmation about `[no-ai]` titles and group-restricted
  sections, never pre-ticked (also after a validation error); the save sends `rag_enabled` with `rag_attested`
  in its own request after the other changes, so a refusal of the search never drops them, and the form shows
  the confirmation date (`rag_attested_at`). When the view says `rag_available: false` (with
  `rag_unavailable_reason`, e.g. `operator_flag_off`), the reason replaces the switch while the search is off. Vektra's refusals `rag_attestation_required` and `rag_unavailable`
  (with its reason: `operator_flag_off`, `eligibility_unreadable`) are shown with their own sentence.
- "Show" next to a saved solution, pasted rubric or recipient list in the activity settings (OQ-034), for users
  who can edit the activity: `reveal.php` (POST, sesskey) reads it through `POST .../config/reveal` and the
  `local_vektradiscuss/reveal` module shows it in a read-only area outside the form fields; "Hide" removes it.
  The text is never stored, cached or logged.
- Edit in place (demo feedback, 2026-11-20): ticking "Edit" next to a saved solution or pasted grading criteria
  fills the field with the text read through the same reveal request (`local_vektradiscuss/secretedit`), for
  users who can edit the activity; the save sends the field as the new text, "Remove" deletes it. On a failed
  read the field stays empty with a message. The text lives only in the page's field.
- The Discussions page opens a transcript in a new browser tab ("Open transcript #n") besides the download:
  `teacher_transcript.php?inline=1` serves it inline, still sandboxed, with only its own inline stylesheet.

### Changed

- Italian and English wording: "rubrica"/"rubric" becomes "criteri di valutazione"/"grading criteria" in
  the activity settings ("Fonte dei criteri di valutazione", "Testo dei criteri"); Moodle's own rubric keeps
  its name. Field names and the API (`pasted_rubric`) are unchanged.
- Course material search starts on for an activity Vektra has no configuration for (`configured: false`),
  and is sent, with the required confirmation, only when the discussion is turned on in the same save.

### Fixed

- On quizzes the activity settings no longer say that the review options hide the attempt when they do not:
  that warning now follows the quiz's own review options, and Vektra's reduced quiz detail gets its own note
  (Moodle's per-question feedback does not reach the assistant; use the Solution field to let it explain
  mistakes).
- The Discussions page showed Vektra's opening-fallback reason in Vektra's language; it now uses the
  plugin's own string.
- The Vektra client loads Moodle's `filelib.php` itself: outside a web page (a CLI script, a task) the `curl`
  class was not loaded and every call to Vektra failed.

### Security

- Student requests are refused by Moodle (login, course access, sesskey, capability) before any request
  to Vektra; a "log in as" session never reaches Vektra and never gets a token.
- Any Vektra failure is shown as the generic "temporarily unavailable" state: no error code, message or
  request id reaches the browser. Nothing is cached server-side or in the browser (CI guard).
- The token is posted only to the configured UI origin, never to `*`; a UI origin equal to the Moodle
  origin is refused.
- The site kill switch is enforced by the client: a disabled site sends nothing to Vektra.
- https is mandatory for the API URL and the UI origin; `$CFG->debugdeveloper` no longer relaxes it (only an
  explicit code-level override, used by the smoke CLI `--allow-local`).
- Log redaction is structural (any depth, any value type) and cannot fail on very long values; a body that
  cannot be JSON-encoded is refused instead of being sent empty.
- `viewtranscripts` carries `RISK_PERSONAL` and `setupcheck` carries `RISK_CONFIG`.
- The discussion UI origin comes only from the plugin's own setting, never from the block's public URL
  (the Vektra API host, where the UI page does not answer). The iframe sandbox is exactly the one the UI is
  tested with (`allow-scripts allow-same-origin allow-downloads`, no `allow-forms`), with only
  `clipboard-write` allowed.
