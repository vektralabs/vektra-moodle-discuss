# OQ-025 / OQ-031 spike report: Moodle WS readability for the assess service account

**Date**: 2026-11-01
**Status**: complete for the scope below; findings feed ARCH-070 (capability matrix), REQ-079 (setup check), BR-007/BR-010/BR-011/BR-012/BR-014, and plans `20261101-154950-assess-moodle-ws`, `-assess-context`, `-assess-teacher-ops` (vektra-stack).
**Environments**: Moodle **4.3.11+** (Build 20250411, PHP 8.2.35) and **5.2.4+** (Build 20261030, PHP 8.4.27), MariaDB 10.11, local Docker, synthetic fixtures only.
**Nature**: throwaway spike code (not product code). Nothing here is part of an approved plan.

## TL;DR

- Every gate input the design needs is **readable on both versions**, with five exceptions:
  - **quiz overrides** and **per-student time limit**: readable on 5.2 only;
  - **grace period**: readable only while the quiz is open;
  - **rubric display settings**: not readable on either version;
  - **per-student module visibility**: not readable on either version;
  - **login-as cross-check**: not readable on either version.
- **Review HTML is not filterable.** The service account's attempt review always contains the hidden general feedback and the right-answer block, even when the student's review options hide them. **The BR-011 structured-only path is the normal path**, driven by `mod_quiz_get_combined_review_options`, which IS per-student and readable after close.
- **`auth=nologin` is refused by the WS layer** on both versions (`wsaccessusernologin`). "Interactive login disabled" (REQ-079) must be implemented with **`auth=webservice`**.
- **The minimal role needs no grade-write capability.** `mod/assign:grade` is not needed: `mod/assign:viewgrades` covers every read. `moodle/grade:managegradingforms` is needed only for rubric *fillings* (instances), not for rubric definitions. Together with the amended REQ-079, the service can hold read-only functions only.
- **Silent fail-open traps** were found and must be handled by the typed readers:
  - empty-with-warning responses (HTTP 200);
  - `null` fields that depend on the caller;
  - unreleased grades returned as plain values.

## Method

1. One Docker image builds both versions (`docker/`). `cli/seed.php` uses Moodle's own data generators to seed one course:
   - users: 4 students, 1 editing teacher, 1 grader, 2 groups;
   - **Quiz A**: open review, everything visible after close, plus a second attempt by s1;
   - **Quiz B**: correctness, feedback and right answer hidden; marks shown after close only; grade item hidden (unreleased);
   - **Quiz C**: essay, s2 left ungraded;
   - **Quiz A overrides**: one user override (s2: later close, longer limit) and one group override (G2);
   - **Assign X**: rubric, released grade with feedback, file submissions, extension for s2;
   - **Assign Y**: marking workflow, graded but `inmarking` (unreleased);
   - **Assign X override**: s3 (`cli/assign_override.php`).

   Every field the bot must not see carries a unique `CANARY-*` token.
2. `cli/provision.php` creates the dedicated account (`vektra_assess_svc`) idempotently:
   - a system-level role `vektra_assess_reader`;
   - an external service `vektra_assess` with restricted users and **read functions only**;
   - a permanent token.
3. `probe.py` (stdlib, no redirects followed) calls each candidate read as the service account. It stores the raw responses (`results/raw-*`, private key redacted) and records which canaries each response carries. `analyze.py` extracts the fields per flag.
4. Capability ablation: remove one capability at a time (and one combined pair), re-probe, and diff against the baseline (`results/ablation/`).

Reproduce: `cd docker && docker compose up -d --build`, then install the composer dev deps inside each container (needed by the question generator; see Notes). Then, as `www-data`, run `seed.php`, `assign_override.php` and `provision.php`, then `python3 -I probe.py m43 http://localhost:10443` (and `m52 http://localhost:10452`).

## Capability matrix (ARCH-070 flags)

| Flag | 4.3 | 5.2 | Evidence (function → field) | Design consequence |
|---|---|---|---|---|
| `quiz_overrides` | **unreadable** | readable | 5.2: `mod_quiz_get_overrides(quizid)` → user (`userid`, `timeclose`, `timelimit`) and group (`groupid`, `timeclose`) rows, matched against seeded values; needs `mod/quiz:viewoverrides`. 4.3: no such function exists (verified in `external_functions`). | 4.3: any quiz with overrides needs the REQ-091 attested date. REQ-091 is the normal path on 4.3. |
| `per_student_timelimit` | **unreadable** | readable | Same source as overrides (`timelimit`). | Same as above. |
| `quiz_grace` | **partial** | **partial** | `mod_quiz_get_quizzes_by_courses` returns `graceperiod` and `overduehandling` only when `access_manager` does not prevent access *for the caller*. Verified: closed → `null`, temporarily reopened → `600` / `graceperiod`. Adding `mod/quiz:preview` and `ignoretimelimits` does not help. The `manageactivities` branch adds only timestamps, password and subnet. | The gate-input refresh tick must **capture grace while the quiz is open** and persist it. Never captured → close-time flag unreadable → REQ-091. |
| `attempt_state` | readable | readable | `mod_quiz_get_user_attempts(quizid, userid, status=all)` → `attempt`, `state`, `sumgrades`; needs `mod/quiz:viewreports`. 5.2 also has `mod_quiz_get_user_quiz_attempts`. | BR-012 attempt selection is feasible on both. |
| `assign_cutoff` | readable | readable | `mod_assign_get_assignments(courseids, includenotenrolledcourses=1)` → `duedate`, `cutoffdate`, `gradingduedate`, `markingworkflow`. | **Trap**: without `includenotenrolledcourses=1` the call returns HTTP 200 with `courses: []` and a *warning*. Readers must treat any warning as Unreadable. |
| `assign_extensions` | readable | readable | `mod_assign_get_user_flags(assignmentids)` → `extensionduedate` (s2 matched); also `participant.grantedextension`. | — |
| assign user overrides (not in the ARCH-070 list) | readable | readable | `mod_assign_get_participant(assignid, userid)` → **effective** `duedate` / `cutoffdate` for that student (s3 override matched). `get_assignments` shows activity-level dates only. | Add this flag. The effective assignment close must come from `get_participant`, per student. |
| `review_html_filterable` | **no** | **no** | `mod_quiz_get_attempt_review` for Quiz B (student options hide feedback and right answer) contains `CANARY-GENFB-*`, the `rightanswer` block and `specificfeedback` markup on both versions. The review is rendered with the caller's (teacher-like) visibility. | **The BR-011 structured-only path is the norm** (expected by the plan-4 risk note, now confirmed). |
| per-student review options (input to the structured path) | readable | readable | `mod_quiz_get_combined_review_options(quizid, userid)`. B for s1: `feedback 0`, `generalfeedback 0`, `rightanswer 0`, `overallfeedback 0`, `marks 2`. A: all 1. Correct after close. | Use this as the frozen policy input for `assemble_context`. Never use `quizzes_by_courses.review*`, which is `null` after close (caller-relative). |
| `rubric_display_settings` | **unreadable** | **unreadable** | `core_grading_get_definitions` returns criteria and level definitions (canaries present) but **no options** (`showdescriptionstudent` and the like). No external file in `grade/grading` references those options on either version. | Under BR-011 "visibility unreadable" → **exclude rubric descriptors** from the model context, always. Discussion quality impact; see the open points. |
| `module_visibility_per_student` | **unreadable** | **unreadable** | No read function takes a target `userid` for availability/visibility (`core_course_get_contents` is caller-relative). | OQ-031(1): RAG stays locked OFF (ARCH-072, FEAT-028). |
| login-as cross-check (capability row, COMP-004) | **unreadable** | **unreadable** | No log-reading WS function exists on either version (`report_log*` / `tool_log*` absent). | OQ-031(2): trust-the-plugin is the accepted residual risk (REQ-084). |

## Other gate inputs

| Input | 4.3 | 5.2 | Evidence | Notes |
|---|---|---|---|---|
| Quiz close / limit (activity level) | readable | readable | `quizzes_by_courses` → `timeclose`, `timelimit` (always visible) | — |
| Quiz grade released (BR-010) | readable | readable | `gradereport_user_get_grade_items(courseid, userid)` → `gradeishidden: true` for Quiz B | **Trap**: `mod_quiz_get_user_best_grade` returns `hasgrade: true, grade: 10` for the hidden Quiz B. Release must come from the grade report, never from `best_grade`. |
| Assignment grade released (BR-010) | readable | readable | `mod_assign_get_submission_status(assignid, userid)` → `gradingstatus: inmarking`, **no feedback** for Y; `graded` + feedback for X | **Trap**: `mod_assign_get_grades` returns s1's unreleased `73.00000` for Y. Capture must gate on `gradingstatus` / workflow state and ignore `get_grades` for release. |
| Fully graded (BR-014) | readable | readable | Attempt review question `state: needsgrading`; `best_grade.hasgrade: false` (Quiz C, s2) | — |
| Rubric fillings | readable | readable | `core_grading_get_gradingform_instances(definitionid)`; needs `moodle/grade:managegradingforms` | — |
| Submissions and files | readable | readable | `mod_assign_get_submissions` → file URLs; downloaded through `webservice/pluginfile.php` (HTTP 200, `text/plain`); the service needs `downloadfiles = 1` | Moodle requires the token **as a query parameter** on file URLs: those URLs must never be logged (COMP-004 "never log bodies" also applies to URLs). |
| Enrolment re-check | readable | readable | `core_enrol_get_users_courses(userid)`, `core_enrol_get_enrolled_users(courseid)` | — |
| Groups | readable | readable | `core_group_get_course_user_groups(courseid, userid)` | — |
| Graders (REQ-082 recipients) | readable | readable | `core_enrol_get_enrolled_users_with_capability(... mod/assign:grade)` → t1, t2 | Needs **`moodle/role:review`** (read-only). Without it: `nopermissions`. |

## REQ-079 setup-check items

| Item | Verifiable via WS? | How / finding |
|---|---|---|
| Token works | yes | `core_webservice_get_site_info` OK |
| Required reads succeed | yes | the probe set above |
| Interactive login disabled | yes | `core_user_get_users_by_field(id, self)` → `auth: webservice`. **Use `auth_webservice`, not `nologin`**: `nologin` makes every WS call fail with `wsaccessusernologin` (both versions). The `webservice` auth plugin must be enabled in the site's auth list. |
| Not a site admin | yes | `site_info.userissiteadmin: false` |
| Not the ingestion account | yes | compare `site_info.username` / `userid` with the configured ingestion account |
| Denied-function probe fails | yes | `core_course_get_categories` (not in the service) → `accessexception` on both |
| No grade-write function in the service (amended) | yes | `site_info.functions[]` checked against a write-function denylist. The ablation shows no write function is needed. |

**Minimal role (ablation-verified, both versions).** Each capability below breaks at least one read when removed:
- `webservice/rest:use`;
- `moodle/course:view`: without enrolment it breaks nearly everything;
- `mod/quiz:viewreports`: attempts, reviews, review options, best grade;
- `mod/quiz:viewoverrides`: overrides (5.2 only);
- `mod/assign:viewgrades`: submission status and participant;
- `moodle/grade:viewall`: grade report items;
- `moodle/grade:managegradingforms`: rubric instances;
- `moodle/role:review`: graders.

Granted for coverage, but not ablated individually:
- `moodle/course:viewparticipants`, `moodle/course:viewhiddenactivities`, `moodle/course:viewhiddencourses`;
- `moodle/site:accessallgroups`, `moodle/user:viewdetails`;
- `moodle/grade:viewhidden`, `moodle/grade:view`, `gradereport/user:view`;
- `mod/quiz:view`, `mod/quiz:reviewmyattempts`, `mod/assign:view`.

**`mod/assign:grade` is NOT needed.** Removing it alone breaks nothing (files still download). Removing it together with `viewgrades` breaks status and participant, so `viewgrades` is what carries those reads.

## Traps for the typed readers (COMP-004)

These are the "returns 200 and a reassuring body" cases the project rules warn about. Each one becomes an Unreadable or an explicit rule in the reader, never a silent value.

1. **Warning-not-error**: `mod_assign_get_assignments` without `includenotenrolledcourses` → `courses: []` plus a warning. Any `warnings[]` entry for a requested item must map to Unreadable.
2. **Caller-relative nulls**: `quizzes_by_courses` returns `graceperiod`, `overduehandling` and `review*` as `null` after close. `null` is not "zero" or "none": it is Unreadable.
3. **Unreleased values returned as normal values**: `get_user_best_grade` ignores a hidden grade item, and `mod_assign_get_grades` ignores the marking workflow state. Release has its own sources (grade report, `gradingstatus`).
4. **Review HTML with hidden content**: anything extracted from review HTML must be treated as containing excluded material. The structured path must not reuse it.
5. **Secrets in responses and URLs**:
   - `site_info` returns `userprivateaccesskey`;
   - file URLs need `?token=`.

   Both must stay out of logs, traces and stored snapshots. The spike probe redacts the key.

## Impact on design and plans (proposed; not applied)

- **REQ-079**: "interactive login disabled" → specify `auth=webservice` (auth_webservice enabled); add "`moodle/role:review` granted" and "service `downloadfiles = 1`" to the checklist; record the minimal capability list above.
- **ARCH-070 flags**:
  - add `assign_user_overrides` (via `get_participant`);
  - split `quiz_grace` into "captured while open" vs unreadable;
  - record `quiz_overrides` and `per_student_timelimit` as unreadable on 4.3 / readable on 5.2;
  - record `rubric_display_settings`, `module_visibility_per_student` and login-as as unreadable on both.
- **Plan `assess-moodle-ws`**: reader rules for traps 1-5; the gate-input refresh tick captures grace (and the activity review settings) while the quiz is open; probe fixtures as seeded here.
- **Plan `assess-context`**:
  - structured-only path by default;
  - policy from `combined_review_options`;
  - rubric descriptors always excluded (display settings unreadable);
  - release gates from the grade report and `gradingstatus`.
- **Plan `assess-teacher-ops`**: on 4.3, the REQ-091 attested-date UI is the expected path for quizzes with overrides; the teacher should be told why.
- **OQ-031**: both sub-questions resolved as unreadable (design defaults stand).

## Open points for product / coordinator

1. **Rubric descriptors are always excluded.** Their visibility to students cannot be read, so BR-011 fails closed. The bot can then discuss rubric scores and the teacher's remarks, but not the criterion and level texts. Is that acceptable for v1? The alternative is a teacher attestation per activity that the descriptors are student-visible.
2. **On 4.3, overridden quizzes need a teacher-attested date** (REQ-091) for every activity that has user or group overrides. Is that acceptable for the pilot sites, and which Moodle version do they run?
3. **Grace capture while open.** If Vektra was not watching the activity before it closed (for example, discussion enabled after close), grace is unreadable and REQ-091 applies.

## Limits of this spike

- Not covered:
  - question types beyond tf/mc/shortanswer/numerical/essay;
  - random questions;
  - team submissions;
  - availability conditions;
  - scales and marking guides;
  - PDF/DOCX files (text only);
  - large files;
  - WS latency and rate behaviour;
  - Postgres-backed Moodle.
- One 4.3 build (4.3.11+). Older 4.3.x point releases may differ.
- Seeding needs Moodle's composer dev dependencies (PHPUnit) inside the container, and defines `BEHAT_TEST` *after* bootstrap only to unlock the quiz generator helper. This is acceptable for a spike, not for a production probe course. Production probes need a separate, generator-free seeder (plan 4 / P1).
- The spike's own seed bug (review options passed to the generator are ignored and had to be set in the DB) is noted because the same trap would affect any fixture tooling.
