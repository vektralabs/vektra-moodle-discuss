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
  (`local_vektradiscuss/frame`; message names to be aligned with the Vektra discussion UI).
- README: the Vektra-side configuration needed before enabling the plugin (TLS UI origin reachable
  from student browsers, `frame-ancestors` listing the Moodle origin, matching site identifier and keys).

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
