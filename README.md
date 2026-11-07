# vektra-moodle-discuss

Moodle **local plugin** (`local_vektradiscuss`) for the Vektra *assessment discussion*
feature: a student discusses their own, already-graded quiz or assignment attempt with an
AI assistant, and a transcript is delivered to the teacher as a basis for the oral exam.

This is a thin Moodle-side adapter. All authority (session lifecycle, eligibility gates,
grade/rubric access, LLM, transcript rendering and delivery) lives in the Vektra backend
(`vektra_assess` module in [vektra-stack](https://github.com/vektralabs/vektra-stack)).
The plugin only:

- injects the student entry point (button + pre-session screen) and embeds the discussion UI;
- mints a short-lived, attempt-bound discussion token server-side (within the student's
  authenticated Moodle session);
- provides the activity settings section (teacher enables/configures per activity);
- provides teacher pages and the transcript download path;
- implements the Moodle Privacy API provider.

The plugin holds **no** session state and re-resolves every identifier server-side in Vektra.

## Status

Alpha (`0.1.0`). The foundation is in place (scaffold, capabilities, settings, assess HTTP
client, CI, release packaging), and so is the student entry point (state panel, pre-session
screen, discussion iframe). Transcript download and the teacher features follow. The plugin is
disabled by default and sends nothing to Vektra until an administrator enables it.

Requirements, architecture and the implementation plans are tracked via Spec2Ship: the
plans live in [`.s2s/plans/`](.s2s/plans/), the design in the `vektra-stack` repository.

## Installation

Prerequisites:

1. Moodle 4.3 or 5.2.
2. The [`block_vektra`](https://github.com/vektralabs/vektra-moodle) plugin, version
   `2026103001` or later, installed and configured (this plugin reuses its Vektra API URL
   and public URL). Installing without it fails with the Moodle dependency error.
3. From the Vektra operator: a site identifier and two dedicated keys, one with the
   `assess:mint` scope and one with `assess:teacher`. Never use the block admin key: the
   settings page refuses it, and Vektra rejects it on assess endpoints anyway.

Copy the plugin directory to `local/vektradiscuss` (`public/local/vektradiscuss` on Moodle
5.2), run the Moodle upgrade, then configure it under
*Site administration > Plugins > Local plugins > Vektra assessment discussion*.

Released zips contain the plugin only (`vektradiscuss/`).

### Vektra-side configuration

The discussion runs in an iframe served by the Vektra discussion UI, and the student's browser loads
it directly. Before enabling the plugin, check with the Vektra operator that:

1. **The UI origin is reachable over TLS from student browsers.** The origin is the
   *Discussion UI URL* setting or, when that setting is empty, the origin of the block's public URL.
   It must be `https://` with a certificate the browsers trust. The plugin uses no other origin: an
   `http://` origin is ignored, and so is an origin equal to the Moodle site's own origin. In both
   cases no entry point is shown. Write the port only when it is not the default one
   (`https://vektra.example.org`, not `https://vektra.example.org:443`).
2. **The UI allows Moodle to frame it.** The `Content-Security-Policy` of the UI pages must list the
   Moodle origin in `frame-ancestors`, for example `frame-ancestors https://moodle.example.org`.
   Without it the browser blocks the iframe and the student sees an empty frame.
3. **The site identifier and the keys match this Moodle.** Every token is minted for the configured
   site identifier, so a key issued for another site fails the mint and the student sees the
   generic "unavailable" text.

The iframe is sandboxed (`allow-scripts allow-same-origin allow-forms allow-downloads`), sends no
referrer, and receives the discussion token only by `postMessage` to the UI origin. The token never
appears in a URL or in the page.

## Continuous integration

The `block_vektra` dependency is installed by `.github/scripts/checkout-block-vektra.sh` from
`BLOCK_VEKTRA_REF` (override per run with the `block_vektra_ref` dispatch input). On `main`, on tags and on
pull requests to `main` the ref must be a `v*` tag or a 40-hex commit SHA (a branch fails the job); on
`develop` a branch is tolerated and the commit it resolved to is logged. The job also fails when the block's
version is lower than the one in `version.php` `dependencies`. A release tag must equal `v` plus
`$plugin->release` (`.github/scripts/assert-release-tag.sh`; self-test: `.github/scripts/test-release-tag.sh`).

## Compatibility

Targets Moodle **4.3 (403)** and **5.2 (502)**, matching the Vektra fleet.

## License

GPL-3.0-or-later (required for Moodle plugins). See [LICENSE](LICENSE).
