"""The facts of ``core_course_get_contents`` the course-material reader uses (FEAT-028).

Hand-written, unlike the generated per-version models: the answer of ``core_course_get_contents``
is a different shape for every module type (a label has no ``url``, a forum may carry an
``activitybadge``, a resource a ``description``...), and no recording can hold every module type
of every site. So these models declare only the keys the reader needs, each REQUIRED with its exact
type (``strict``): a missing key or a wrong type is still a ``ValidationError``, i.e.
``Unreadable(unknown_shape)`` for the whole read; any other key is ignored. The same models serve
4.3 and 5.2 (the keys below exist on both).
"""

from __future__ import annotations

from typing import Any

from pydantic import BaseModel, ConfigDict, TypeAdapter


class _Facts(BaseModel):
    model_config = ConfigDict(extra="ignore", strict=True, frozen=True)


class ModuleFacts(_Facts):
    id: int
    name: str
    modname: str
    visible: int
    visibleoncoursepage: int
    uservisible: bool
    #: present for an account with ``moodle/course:viewhiddenactivities``; null when no condition
    availability: str | None


class SectionFacts(_Facts):
    id: int
    visible: int
    uservisible: bool
    hiddenbynumsections: int
    modules: list[ModuleFacts]
    #: present only when the section is not available to the caller
    availabilityinfo: str | None = None
    #: 5.x delegated sections (``mod_subsection``): the component and the delegating cmid
    component: str | None = None
    itemid: int | None = None


ADAPTERS: dict[str, TypeAdapter[Any]] = {
    "core_course_get_contents": TypeAdapter(list[SectionFacts]),
}
