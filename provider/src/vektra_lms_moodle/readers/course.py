"""Course material visibility (FEAT-028, ARCH-072 amendment 2026-11-16).

One call, ``core_course_get_contents`` without file contents, gives every module the service
account can see with the facts the vertical needs to decide what course material is eligible
for retrieval. The read is relative to the CALLER (the service account): a group condition on a
section is not visible here (the account holds ``moodle/site:accessallgroups``), and a section
the account cannot see comes back without its modules, which are then simply absent.
"""

from __future__ import annotations

import json
from typing import Any

from vektra_lms_moodle.client import MoodleWsClient, SiteRef
from vektra_lms_moodle.domain import CourseModuleFacts
from vektra_lms_moodle.readers._support import fetch, reader
from vektra_lms_moodle.result import Readable, Reading, Unreadable

#: no file names, no file URLs: the module list only (``contents`` comes back empty)
CONTENTS_OPTIONS: list[dict[str, Any]] = [{"name": "excludecontents", "value": 1}]


def has_conditions(availability: str | None) -> bool:
    """Whether a module's raw availability tree holds any condition.

    ``None`` or an empty string: none. A tree whose top-level ``c`` list is empty (what Moodle
    may keep after every condition is removed): none. Anything else, including a value that is
    not a JSON tree, counts as a condition: the safe answer is "restricted"."""
    if availability is None or not availability.strip():
        return False
    try:
        tree = json.loads(availability)
    except ValueError:
        return True
    return not (
        isinstance(tree, dict) and isinstance(tree.get("c"), list) and not tree["c"]
    )


@reader
async def read_course_modules(
    client: MoodleWsClient, site: SiteRef, courseid: int
) -> Reading[tuple[CourseModuleFacts, ...]]:
    """Every module of the course with its visibility facts, in course order.

    Fail closed: the strict model rejects a missing key or an unexpected type (the whole read is
    Unreadable, never a partial list). ``availability`` must be present on every module (it is
    returned only to an account with ``moodle/course:viewhiddenactivities``)."""
    res = await fetch(
        client,
        site,
        "core_course_get_contents",
        {"courseid": courseid, "options": CONTENTS_OPTIONS},
    )
    if isinstance(res, Unreadable):
        return res
    rows = tuple(
        CourseModuleFacts(
            cmid=mod.id,
            name=mod.name,
            sectionid=sec.id,
            visible=mod.visible == 1,
            visibleoncoursepage=mod.visibleoncoursepage == 1,
            has_availability=has_conditions(mod.availability),
            section_visible=sec.visible == 1,
            section_available=sec.uservisible and not sec.availabilityinfo,
        )
        for sec in res.value
        for mod in sec.modules
    )
    return Readable(rows, res.evidence)
