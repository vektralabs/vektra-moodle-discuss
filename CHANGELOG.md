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
  exported, and a subject-wide erasure when the request covers every listed activity.
- Transcript downloads (student and grader) and resends carry the activity id (`cmid`); Vektra refuses a
  transcript of another activity, so the plugin no longer pages the activity state or the sessions list
  to check ownership (release 0.2.0).

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
