"""OQ-025 / OQ-031 spike probe: call each candidate Moodle WS read as the dedicated
service account and record readability, errors and which CANARY tokens each response carries.

Usage: python3 -I probe.py <label> <base_url>     e.g.  probe.py m43 http://localhost:10443
Reads .local/provision-<label>.json (token) and results/manifest-<label>.json (fixture ids).
Writes results/probe-<label>.json and results/raw-<label>/<call>.json. Synthetic data only.
Stdlib only, no redirects followed (mirrors the follow_redirects=False rule of COMP-004).
"""

import json
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
CANARY = re.compile(r"CANARY-[A-Za-z0-9-]+")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def flatten(prefix, value, out):
    """Moodle REST encoding: a[0][b]=..."""
    if isinstance(value, dict):
        for k, v in value.items():
            flatten(f"{prefix}[{k}]" if prefix else k, v, out)
    elif isinstance(value, list):
        for i, v in enumerate(value):
            flatten(f"{prefix}[{i}]", v, out)
    else:
        out.append((prefix, str(int(value)) if isinstance(value, bool) else str(value)))


def call(base, token, fn, params):
    pairs = [("wstoken", token), ("wsfunction", fn), ("moodlewsrestformat", "json")]
    flatten("", params, pairs)
    data = urllib.parse.urlencode(pairs).encode()
    req = urllib.request.Request(f"{base}/webservice/rest/server.php", data=data, method="POST")
    try:
        with OPENER.open(req, timeout=30) as r:
            body = r.read()
            status = r.status
    except urllib.error.HTTPError as e:
        body, status = e.read(), e.code
    text = body.decode("utf-8", "replace")
    try:
        parsed = json.loads(text)
    except ValueError:
        parsed = {"_nonjson": text[:500]}
    err = None
    if isinstance(parsed, dict) and "exception" in parsed:
        err = {"errorcode": parsed.get("errorcode"), "message": parsed.get("message")}
    return status, parsed, err, len(body), sorted(set(CANARY.findall(text)))


def main():
    label, base = sys.argv[1], sys.argv[2].rstrip("/")
    prov = json.loads((HERE / ".local" / f"provision-{label}.json").read_text())
    man = json.loads((HERE / "results" / f"manifest-{label}.json").read_text())
    tok, U, Q, A, AT = prov["token"], man["users"], man["quizzes"], man["assigns"], man["attempts"]
    cid = man["courseid"]
    calls = [
        ("site_info", "core_webservice_get_site_info", {}),
        ("course_by_id", "core_course_get_courses_by_field", {"field": "id", "value": cid}),
        ("course_contents", "core_course_get_contents", {"courseid": cid}),
        ("enrolled_users", "core_enrol_get_enrolled_users", {"courseid": cid}),
        ("graders", "core_enrol_get_enrolled_users_with_capability",
         {"coursecapabilities": [{"courseid": cid, "capabilities": ["mod/assign:grade", "mod/quiz:grade"]}]}),
        ("graders_alt", "core_enrol_get_enrolled_users",
         {"courseid": cid, "options": [{"name": "withcapability", "value": "mod/assign:grade"}]}),
        ("users_courses_s1", "core_enrol_get_users_courses", {"userid": U["s1"]}),
        ("groups_s3", "core_group_get_course_user_groups", {"courseid": cid, "userid": U["s3"]}),
        ("self_user", "core_user_get_users_by_field", {"field": "id", "values": [prov["userid"]]}),
        ("quizzes", "mod_quiz_get_quizzes_by_courses", {"courseids": [cid]}),
        ("attempts_A_s1", "mod_quiz_get_user_attempts", {"quizid": Q["A"]["id"], "userid": U["s1"], "status": "all"}),
        ("attempts_A_s2", "mod_quiz_get_user_attempts", {"quizid": Q["A"]["id"], "userid": U["s2"], "status": "all"}),
        ("review_A_s1", "mod_quiz_get_attempt_review", {"attemptid": AT["A"]["s1"]}),
        ("review_B_s1_hidden", "mod_quiz_get_attempt_review", {"attemptid": AT["B"]["s1"]}),
        ("review_C_s2_ungraded", "mod_quiz_get_attempt_review", {"attemptid": AT["C"]["s2"]}),
        ("reviewopts_B_s1", "mod_quiz_get_combined_review_options", {"quizid": Q["B"]["id"], "userid": U["s1"]}),
        ("reviewopts_A_s1", "mod_quiz_get_combined_review_options", {"quizid": Q["A"]["id"], "userid": U["s1"]}),
        ("bestgrade_B_s1_hiddenitem", "mod_quiz_get_user_best_grade", {"quizid": Q["B"]["id"], "userid": U["s1"]}),
        ("bestgrade_C_s2_ungraded", "mod_quiz_get_user_best_grade", {"quizid": Q["C"]["id"], "userid": U["s2"]}),
        ("overrides_A", "mod_quiz_get_overrides", {"quizid": Q["A"]["id"]}),
        ("user_quiz_attempts_A_s1", "mod_quiz_get_user_quiz_attempts",
         {"quizid": Q["A"]["id"], "userid": U["s1"], "status": "all"}),
        ("assignments", "mod_assign_get_assignments", {"courseids": [cid], "includenotenrolledcourses": 1}),
        ("submissions_X", "mod_assign_get_submissions", {"assignmentids": [A["X"]["id"]]}),
        ("status_X_s1", "mod_assign_get_submission_status", {"assignid": A["X"]["id"], "userid": U["s1"]}),
        ("status_Y_s1_unreleased", "mod_assign_get_submission_status", {"assignid": A["Y"]["id"], "userid": U["s1"]}),
        ("grades_Y_unreleased", "mod_assign_get_grades", {"assignmentids": [A["Y"]["id"]]}),
        ("userflags_X", "mod_assign_get_user_flags", {"assignmentids": [A["X"]["id"]]}),
        ("participant_X_s2", "mod_assign_get_participant", {"assignid": A["X"]["id"], "userid": U["s2"]}),
        ("grading_defs_X", "core_grading_get_definitions",
         {"cmids": [A["X"]["cmid"]], "areaname": "submissions", "activeonly": 1}),
        ("grading_instances_X", "core_grading_get_gradingform_instances",
         {"definitionid": man["rubric_definitionid"], "since": 0}),
        ("grade_items_s1", "gradereport_user_get_grade_items", {"courseid": cid, "userid": U["s1"]}),
        ("DENIED_categories", "core_course_get_categories", {}),
    ]
    raw = HERE / "results" / f"raw-{label}"
    raw.mkdir(parents=True, exist_ok=True)
    out = {"label": label, "release": man["release"], "calls": {}}
    for name, fn, params in calls:
        if fn not in prov["functions"] and not name.startswith("DENIED"):
            out["calls"][name] = {"function": fn, "result": "NOT_IN_SERVICE (function absent in this release)"}
            continue
        status, parsed, err, size, canaries = call(base, tok, fn, params)
        if isinstance(parsed, dict) and "userprivateaccesskey" in parsed:
            parsed["userprivateaccesskey"] = "[REDACTED]"  # site_info returns a private key: never store it
        (raw / f"{name}.json").write_text(json.dumps(parsed, indent=1, ensure_ascii=False))
        out["calls"][name] = {"function": fn, "http": status, "error": err, "bytes": size, "canaries": canaries,
                              "result": "ERROR" if err else "OK"}
    # Submission file download through webservice/pluginfile.php (token as param, as Moodle requires).
    try:
        subs = json.loads((raw / "submissions_X.json").read_text())
        urls = [f["fileurl"] for a in subs.get("assignments", []) for s in a.get("submissions", [])
                for p in s.get("plugins", []) for fa in p.get("fileareas", []) for f in fa.get("files", [])]
        dl = []
        for u in urls[:2]:
            sep = "&" if "?" in u else "?"
            try:
                with OPENER.open(f"{u}{sep}token={tok}", timeout=30) as r:
                    dl.append({"http": r.status, "bytes": len(r.read()), "ctype": r.headers.get("Content-Type")})
            except urllib.error.HTTPError as e:
                dl.append({"http": e.code})
        out["calls"]["file_download"] = {"urls_found": len(urls), "downloads": dl}
    except Exception as e:  # noqa: BLE001 - spike reporting
        out["calls"]["file_download"] = {"error": repr(e)}
    (HERE / "results" / f"probe-{label}.json").write_text(json.dumps(out, indent=1, ensure_ascii=False))
    for name, r in out["calls"].items():
        print(f"{name:28} {r.get('result', ''):6} {str(r.get('error') or '')[:90]:90} {r.get('canaries', '')}")
        if name == "file_download":
            print("   ", r)


if __name__ == "__main__":
    main()
