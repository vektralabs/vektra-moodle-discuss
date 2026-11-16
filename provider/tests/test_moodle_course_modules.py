"""``course_modules`` (FEAT-028, ARCH-072 amendment 2026-11-16): the visibility facts of every
module of a course, read with ``core_course_get_contents`` by the service account.

The ``materials`` recordings are the FEAT-028 test sections of a dev course, on 4.3 and 5.2:

- R0 a prerequisite page (manual completion), R1 visible with no restriction;
- R2 hidden, R3 available but not shown on the course page (stealth);
- R4 group, R5 future date, R6 completion of R0 restrictions; R7 ``[no-ai]`` in the title;
- R8 in a section restricted to a group (NOT detectable by the service account);
- R9 in a section available from 2027, R10 in a hidden section: neither comes back.
"""

from __future__ import annotations

import json
from typing import Any

import pytest
from assess_moodle_support import BRANCHES, FIXTURES, Replay, lms_site, make_provider

from vektra_lms_moodle.readers.course import CONTENTS_OPTIONS, has_conditions
from vektra_shared.lms import (
    CourseModuleVisibility,
    Readable,
    Unreadable,
    UnreadableReason,
)

#: (visible, on_course_page, has_conditions, section_visible, section_available), by title prefix
EXPECTED: dict[str, tuple[bool, bool, bool, bool, bool]] = {
    "R0 ": (True, True, False, True, True),
    "R1 ": (True, True, False, True, True),
    "R2 ": (False, True, False, True, True),
    "R3 ": (True, False, False, True, True),
    "R4 ": (True, True, True, True, True),
    "R5 ": (True, True, True, True, True),
    "R6 ": (True, True, True, True, True),
    "R7 ": (True, True, False, True, True),
    # the section's group condition passes for the service account (accessallgroups)
    "R8 ": (True, True, False, True, True),
}


def _course(branch: str, fixture: str) -> int:
    doc = json.loads(
        (FIXTURES / branch / f"core_course_get_contents__{fixture}.json").read_text()
    )
    return int(doc["params"]["courseid"])


async def _read(
    branch: str, fixture: str = "materials", mutate: Any = None
) -> Any:
    replay = Replay(branch)
    if mutate is not None:

        def rewrite(fn: str, body: Any) -> Any:
            return mutate(body) if fn == "core_course_get_contents" else body

        replay.mutate = rewrite
    got = await make_provider(replay).course_modules(
        lms_site(branch), _course(branch, fixture)
    )
    return got, replay


def _by_prefix(rows: tuple[CourseModuleVisibility, ...]) -> dict[str, CourseModuleVisibility]:
    return {r.title[:3]: r for r in rows}


@pytest.mark.parametrize("branch", BRANCHES)
async def test_the_test_sections_give_the_expected_facts(branch: str) -> None:
    got, replay = await _read(branch)
    assert isinstance(got, Readable)
    rows = _by_prefix(got.value)
    assert set(rows) == set(EXPECTED)  # R9 and R10 are not returned
    for prefix, (vis, page, cond, svis, savail) in EXPECTED.items():
        r = rows[prefix]
        assert (
            r.visible,
            r.on_course_page,
            r.has_conditions,
            r.section_visible,
            r.section_available,
        ) == (vis, page, cond, svis, savail), prefix
    assert "[no-ai]" in rows["R7 "].title
    # R8 is in its own section, the others share one
    assert rows["R8 "].section_id != rows["R1 "].section_id
    assert len({rows[p].section_id for p in EXPECTED if p != "R8 "}) == 1
    # one call, without file contents
    assert replay.calls == [
        (
            "core_course_get_contents",
            {
                "courseid": str(_course(branch, "materials")),
                "options[0][name]": "excludecontents",
                "options[0][value]": "1",
            },
        )
    ]


@pytest.mark.parametrize("branch", BRANCHES)
async def test_the_values_carry_no_url_and_no_content(branch: str) -> None:
    got, _ = await _read(branch)
    assert isinstance(got, Readable)
    for r in got.value:
        assert set(vars(r)) == {
            "cmid",
            "title",
            "section_id",
            "visible",
            "on_course_page",
            "has_conditions",
            "section_visible",
            "section_available",
        }
        assert "http" not in r.title


@pytest.mark.parametrize("branch", BRANCHES)
async def test_the_seeded_course_reads_whole(branch: str) -> None:
    got, _ = await _read(branch, "course")
    assert isinstance(got, Readable)
    assert len(got.value) == 26
    restricted = [r.title for r in got.value if r.has_conditions]
    assert restricted == ["AS hidden for stu3"]


@pytest.mark.parametrize("branch", BRANCHES)
async def test_a_section_with_a_notice_is_not_available(branch: str) -> None:
    def notice(body: Any) -> Any:
        body[0]["availabilityinfo"] = "Available from <strong>1 January 2027</strong>"
        return body

    got, _ = await _read(branch, mutate=notice)
    assert isinstance(got, Readable)
    first = got.value[0].section_id
    for r in got.value:
        assert r.section_available is (r.section_id != first)


@pytest.mark.parametrize("branch", BRANCHES)
async def test_a_section_the_account_cannot_see_is_not_available(branch: str) -> None:
    def hidden(body: Any) -> Any:
        body[0]["uservisible"] = False
        body[0]["visible"] = 0
        return body

    got, _ = await _read(branch, mutate=hidden)
    assert isinstance(got, Readable)
    first = got.value[0].section_id
    for r in got.value:
        if r.section_id == first:
            assert not r.section_available and not r.section_visible


def _drop_availability(body: Any) -> Any:
    del body[0]["modules"][1]["availability"]
    return body


def _string_visible(body: Any) -> Any:
    body[0]["modules"][1]["visible"] = "1"
    return body


def _unknown_key(body: Any) -> Any:
    body[0]["modules"][1]["newfield"] = 1
    return body


def _drop_section_uservisible(body: Any) -> Any:
    del body[1]["uservisible"]
    return body


def _exception(body: Any) -> Any:
    return {"exception": "moodle_exception", "errorcode": "nopermissions", "message": "x"}


def _warnings(body: Any) -> Any:
    return {"sections": body, "warnings": [{"warningcode": "x", "message": "y"}]}


@pytest.mark.parametrize("branch", BRANCHES)
@pytest.mark.parametrize(
    ("mutate", "reason", "detail"),
    [
        (_drop_availability, UnreadableReason.UNKNOWN_SHAPE, "shape_mismatch"),
        (_string_visible, UnreadableReason.UNKNOWN_SHAPE, "shape_mismatch"),
        (_unknown_key, UnreadableReason.UNKNOWN_SHAPE, "shape_mismatch"),
        (_drop_section_uservisible, UnreadableReason.UNKNOWN_SHAPE, "shape_mismatch"),
        (_exception, UnreadableReason.ACCESS_EXCEPTION, "nopermissions"),
        (_warnings, UnreadableReason.UNKNOWN_SHAPE, "shape_mismatch"),
    ],
    ids=["drop_availability", "string_visible", "unknown_key", "drop_section_uservisible",
         "exception", "warnings"],
)
async def test_an_unreadable_fact_makes_the_whole_read_unreadable(
    branch: str, mutate: Any, reason: UnreadableReason, detail: str
) -> None:
    """Never a partial list: one bad module or section makes the whole read Unreadable."""
    got, _ = await _read(branch, mutate=mutate)
    assert got == Unreadable(reason, detail)


@pytest.mark.parametrize(
    ("availability", "expected"),
    [
        (None, False),
        ("", False),
        ("   ", False),
        ('{"op":"&","c":[],"showc":[]}', False),
        ('{"op":"&","c":[{"type":"group","id":3}],"showc":[true]}', True),
        ('{"op":"|","c":[{"type":"date","d":">=","t":1}],"show":true}', True),
        ("not json", True),
        ("[]", True),
    ],
)
def test_has_conditions(availability: str | None, expected: bool) -> None:
    assert has_conditions(availability) is expected


def test_the_options_exclude_the_contents() -> None:
    assert CONTENTS_OPTIONS == [{"name": "excludecontents", "value": 1}]
