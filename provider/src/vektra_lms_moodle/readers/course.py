"""Course material visibility (FEAT-028, ARCH-072 amendment 2026-11-16).

One call, ``core_course_get_contents`` without file contents, gives every module the service
account can see with the facts the vertical needs to decide what course material is eligible
for retrieval. The read is relative to the CALLER (the service account):

- a section the account cannot see comes back without its modules, which are then absent;
- a section restriction the account SATISFIES is not visible here: a group condition (the account
  holds ``moodle/site:accessallgroups``), a profile condition it matches, an "until" date not
  reached yet. The section raw availability is not returned by any read-only function; the
  teacher's confirmation covers it (ARCH-072 amendment);
- a module the account cannot use (``uservisible`` false) counts as restricted whatever the
  reason, so it is never eligible.

On 5.x a delegated section (``mod_subsection``) inherits the visibility and the restrictions of
the module that delegates it and of that module's own section: an unknown delegating module makes
it unavailable.
"""

from __future__ import annotations

import json
from typing import Any

from vektra_lms_moodle.client import MoodleWsClient, SiteRef
from vektra_lms_moodle.domain import CourseModuleFacts
from vektra_lms_moodle.models.course_facts import ModuleFacts, SectionFacts
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


def _restricted(mod: ModuleFacts) -> bool:
    return has_conditions(mod.availability) or not mod.uservisible


def course_module_facts(
    sections: list[SectionFacts],
) -> tuple[CourseModuleFacts, ...]:
    """The facts of every module, with delegated sections resolved through their module."""
    by_id = {s.id: s for s in sections}
    home: dict[int, tuple[SectionFacts, ModuleFacts]] = {
        mod.id: (sec, mod) for sec in sections for mod in sec.modules
    }
    memo: dict[int, tuple[bool, bool]] = {}

    def effective(sec: SectionFacts, seen: frozenset[int]) -> tuple[bool, bool]:
        """(visible, available) of a section, following its delegating module."""
        if sec.id in memo:
            return memo[sec.id]
        visible = sec.visible == 1 and sec.hiddenbynumsections == 0
        available = sec.uservisible and not sec.availabilityinfo
        if sec.component is not None:
            parent = home.get(sec.itemid) if sec.itemid is not None else None
            if parent is None or sec.id in seen:
                visible = available = False
            else:
                psec, pmod = parent
                pvisible, pavailable = effective(by_id[psec.id], seen | {sec.id})
                visible = (
                    visible
                    and pvisible
                    and pmod.visible == 1
                    and pmod.visibleoncoursepage == 1
                )
                available = available and pavailable and not _restricted(pmod)
        memo[sec.id] = (visible, available)
        return memo[sec.id]

    rows: list[CourseModuleFacts] = []
    for sec in sections:
        svisible, savailable = effective(sec, frozenset())
        rows.extend(
            CourseModuleFacts(
                cmid=mod.id,
                name=mod.name,
                sectionid=sec.id,
                visible=mod.visible == 1,
                visibleoncoursepage=mod.visibleoncoursepage == 1,
                has_availability=_restricted(mod),
                section_visible=svisible,
                section_available=savailable,
            )
            for mod in sec.modules
        )
    return tuple(rows)


@reader
async def read_course_modules(
    client: MoodleWsClient, site: SiteRef, courseid: int
) -> Reading[tuple[CourseModuleFacts, ...]]:
    """Every module of the course with its visibility facts, in course order.

    Fail closed: a missing key or an unexpected type of any fact the reader uses makes the whole
    read Unreadable, never a partial list. ``availability`` must be present on every module (it is
    returned only to an account with ``moodle/course:viewhiddenactivities``)."""
    res = await fetch(
        client,
        site,
        "core_course_get_contents",
        {"courseid": courseid, "options": CONTENTS_OPTIONS},
    )
    if isinstance(res, Unreadable):
        return res
    return Readable(course_module_facts(res.value), res.evidence)
