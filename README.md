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

🚧 **Design complete, implementation not started.**

Requirements, architecture and implementation plans are tracked via Spec2Ship in
`vektra-stack` (`feature/assessment-discussion-bot`). Code will land here only after the
plans are approved and the Moodle web-service feasibility spike (OQ-025) is run against the
live 4.3 and 5.2 fleet.

## Compatibility

Targets Moodle **4.3 (403)** and **5.2 (502)**, matching the Vektra fleet.

## License

GPL-3.0-or-later (required for Moodle plugins). See [LICENSE](LICENSE).
