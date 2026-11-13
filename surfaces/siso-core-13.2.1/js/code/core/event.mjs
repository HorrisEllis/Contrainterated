/*
core/event.mjs — Event, a datum flowing through the stream.

Contract js-core-api EVENT / READ-ONLY / STATELESS THROUGH THE EVENT
(core 13). Port of lua/code/core/event.lua.

  import { Event } from "./event.mjs";
  Event(type, data) → a read-only event { type, data } (data defaults to
                      {}); `new Event(type, data)` returns the same kind
                      of object
  Event.is(v)       → true only for an event the core built

An event is a type and data, nothing else. Extra arguments attach
nothing. Created, never modified by delivery: the stream's delivery
metadata (id, cause, depth) rides beside the event in its queue, never on
it.

Read-only from construction: Event takes the data as it stands, copying
it into fresh frozen objects (core/freeze.mjs, one memo per call), so a
write the caller makes afterwards to what it passed never reaches the
event and the caller's objects are never frozen in place. What the core
already froze (an event carried in data, data read from another event)
is kept by identity. Anything that is not plain data is refused with a
TypeError "Event: data at <path> is not plain data (<what>)".

Stateless through the event: an event's prototype is Object.prototype,
its data's are intrinsics or null, every object on its graph is frozen,
and Event.is checks a private brand (no look-alike, Proxy or structured
clone passes). Event, Event.prototype and Event.is are frozen.
*/

import { newEvent, isEvent } from "./freeze.mjs";

export function Event(type, data) {
  return newEvent(type, data);
}

Event.is = (v) => isEvent(v);

Object.freeze(Event.is);
Object.freeze(Event.prototype);
Object.freeze(Event);

export default Event;
