# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Plugin scaffold: `version.php` (Moodle 4.3 to 5.2, depends on `block_vektra`), six
  capabilities in the course-module context, English and Italian language packs, privacy
  provider declaring the external Vektra location.
- Assess HTTP client (`\local_vektradiscuss\local\http\assess_client`): assess-only paths,
  no redirects, TLS verification, per-role narrow keys, secret redaction, REQ-010 error
  envelope parsing, `X-Vektra-Plugin-Version` header, bounded streaming.
- Site settings: kill switch (off by default), discussion UI origin, site identifier,
  separate mint and teacher keys (the block admin key is refused).
