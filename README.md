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

Alpha (`0.2.0`). The foundation is in place (scaffold, capabilities, settings, assess HTTP
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
3. From the Vektra operator: a site identifier and four dedicated keys, each bound to this site
   (see the table below). Never use the block admin key: the settings page refuses it, and Vektra
   rejects it on assess endpoints anyway. The settings page also refuses the same key in two roles.

| Setting | Vektra scope | Used for |
|---------|--------------|----------|
| Mint key | `assess:mint` | Students: the discussion token, the state of the button, their own transcript downloads |
| Teacher key | `assess:teacher` | Teachers: the activity settings, the Discussions page (sessions, new sessions, ending discussions, extra time, transcript downloads and resends) and the setup check |
| Privacy export key | `assess:privacy-export` | The Moodle privacy API: finding and exporting a person's transcripts |
| Privacy erase key | `assess:privacy-erase` | The Moodle privacy API: deleting a person's discussion data |

Copy the plugin directory to `local/vektradiscuss` (`public/local/vektradiscuss` on Moodle
5.2), run the Moodle upgrade, then configure it under
*Site administration > Plugins > Local plugins > Vektra assessment discussion*.

Released zips contain the plugin only (`vektradiscuss/`).

### Vektra-side configuration

The discussion runs in an iframe served by the Vektra discussion UI, and the student's browser loads
it directly. Before enabling the plugin, check with the Vektra operator that:

1. **The UI origin is reachable over TLS from student browsers.** The origin is the
   *Discussion UI origin* setting, and only that: the UI page answers on its own host, never on the
   Vektra API host, so the block's public URL is not used and an empty setting shows no entry point.
   It must be `https://` with its own certificate that the browsers trust. The plugin uses no other
   origin: an `http://` origin is ignored, and so is an origin equal to the Moodle site's own origin.
   In both cases no entry point is shown. Write the port only when it is not the default one
   (`https://vektra.example.org`, not `https://vektra.example.org:443`).
2. **The UI allows Moodle to frame it.** The `Content-Security-Policy` of the UI pages must list the
   Moodle origin in `frame-ancestors`, for example `frame-ancestors https://moodle.example.org`.
   Without it the browser blocks the iframe and the student sees an empty frame.
3. **The site identifier and the keys match this Moodle.** Every token is minted for the configured
   site identifier, so a key issued for another site fails the mint and the student sees the
   generic "unavailable" text.

The iframe is sandboxed (`allow-scripts allow-same-origin allow-downloads`, with only `clipboard-write`
allowed), sends no
referrer, and receives the discussion token only by `postMessage` to the UI origin. The token never
appears in a URL or in the page.

### Setup check

*Site administration > Plugins > Local plugins > Assessment discussion: setup check* shows which
keys are configured (never their values) and whether the UI origin is usable, and runs Vektra's
check of the site's service account: each item by name with its result. Vektra refuses to enable a
discussion while an item is not OK. At most one check every 5 minutes.

## Teacher guide

**Activity settings.** In a quiz or an assignment, the *Discussion with the assistant* section reads
the activity's settings from Vektra when the form opens, and saves only what you change.

- Save a new activity first: the section can be configured once the activity exists.
- To enable the discussion, the activity needs a close date (quiz) or a cut-off date (assignment), a
  time limit, at least one transcript recipient, and the site's privacy settings and setup check
  must be in order. When Vektra refuses, the reason appears after saving, and the discussion stays
  off.
- The solution, the pasted rubric and the recipients are write-only: once saved, the form only says
  whether they are set. Replace them to change them. They are stored in Vektra and never in Moodle.
- Course material search is off in this version.
- If Vektra cannot read every closing date of the activity (overrides, grace period), the form asks
  for the date from which discussions can start, with a confirmation that no student has time
  beyond it.
- If Vektra cannot be reached while you edit the activity, the section is locked and nothing is
  sent: the rest of the activity saves as usual.

**Discussions page.** The activity's *Discussions* entry (teachers who can see transcripts) lists
each student's sessions, how their transcripts were delivered, the new sessions granted and the extra
time. Teachers who can manage discussions can:

- grant a new session after a student's latest closed one, with a reason that only this page shows
  (the student is not notified by email);
- end every discussion in progress, confirming their number, optionally disabling the activity;
- give extra time in minutes or as a percentage, before a student's first discussion or after a new
  session was granted (more than +100% needs a confirmation; time limit plus extra time at most 240
  minutes);
- download any transcript, and send a delivered transcript again to the current recipients.

## Privacy

The plugin stores no discussion data in Moodle: conversations and transcripts are kept by Vektra,
under the retention period the operator configured. Students see Vektra's privacy summary, the legal
basis, the retention period and the notice link on the screen before they start.

The Moodle privacy API works through Vektra:

- **Contexts** come from Vektra's list of the person's activities: every activity where Vektra holds a
  row that an erasure would remove (sessions, new sessions granted, extra time, retention tombstones,
  technical records). Data of activities deleted from Moodle belongs to the person's own context.
- **Export** writes, per activity, the sessions with their turns (for a session without a transcript yet),
  the new sessions granted and the extra time (without the teacher's reasons or notes), the number of
  sessions removed by retention and the technical record counts; and each transcript exactly as Vektra
  stored it, after checking it against its hash. When some content cannot be exported (a transcript still
  being generated, or one that failed or is missing), everything else is written and the plugin raises
  an error naming what is missing: run the request again later for a pending transcript.
- **Deletion** asks Vektra to erase the data: the whole person in one request when the request covers
  every activity Vektra lists, otherwise activity by activity, so that an activity the request did not
  approve is never reached. It then reads the list back to verify that nothing is left. If a key is missing, Vektra fails or is still erasing,
  data is still found, or the operator keeps the data as exam evidence, the plugin raises an error
  with an explanation. Moodle sends it to the site's Data Protection Officers; note that Moodle then
  completes the request anyway (a plugin cannot mark it as failed), so the DPO message is the signal
  to act on. Vektra also alerts its operator when an erasure fails or stays pending. Privacy requests
  keep working when the plugin is switched off.

## Continuous integration

The `block_vektra` dependency is installed by `.github/scripts/checkout-block-vektra.sh` from
`BLOCK_VEKTRA_REF` (override per run with the `block_vektra_ref` dispatch input). On `main`, on tags and on
pull requests to `main` the ref must be a `v*` tag or a 40-hex commit SHA (a branch fails the job); on
`develop` a branch is tolerated and the commit it resolved to is logged. The job also fails when the block's
version is lower than the one in `version.php` `dependencies`. A release tag must equal `v` plus
`$plugin->release` (`.github/scripts/assert-release-tag.sh`; self-test: `.github/scripts/test-release-tag.sh`).

## Compatibility

Targets Moodle **4.3 (403)** and **5.2 (502)**, matching the Vektra fleet.

Release 0.2.0 pairs with Vektra 0.9.0, the first Vektra release with the assessment discussion. For this
first installation, install both: the order does not matter while no student has an active session.

Later releases follow one rule when Vektra makes a new request field mandatory: first a Vektra release that
accepts it as optional, then the plugin release that sends it, then the Vektra release that requires it.
Vektra refuses a plugin older than its minimum version at the mint (`403 ERR-ASSESS-016`).

## Moodle LMS provider (`provider/`)

The repository also hosts `vektra-lms-moodle`, the Python provider through which the Vektra
backend reads Moodle (web-service client, response models, capability probes, setup-check
items). It is a separate distribution, released as a wheel under `provider-v*` tags, and it is
not part of the plugin zip. See [`provider/README.md`](provider/README.md).

## License

The plugin is GPL-3.0-or-later (required for Moodle plugins). See [LICENSE](LICENSE).
The provider in `provider/` keeps the Apache-2.0 license it had in vektra-stack
([`provider/LICENSE`](provider/LICENSE)).
