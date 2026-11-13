/*
core/rejection.mjs — what a rejected propagation throws (K5).

Contract js-core-api REJECTION. Port of lua/code/core/rejection.lua. A
rejection carries the reason and the identities of the delivery
involved, and nothing else. It is an Error (Object.prototype.toString
answers "[object Error]") named "Rejection", `String(r) === r.message`,
and its own enumerable fields are exactly:

  code       SISO_SELF_LOOP | SISO_CYCLE | SISO_GATE_THREW | SISO_NOT_EVENT
  message    a string
  eventId    the delivery involved (absent only for a refused entry)
  causeId    that delivery's cause (absent for the entry delivery)
  eventType  that delivery's event type (absent only for a refused entry)
  depth      SISO_CYCLE only
  cause      SISO_GATE_THREW only: exactly what the gate threw

A field that would be undefined is absent. Its non-enumerable `stack` is
the substrate's.

  import { Rejection } from "./rejection.mjs";
  Rejection.selfLoop(delivery, signature)
  Rejection.cycle(delivery, gateCount)
  Rejection.gateThrew(delivery, signature, cause)
  Rejection.notEvent(delivery, signature, what)   delivery undefined: a refused entry
  Rejection.is(v)

`delivery` is the stream's view of one delivery: { id, causeId, type,
depth }. Each builder makes a fresh rejection holding exactly the fields
its code carries; nothing here is kept between calls. `new Rejection(…)`
from outside this module throws: only the builders make one, and
Rejection.is checks a private brand, so a look-alike is never one.

The class, its prototype, toString and the builders are frozen (core
13.0.0's counterpart: nothing a holder of one rejection writes reaches
another). An observer is never shown a rejection: it receives
freeze.plain of its fields (observer-seam THE REJECTION).

X1 (SPEC.md) applied: a thrown value's `message` is read raw — an own
data property, never a getter or anything inherited — and under
protection, so building the rejection never runs the gate's code and
never throws.
*/

const TOKEN = Object.freeze({});

function define(o, k, value) {
  if (value === undefined) return;
  Object.defineProperty(o, k, { __proto__: null, value, enumerable: true, writable: true, configurable: true });
}

// String(v) that never throws (a symbol renders, an object never runs
// its own code: it is named by kind).
function safeString(v) {
  if (v === null || (typeof v !== "object" && typeof v !== "function")) {
    try {
      return String(v);
    } catch {
      return typeof v;
    }
  }
  return describe(v);
}

// A short name for a value that is not an Event. Never throws, never
// runs code the value carries.
export function describe(v) {
  if (v === undefined) return "undefined";
  if (v === null) return "null";
  const t = typeof v;
  if (t === "boolean" || t === "number" || t === "bigint") return `the ${t} ${String(v)}`;
  if (t === "string") return "a string";
  if (t === "symbol") return "a symbol";
  if (t === "function") return "a function";
  try {
    if (Array.isArray(v)) return "an array that is not an Event";
  } catch {
    // a revoked Proxy
  }
  return "an object that is not an Event";
}

// A thrown value with an own string `message` (an Error) shows that
// message; a primitive shows as String gives it; any other object is
// named by kind. Read raw and under protection (X1).
function thrownMessage(thrown) {
  if (thrown !== null && (typeof thrown === "object" || typeof thrown === "function")) {
    try {
      const d = Object.getOwnPropertyDescriptor(thrown, "message");
      if (d !== undefined && "value" in d && typeof d.value === "string") return d.value;
    } catch {
      // a Proxy whose trap throws: named by kind below
    }
    try {
      if (Array.isArray(thrown)) return "an array";
    } catch {
      // a revoked Proxy
    }
    return typeof thrown === "function" ? "a function" : "an object";
  }
  return safeString(thrown);
}

export class Rejection extends Error {
  #rejection = true;

  constructor(token, code, message, delivery, extra) {
    if (token !== TOKEN) throw new TypeError("Rejection: only the stream builds a rejection");
    super(message);
    define(this, "code", code);
    define(this, "message", message);
    if (delivery !== undefined) {
      define(this, "eventId", delivery.id);
      define(this, "causeId", delivery.causeId);
      define(this, "eventType", delivery.type);
    }
    if (extra !== undefined) define(this, extra.key, extra.value);
  }

  toString() {
    return this.message;
  }

  static is(v) {
    return v !== null && typeof v === "object" && #rejection in v;
  }

  static selfLoop(delivery, signature) {
    return new Rejection(TOKEN, "SISO_SELF_LOOP",
      `Self-loop: gate '${safeString(signature)}' returned its own type`, delivery);
  }

  static cycle(delivery, gateCount) {
    return new Rejection(TOKEN, "SISO_CYCLE",
      `Cycle: '${safeString(delivery.type)}' claimed at depth ${delivery.depth} with ${gateCount} gates registered — a type repeated on this causal chain`,
      delivery, { key: "depth", value: delivery.depth });
  }

  static gateThrew(delivery, signature, cause) {
    return new Rejection(TOKEN, "SISO_GATE_THREW",
      `Gate '${safeString(signature)}' threw: ${thrownMessage(cause)}`,
      delivery, { key: "cause", value: cause });
  }

  static notEvent(delivery, signature, what) {
    if (delivery === undefined) {
      return new Rejection(TOKEN, "SISO_NOT_EVENT", `Not an Event: the entry door was given ${what}`);
    }
    return new Rejection(TOKEN, "SISO_NOT_EVENT",
      `Not an Event: gate '${safeString(signature)}' returned ${what} (a gate returns undefined, null, an Event or an array of Events)`,
      delivery);
  }
}

Object.defineProperty(Rejection.prototype, "name", { value: "Rejection" });
for (const k of Object.getOwnPropertyNames(Rejection)) {
  const d = Object.getOwnPropertyDescriptor(Rejection, k);
  if ("value" in d && typeof d.value === "function") Object.freeze(d.value);
}
Object.freeze(Rejection.prototype.toString);
Object.freeze(Rejection.prototype);
Object.freeze(Rejection);
Object.freeze(describe);

export default Rejection;
