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

### Security

- The site kill switch is enforced by the client: a disabled site sends nothing to Vektra.
- https is mandatory for the API URL and the UI origin; `$CFG->debugdeveloper` no longer relaxes it (only an
  explicit code-level override, used by the smoke CLI `--allow-local`).
- Log redaction is structural (any depth, any value type) and cannot fail on very long values; a body that
  cannot be JSON-encoded is refused instead of being sent empty.
- `viewtranscripts` carries `RISK_PERSONAL` and `setupcheck` carries `RISK_CONFIG`.
