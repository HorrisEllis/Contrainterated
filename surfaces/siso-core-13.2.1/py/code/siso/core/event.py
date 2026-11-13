"""siso.core.event -- Event, a datum flowing through the stream.

Contract py-core-api EVENT / READ-ONLY / STATELESS THROUGH THE EVENT
(core 13.0.0). Port of lua/code/core/event.lua.

  from siso.core.event import Event
  Event(type, data=None) -> a read-only event with exactly `type` (a str)
                            and `data` (a FrozenDict; None gives an empty one)
  Event.isEvent(v)       -> True only for an Event (type(v) is Event; Event
                            is final, so this is isinstance without asking
                            the value anything)

An event is a type and data, nothing else. A third argument is refused by
Python's arity check (TypeError), never dropped. Created, never modified
by delivery: the stream's delivery metadata (id, cause, depth) rides
beside the event in its queue, never on it.

Read-only from construction: Event takes the data as it stands, copying
it into fresh frozen values (a dict -> FrozenDict, a list or tuple ->
tuple), iteratively and with one memo per call, so a write the caller
makes afterwards to what it passed never reaches the event and the
caller's objects are never changed. What the core already froze (an
Event carried in data, another event's FrozenDict) is kept by identity.
Anything that is not plain data is refused with
TypeError("Event: data at <path> is not plain data (<what>)").

The class lives with the engine that builds it (siso.core.freeze, as
JavaScript's newEvent lives in freeze.mjs); this module is its public
face. Event is final (a subclass is refused when its class statement
runs), slotted (no instance __dict__), refuses assigning or deleting an
attribute with AttributeError("read-only: an event and its data cannot be
assigned (field '<name>')"), and its class is locked. Event.isEvent is the
instance of a locked slotted class, not a Python function.
Python's rawset (object.__setattr__ on the slots) is not refused: the
substrate concession named in py-core-api SUBSTRATE (Lua's R3).
"""

from siso.core.freeze import Event

__all__ = ["Event"]
