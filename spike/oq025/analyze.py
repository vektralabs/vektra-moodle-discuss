"""Summarise raw probe responses per ARCH-070 flag. Usage: python3 -I analyze.py <label>"""
import json, pathlib, sys
HERE = pathlib.Path(__file__).resolve().parent / "results"
L = sys.argv[1]
R = HERE / f"raw-{L}"; man = json.loads((HERE / f"manifest-{L}.json").read_text()); U = man["users"]; inv = {v: k for k, v in U.items()}
def j(n):
    f = R / f"{n}.json"
    return json.loads(f.read_text()) if f.exists() else None
print(f"=================== {L} {man['release']}")
si = j("site_info"); print("site_info: userissiteadmin", si.get("userissiteadmin"), "| nfunctions", len(si.get("functions", [])))
print("self_user auth:", [(u.get("auth"), u.get("suspended")) for u in j("self_user")])
print("graders:", j("graders").get("message"), "| graders_alt:", j("graders_alt").get("message"))
for q in j("quizzes")["quizzes"]:
    print("quiz", q["name"][:20], {k: q.get(k) for k in ("timeclose", "timelimit", "graceperiod", "overduehandling", "reviewrightanswer")})
for n in ("attempts_A_s1", "attempts_A_s2"):
    print(n, [(a["attempt"], a["state"], a.get("sumgrades")) for a in j(n)["attempts"]])
for n in ("reviewopts_A_s1", "reviewopts_B_s1"):
    d = j(n); print(n, "some:", {o["name"]: o["value"] for o in d.get("someoptions", [])})
for n in ("review_A_s1", "review_B_s1_hidden", "review_C_s2_ungraded"):
    d = j(n); txt = json.dumps(d)
    print(n, "| grade", d.get("grade"), "| states", [(q.get("slot"), q.get("state"), q.get("mark")) for q in d.get("questions", [])],
          "| has 'rightanswer' div:", "rightanswer" in txt, "| has 'specificfeedback':", "specificfeedback" in txt)
for n in ("bestgrade_B_s1_hiddenitem", "bestgrade_C_s2_ungraded"):
    print(n, j(n))
ov = j("overrides_A")
if ov is not None:
    print("overrides_A raw keys:", list(ov.keys()) if isinstance(ov, dict) else type(ov))
    lst = ov.get("data", {}).get("overrides") if isinstance(ov.get("data"), dict) else ov.get("overrides")
    print("overrides_A:", [(inv.get(o.get("userid")), o.get("groupid"), o.get("timeclose"), o.get("timelimit")) for o in (lst or [])])
print("expected:", man["overrides"])
for a in j("assignments")["courses"][0]["assignments"]:
    print("assign", a["name"][:20], {k: a.get(k) for k in ("duedate", "cutoffdate", "gradingduedate", "markingworkflow")})
print("userflags_X:", [(inv.get(f["userid"]), f.get("extensionduedate"), f.get("workflowstate")) for a in j("userflags_X")["assignments"] for f in a["userflags"]], "| expected", man["extensions"])
p = j("participant_X_s2"); print("participant_X_s2:", {k: p.get(k) for k in ("submitted", "requiregrading", "grantedextension", "duedate", "cutoffdate") if k in p})
for n in ("status_X_s1", "status_Y_s1_unreleased"):
    d = j(n); fb = d.get("feedback") or {}; la = d.get("lastattempt") or {}
    print(n, "| feedback present:", bool(fb), "| grade", (fb.get("grade") or {}).get("grade"), "| workflow", la.get("gradingstatus"),
          "| canary/visible:", [w for w in ("VISIBLE-FEEDBACK-X-s1", "CANARY-UNRELEASED-FEEDBACK-Y") if w in json.dumps(d)])
g = j("grades_Y_unreleased"); print("grades_Y (unreleased):", [(inv.get(x["userid"]), x["grade"]) for a in g.get("assignments", []) for x in a["grades"]])
d = j("grading_defs_X"); a = d["areas"][0]["definitions"][0]; print("grading def options:", a.get("options"))
gi = j("grading_instances_X"); print("grading instances:", [(i.get("status"), i.get("rawgrade")) for i in gi.get("instances", [])])
for ug in j("grade_items_s1").get("usergrades", []):
    print("grade_items s1:", [(x.get("itemname")[:12], x.get("graderaw"), x.get("gradeishidden")) for x in ug["gradeitems"] if x.get("itemtype") == "mod"])
