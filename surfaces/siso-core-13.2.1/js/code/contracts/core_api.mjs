/*
contract: core-api — what the core hands everyone outside it (core 13,
JavaScript instance). Port of lua/code/contracts/core_api.lua.

Between: stream -> observer, kit, fixtures, and every consuming project.
Only a contracts phase changes this file. It holds what crosses, not how
the stream works.

MODULES (import root js/code/; ESM, relative imports with explicit .mjs)
  core/event.mjs   export Event
  core/gate.mjs    export Gate
  core/stream.mjs  export Stream
  (core/rejection.mjs exports Rejection; core/index.mjs may re-export
  the four.) Core modules import nothing from `node:`.

EVENT
  Event(type, data) → an event: a frozen plain object whose own keys are
    exactly `type` and `data` (both enumerable data properties), with
    prototype Object.prototype, read-only from the moment it is built:
    assignment, defineProperty, delete and setPrototypeOf on the event,
    or on anything in its data, fail (TypeError in strict code), wherever
    the event was built (a gate, a boundary layer, a test). `data` is {}
    when undefined. Extra arguments attach nothing. Calling Event with
    `new` returns the same kind of object.
    The data is taken as it stands: Event deep-copies it into fresh
    frozen objects (one memo per call, so sharing and cycles inside the
    data are preserved), so a write the caller makes afterwards to the
    objects it passed never reaches the event, and the caller's objects
    are never frozen in place. Values the core already froze (an event
    carried in data, data read from another event) are kept by identity,
    so an event or data passed on unchanged keeps its identity. A
    caller's own frozen object is the caller's: it is copied like any
    plain object.
    What is plain data: primitives (as they are), plain objects
    (prototype Object.prototype or null, every own property an
    enumerable, string-keyed data property, each read once) and dense
    arrays (the copy is a frozen Array). A symbol key, a non-enumerable
    own property or an accessor is refused like any other non-plain
    value, so nothing in the caller's data is silently dropped or read
    through code (Drift 7: carry or refuse, never repair). Anything
    else — a function, a class instance, Map, Set, Date, RegExp, a typed
    array, a boxed primitive, a Promise, a sparse array — is refused:
    Event throws a TypeError whose message is
    "Event: data at <path> is not plain data (<what>)".
  Event.is(v) → true only for an object the core built as an event: a
    brand in a module-private class's private field, checked with
    `#brand in v`. A look-alike, an Object.create of an event's
    prototype, a Proxy around an event and a structured clone all answer
    false; any value, null and undefined included, is answered without
    throwing.
  STATELESS THROUGH THE EVENT: nothing reachable from an event is an
    object the core holds or shares, or one anyone can write. Every
    object on an event's graph is frozen, every property is a data
    property (no accessor: a getter is a closure, which can hold state),
    no value is a function, and the only prototypes are realm intrinsics
    (Object.prototype, Array.prototype for arrays, or null). The core's
    exported objects (Event, Event.is, Gate, Stream, Stream.prototype,
    every door, and everything reachable from them by own properties and
    prototypes short of the realm's intrinsics) are frozen, and a
    rejection's prototype chain short of the intrinsics is frozen, so
    nothing a holder of one rejection writes reaches another. The
    intrinsics themselves are writable globals (SUBSTRATE).
  An event is plain data: `data` holds strings, finite numbers,
    booleans, null and plain objects and arrays of them, exactly what
    kit/data.mjs encodes as canonical JSON (SISO "polyglot"; the kit
    checks this, the stream does not).
  READ-ONLY (from construction): every event Event builds, and so every
    event that enters, every event a gate returns, and every event a
    gate, a sample, a take or an observer is given, is frozen at every
    depth. Reads work through property access, `length`, `for…of`,
    `Object.keys`, `for…in` and spread, and reading the same field twice
    gives the same value (===), objects included. A frozen array is an
    Array (Array.isArray).
  IMMUTABLE SENTINELS: JSON null is the primitive `null`, which needs no
    sentinel; Lua's "readonly:" sentinels vanish (SUBSTRATE).
  OWNERSHIP: the stream never holds an object the outside still holds,
    and never hands out one it still holds. Entry takes the stream's own
    copy of the event (fresh identities at every depth), so nothing the
    entering code holds is in the residue, and a private-field stamp the
    entering code (or a gate) puts on what it holds is never on what a
    later propagation is given. A take hands the residue over (the
    stream holds none of it afterwards). A sample's residue holds the
    sampler's own frozen copies of the events, and an observer's records
    carry the observer's own frozen copies of the data (observer-seam),
    so nothing done to them — a write attempt, a private-field stamp —
    reaches the stream, a later sample or another observer. Copies
    preserve sharing within one owner's copy (memo) and never share with
    the original or another owner. Only inside a propagation is an event
    shared (a gate shares its input with the stream).

GATE
  Gate(signature, transform, shape) → a frozen { signature, transform,
  shape } holding the three values as given; a missing transform is a
  no-op function. The stream calls `gate.transform(event)` with the event
  as the only argument. `transform` returns:
    undefined or null  consumed, no follow-up
    an Event           one follow-up
    an array of Events Array.isArray, own keys exactly the indices
                       0..length-1 (no hole, no named own property),
                       every member an Event
  Anything else (false, 0, "", a number, a string, a non-Event object,
  a Promise — an async transform —, a sparse array, an array with a
  named key) is a rejection (SISO_NOT_EVENT). A frozen array a gate read
  from its event's data is an Array like any other. `shape` is optional,
  a declaration for tooling ({ emits: [types…], rejects: [types…] });
  the stream never reads it.

STREAM
  new Stream() → a stream with no gates, no residue, no observers, and
  no own properties; the instance is frozen, so none can be added and no
  door can be shadowed. Its state is in private fields, so the doors are
  the only way in (but see SUBSTRATE on intrinsics).
  Doors:
    stream.register(gate)      building. A duplicate signature throws an
                               Error whose message is exactly
                               "Signature collision: '<sig>'".
    stream.emit(event)         entry. Runs one propagation until it
                               settles and returns undefined, or throws a
                               rejection (below). Nothing else is thrown.
    stream.takeResidue()       take. → the residue as an Array; the
                               stream holds none of it afterwards.
    stream.sampleHere()        sample. → { residue: <an Array of frozen
                               copies of the residue's events
                               (OWNERSHIP)>, gateCount: <registered
                               gates> }. Nothing else (no event counter).
    stream.attach(observer)    observe. `observer` is a function; see the
    stream.detach(observer)    observer-seam contract. Attaching one that
                               is attached, or detaching one that is not,
                               throws.
  Residue is a multiset: its order is not part of the answer.

IDS
  Each propagation numbers its own deliveries 1, 2, … in delivery order.
  The entry delivery is 1 and has no cause (causeId absent). Nothing is
  counted across propagations, and no id survives the propagation except
  in what leaves through a door (a rejection, an observer's batch).

REJECTION
  A rejected propagation commits no residue and leaves the stream as it
  was before the entry. emit throws a rejection: an Error (it carries
  [[ErrorData]]: Object.prototype.toString answers "[object Error]")
  whose `name` is "Rejection", `String(rejection) === rejection.message`,
  and whose own enumerable fields are exactly:
    code       one of the codes below
    message    a string
    eventId    the delivery involved (absent only for a refused entry)
    causeId    that delivery's cause (absent for the entry delivery)
    eventType  that delivery's event type (absent only for a refused
               entry)
    depth      SISO_CYCLE only: the delivery's depth on the causal chain
    cause      SISO_GATE_THREW only: exactly what the gate threw (any JS
               value: an Error, an object, a string, …)
  A field that would be undefined is absent, not present-but-undefined.
  Its non-enumerable `stack` is the substrate's (SUBSTRATE).
  Codes:
    SISO_SELF_LOOP   a gate returned an event of its own type. The
                     delivery is the one whose gate returned it.
    SISO_CYCLE       a claimed delivery sits deeper than the registered
                     gate count (read when the propagation starts). The
                     delivery is that one.
    SISO_GATE_THREW  a gate threw. The delivery is the one it was given.
    SISO_NOT_EVENT   emit was given a value that is not an Event, or an
                     Event the stream cannot take its own copy of (too
                     deep for the substrate) (no delivery: eventId,
                     causeId and eventType absent), or a gate returned
                     something that is not undefined, null, an Event or
                     an array of Events. The delivery is the one whose
                     gate returned it.
  Within one returned array, members are judged in index order; the
  first that is not an Event or is the gate's own type decides the
  rejection; then the keys are judged (a hole or a named key is
  SISO_NOT_EVENT). Nothing a gate returns is dropped or repaired
  (Drift 7).

SUBSTRATE (JavaScript; how the reference's Lua mechanisms map here)
  - Read-only by the language: Object.freeze at every depth. Lua's
    proxy tables, their private key and `rawset` past the refusal vanish:
    there is no rawset, so the R3 rawset clause vanishes. In sloppy-mode
    code an assignment to a frozen object is silently ignored instead of
    throwing; nothing reaches the event either way (a note; ESM is
    strict).
  - Event.is is a private-field brand stamped with the return-override
    idiom; Lua's X1 "a table whose metatable answers siso.event without
    being built by Event" vanishes: an event cannot be forged.
  - getmetatable answers vanish: an event's prototype is the intrinsic
    Object.prototype, plain data's are intrinsics or null. Lua's data
    marks (kit.data.array) vanish: a JS array is an Array.
  - Immutable sentinels vanish (JSON null is the primitive null), and
    with them Lua's R4 (core 12 `__readonly` sentinels).
  - Lua's R5 (host code reaches the stream's table fields and its
    shared metatable) thins: private fields, a frozen instance and
    frozen classes leave no field or shared table to reach. What
    remains is the intrinsics convention below, for host code as well
    as gates. The doors call built-ins captured at load and use plain
    loops, so rewriting an intrinsic method after the core loaded
    reaches nothing; but code that installs an index accessor on
    Array.prototype (or Object.prototype) is handed the core's internal
    arrays when the core writes a missing index (independent review,
    13.1.0). Not defended: it is a global write by trusted code.
  - Calling emit with the call stack nearly exhausted can throw the
    engine's RangeError before the stream can turn it into a rejection;
    a RangeError raised inside a gate's frame because the stack ran out
    there is that gate's throw (SISO_GATE_THREW); and an entry the
    stream cannot copy for want of stack, however shallow, is refused as
    SISO_NOT_EVENT ("an Event too deep to copy"): the substrate's limit
    (a note).
  - Data is plain data, checked at construction: Lua freezes any table
    and passes functions through; a JS function or class instance
    carries writable state a Lua value of plain data cannot, so refusing
    it is the thinnest honest form.
  - Remains convention (the R3 counterpart): realm intrinsics
    (Object.prototype, Array.prototype, Function.prototype, globalThis)
    are writable globals reachable from any value, like Lua's string
    metatable and globals: writing one is a global write. It vanishes
    when the host freezes the realm's intrinsics (e.g. SES lockdown()).
    These checks verify the structure (prototypes are intrinsics or
    null, every object frozen, fresh copies) and never write an
    intrinsic themselves. A gate's closures, module globals, I/O,
    timers, async work and throwing stay convention as in Lua.
  - A private-field stamp: any code can stamp its own private field on
    any object, frozen ones included (V8 today), keeping a writable
    per-object slot. Because entry, samples and observers get fresh
    identities, such a slot cannot carry state from one propagation to
    the next through an event (check.ownership probes this); within one
    propagation it is gate-held state (convention).
  - Deception by trusted code (the X1 counterpart): a Proxy returned by a
    gate can lie about its contents; the stream reads a returned array
    once. Recorded, not defended.
  - What has no counterpart: Lua's `select("#", …)` probe of emit's
    return count becomes "emit returns undefined" (JS cannot tell
    `return undefined` from no return). Lua's depth probes (1,000 to
    61,000) become 1,000 to 100,000, the depths meaningful for V8.

rejectionProblems(r, want), check.doors(core), check.readonly(core) and
check.ownership(core) run this contract against an implementation
`core = { Event, Gate, Stream }` (Stream is constructed with `new`) and
return an Array of problem strings (empty when it holds). They are what
the stream phases' checks call. No check writes a realm intrinsic.
*/

export const CODES = Object.freeze({
  SISO_SELF_LOOP: true,
  SISO_CYCLE: true,
  SISO_GATE_THREW: true,
  SISO_NOT_EVENT: true,
});

export const DOORS = Object.freeze(["register", "emit", "takeResidue", "sampleHere", "attach", "detach"]);

const REJECTION_FIELDS = Object.freeze({
  code: true, message: true, eventId: true, causeId: true,
  eventType: true, depth: true, cause: true,
});

// The fields a rejection may carry, by code (beyond code and message).
const EXTRA = Object.freeze({ SISO_CYCLE: "depth", SISO_GATE_THREW: "cause" });

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");
const isCode = (c) => typeof c === "string" && hasOwn(CODES, c);

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

function typeName(v) {
  if (v === null) return "null";
  if (Array.isArray(v)) return "array";
  return typeof v;
}

// The realm's intrinsics, read once at load (reads only): every global
// constructor, its prototype, and their prototype chains, plus the
// global namespace objects. A check never writes one of these.
const INTRINSICS = (() => {
  const set = new Set();
  const add = (o) => {
    while (isObject(o) && !set.has(o)) {
      set.add(o);
      o = Object.getPrototypeOf(o);
    }
  };
  for (const name of Object.getOwnPropertyNames(globalThis)) {
    const d = Object.getOwnPropertyDescriptor(globalThis, name);
    if (!d || !("value" in d) || !isObject(d.value)) continue;
    add(d.value);
    if (typeof d.value === "function") {
      const p = Object.getOwnPropertyDescriptor(d.value, "prototype");
      if (p && "value" in p) add(p.value);
    }
  }
  add(Object.getPrototypeOf(function* () {}));
  add(Object.getPrototypeOf(async function () {}));
  add(Object.getPrototypeOf(async function* () {}));
  add(Object.getPrototypeOf([][Symbol.iterator]()));
  return set;
})();

const isIntrinsic = (o) => INTRINSICS.has(o);

/*
rejectionProblems(r, want) → Array of problems.
`want` (optional) pins fields: { code, eventId, causeId, eventType, depth,
cause }; a key whose value is not undefined is compared with ===, and
`want.noDelivery = true` requires eventId, causeId and eventType absent,
`want.entry = true` requires causeId absent.
*/
export function rejectionProblems(r, want) {
  const p = [];
  if (r === null || typeof r !== "object") {
    p.push(`a rejection is an object, got ${typeName(r)}`);
    return p;
  }
  if (Object.prototype.toString.call(r) !== "[object Error]") p.push("a rejection is not an Error");
  let name;
  try { name = r.name; } catch { name = undefined; }
  if (name !== "Rejection") p.push(`a rejection's name is ${str(name)}, not "Rejection"`);
  if (!isCode(r.code)) p.push(`unknown code ${str(r.code)}`);
  if (typeof r.message !== "string") p.push("message is not a string");
  let rendered;
  try { rendered = String(r); } catch (e) { rendered = { threw: e }; }
  if (rendered !== r.message) p.push("String(rejection) is not its message");
  const own = Object.keys(r);
  for (const k of ["code", "message"]) {
    if (!own.includes(k)) p.push(`${k} is not an own enumerable field`);
  }
  for (const k of own) {
    if (!hasOwn(REJECTION_FIELDS, k)) p.push(`unexpected field '${k}'`);
  }
  for (const k of ["eventId", "causeId", "eventType", "depth"]) {
    if (hasOwn(r, k) && r[k] === undefined) p.push(`${k} is present but undefined (absent when it does not apply)`);
  }
  for (const [code, field] of Object.entries(EXTRA)) {
    if (r.code !== code && hasOwn(r, field)) p.push(`${field} is carried only by ${code}`);
  }
  if (r.code === "SISO_CYCLE" && !Number.isInteger(r.depth)) p.push("SISO_CYCLE carries an integer depth");
  if (r.eventId !== undefined && !Number.isInteger(r.eventId)) p.push("eventId is not an integer");
  if (r.causeId !== undefined && !Number.isInteger(r.causeId)) p.push("causeId is not an integer");
  if (want) {
    for (const k of ["code", "eventId", "causeId", "eventType", "depth", "cause"]) {
      if (want[k] !== undefined && r[k] !== want[k]) {
        p.push(`${k}: expected ${str(want[k])}, got ${str(r[k])}`);
      }
    }
    if (want.noDelivery && (r.eventId !== undefined || r.causeId !== undefined || r.eventType !== undefined)) {
      p.push("a refused entry names no delivery");
    }
    if (want.entry && r.causeId !== undefined) p.push("the entry delivery has no cause");
  }
  return p;
}

// ── running the contract against an implementation ──────────────

function add(p, label, list) {
  for (const s of list) p.push(`${label}: ${s}`);
}

function keysOf(t) {
  return Reflect.ownKeys(t).map((k) => String(k)).sort().join(",");
}

// emit and catch: → { settled: true } or { settled: false, err }
function emitCatch(s, e) {
  try {
    s.emit(e);
    return { settled: true };
  } catch (err) {
    return { settled: false, err };
  }
}

// The first problem on an event's object graph, or null: every object
// frozen, data properties only, no function, prototypes intrinsic
// (Object.prototype, Array.prototype for an array) or null. Iterative;
// reads descriptors only, so no getter runs.
function graphProblem(root, label) {
  const seen = new Set();
  const stack = [[root, label]];
  while (stack.length > 0) {
    const [v, path] = stack.pop();
    if (typeof v === "function") return `${path} is a function (state reachable from an event)`;
    if (v === null || typeof v !== "object" || seen.has(v)) continue;
    seen.add(v);
    const proto = Object.getPrototypeOf(v);
    const plainProto = proto === null || proto === Object.prototype || (proto === Array.prototype && Array.isArray(v));
    if (!plainProto) return `${path} has a prototype that is not Object.prototype, Array.prototype or null`;
    if (!Object.isFrozen(v)) return `${path} is not frozen`;
    for (const k of Reflect.ownKeys(v)) {
      const d = Object.getOwnPropertyDescriptor(v, k);
      if (!("value" in d)) return `${path}.${String(k)} is an accessor (a getter can hold state)`;
      stack.push([d.value, `${path}.${String(k)}`]);
    }
  }
  return null;
}

// The first object reachable from `root` by own properties (values and
// accessor functions) and prototypes, short of the realm's intrinsics,
// that is not frozen; or null.
function writableReach(root, label) {
  const seen = new Set();
  const stack = [[root, label]];
  while (stack.length > 0) {
    const [v, path] = stack.pop();
    if (!isObject(v) || seen.has(v) || isIntrinsic(v)) continue;
    seen.add(v);
    if (!Object.isFrozen(v)) return path;
    stack.push([Object.getPrototypeOf(v), `${path}.[[Prototype]]`]);
    for (const k of Reflect.ownKeys(v)) {
      const d = Object.getOwnPropertyDescriptor(v, k);
      const where = `${path}.${String(k)}`;
      if ("value" in d) stack.push([d.value, where]);
      else {
        stack.push([d.get, `${where} (getter)`]);
        stack.push([d.set, `${where} (setter)`]);
      }
    }
  }
  return null;
}

function gate(core, sig, fn, shape) {
  return core.Gate(sig, fn, shape);
}

function doors(core) {
  const p = [];
  const { Event, Stream } = core;
  const fail = (s) => { p.push(s); };
  const guard = (label, fn) => {
    try {
      fn();
    } catch (err) {
      fail(`${label}: raised ${str(err)}`);
    }
  };

  guard("Event", () => {
    const e = Event("t", { x: 1 });
    if (keysOf(e) !== "data,type") fail(`Event fields are ${keysOf(e)}`);
    if (!Object.keys(e).includes("type") || !Object.keys(e).includes("data")) fail("Event's type and data are not enumerable");
    if (e.type !== "t" || e.data.x !== 1) fail("Event does not hold type and data");
    if (Object.getPrototypeOf(e) !== Object.prototype) fail("an event's prototype is not Object.prototype");
    const d = Event("t");
    if (d.data === null || typeof d.data !== "object" || Array.isArray(d.data) || Reflect.ownKeys(d.data).length !== 0) {
      fail("Event data does not default to {}");
    }
    if (keysOf(Event("t", {}, { attempt: 1 })) !== "data,type") fail("an extra argument attached a field");
    const n = new Event("t", { x: 1 });
    if (!Event.is(n) || keysOf(n) !== "data,type" || n.data.x !== 1 || Object.getPrototypeOf(n) !== Object.prototype) {
      fail("new Event(type, data) does not return an event");
    }
    if (Event.is(e) !== true) fail("Event.is(event) is not true");
    if (Event.is({ type: "t", data: {} }) !== false) fail("Event.is accepts a plain object");
    if (Event.is(Object.freeze({ type: "t", data: Object.freeze({}) })) !== false) fail("Event.is accepts a frozen look-alike");
    if (Event.is(Object.create(Object.getPrototypeOf(e))) !== false) fail("Event.is accepts an object made from an event's prototype");
    if (Event.is(new Proxy(e, {})) !== false) fail("Event.is accepts a Proxy around an event");
    if (Event.is(structuredClone(e)) !== false) fail("Event.is accepts a structured clone of an event");
    for (const v of [undefined, null, 0, "t", true]) {
      if (Event.is(v) !== false) fail(`Event.is(${str(v)}) is not false`);
    }
  });

  guard("Gate", () => {
    const fn = () => {};
    const shape = { emits: [], rejects: [] };
    const g = core.Gate("g", fn, shape);
    if (g.signature !== "g" || g.transform !== fn || g.shape !== shape) {
      fail("Gate(signature, transform, shape) does not hold its three fields");
    }
    if (!Object.isFrozen(g)) fail("a gate is not frozen");
    const noop = core.Gate("n");
    if (typeof noop.transform !== "function" || noop.transform(Event("n")) !== undefined) {
      fail("a gate with no transform is not a no-op");
    }
  });

  guard("Stream doors", () => {
    const s = new Stream();
    for (const d of DOORS) {
      if (typeof s[d] !== "function") fail(`stream has no door ${d}`);
    }
    if (Reflect.ownKeys(s).length !== 0) fail(`a stream's own keys are ${keysOf(s)} (its state is reachable past the doors)`);
    if (!Object.isFrozen(s) || Reflect.set(s, "emit", () => {}) || Reflect.defineProperty(s, "extra", { value: 1 })) {
      fail("a stream instance accepts own properties (a door can be shadowed)");
    }
    const sm = s.sampleHere();
    if (keysOf(sm) !== "gateCount,residue") fail(`sampleHere carries ${keysOf(sm)}`);
    if (!Array.isArray(sm.residue)) fail("sampleHere's residue is not an Array");
  });

  guard("register", () => {
    const s = new Stream();
    s.register(gate(core, "a"));
    let accepted = true;
    let err;
    try {
      s.register(gate(core, "a"));
    } catch (e) {
      accepted = false;
      err = e;
    }
    if (accepted) fail("a duplicate signature was accepted");
    else if (!isObject(err) || err.message !== "Signature collision: 'a'") {
      fail(`collision message is ${isObject(err) ? str(err.message) : str(err)}`);
    }
    if (s.sampleHere().gateCount !== 1) fail("gateCount is not the registered count");
  });

  guard("emit and take", () => {
    const s = new Stream();
    s.register(gate(core, "a", (e) => Event("b", { v: e.data.v })));
    if (s.emit(Event("a", { v: 7 })) !== undefined) fail("emit returned a value");
    const smp = s.sampleHere();
    if (smp.residue.length !== 1 || smp.residue[0].type !== "b") fail("residue after a settled chain");
    smp.residue.pop();
    if (s.sampleHere().residue.length !== 1) fail("a sample's list reached the stream");
    const taken = s.takeResidue();
    if (!Array.isArray(taken) || taken.length !== 1 || taken[0].data.v !== 7) fail("takeResidue did not return the residue");
    if (s.sampleHere().residue.length !== 0) fail("the stream still holds taken residue");
    if (Array.isArray(taken)) taken.push(Event("pushed"));
    if (s.sampleHere().residue.length !== 0) fail("a write to the taken list reached the stream");
  });

  guard("returns", () => {
    const s = new Stream();
    s.register(gate(core, "one", () => Event("x")));
    s.register(gate(core, "many", () => [Event("x"), Event("y")]));
    s.register(gate(core, "none", () => undefined));
    s.register(gate(core, "null", () => null));
    s.register(gate(core, "empty", () => []));
    s.emit(Event("one")); s.emit(Event("many")); s.emit(Event("none")); s.emit(Event("null")); s.emit(Event("empty"));
    if (s.takeResidue().length !== 3) fail("undefined / null / Event / array returns do not settle as declared");
    const c = new Stream();
    c.register(gate(core, "carry", (e) => e.data.outs));
    c.emit(Event("carry", { outs: [Event("x"), Event("y")] }));
    if (c.takeResidue().length !== 2) fail("a frozen array of Events read from the event's data is not judged as an array");
  });

  const rej = (label, s, entry, want) => {
    const before = s.sampleHere().residue.length;
    const r = emitCatch(s, entry);
    if (r.settled) {
      fail(`${label}: settled, expected ${want.code}`);
      return;
    }
    add(p, label, rejectionProblems(r.err, want));
    if (s.sampleHere().residue.length !== before) fail(`${label}: a rejection committed residue`);
  };

  guard("rejections", () => {
    const s = new Stream();
    s.emit(Event("earlier"));
    s.register(gate(core, "loop", () => [Event("other"), Event("loop")]));
    rej("self-loop", s, Event("loop"), { code: "SISO_SELF_LOOP", eventId: 1, eventType: "loop", entry: true });

    const c = new Stream();
    c.register(gate(core, "a", () => Event("b")));
    c.register(gate(core, "b", () => Event("a")));
    rej("cycle", c, Event("a"), { code: "SISO_CYCLE", eventId: 3, causeId: 2, eventType: "a", depth: 3 });

    const t = new Stream();
    const thrown = ["the gate's own value"];
    t.register(gate(core, "a", () => Event("b")));
    t.register(gate(core, "b", () => { throw thrown; }));
    rej("gate threw", t, Event("a"), { code: "SISO_GATE_THREW", eventId: 2, causeId: 1, eventType: "b", cause: thrown });
    // ids are per propagation: the same entry names the same deliveries again
    rej("gate threw, again", t, Event("a"), { code: "SISO_GATE_THREW", eventId: 2, causeId: 1 });
    const ts = new Stream();
    ts.register(gate(core, "s", () => { throw "a thrown string"; }));
    rej("gate threw a string", ts, Event("s"), { code: "SISO_GATE_THREW", eventId: 1, cause: "a thrown string", entry: true });

    const n = new Stream();
    rej("entry not an Event", n, { type: "x", data: {} }, { code: "SISO_NOT_EVENT", noDelivery: true });
    rej("entry undefined", n, undefined, { code: "SISO_NOT_EVENT", noDelivery: true });
    rej("entry null", n, null, { code: "SISO_NOT_EVENT", noDelivery: true });
    rej("entry a Proxy around an Event", n, new Proxy(Event("x"), {}), { code: "SISO_NOT_EVENT", noDelivery: true });
    n.register(gate(core, "bad", () => [Event("ok"), { type: "fake" }]));
    rej("output not an Event", n, Event("bad"), { code: "SISO_NOT_EVENT", eventId: 1, eventType: "bad" });
    // eslint-disable-next-line no-sparse-arrays
    n.register(gate(core, "holes", () => [Event("ok"), , Event("ok")]));
    rej("output array with a hole", n, Event("holes"), { code: "SISO_NOT_EVENT", eventId: 1 });
    n.register(gate(core, "named", () => Object.assign([Event("ok")], { extra: 1 })));
    rej("output array with a named key", n, Event("named"), { code: "SISO_NOT_EVENT", eventId: 1 });
    n.register(gate(core, "undef", () => [Event("ok"), undefined]));
    rej("output array with an undefined member", n, Event("undef"), { code: "SISO_NOT_EVENT", eventId: 1 });
    n.register(gate(core, "num", () => 42));
    rej("output a number", n, Event("num"), { code: "SISO_NOT_EVENT" });
    n.register(gate(core, "f", () => false));
    rej("output false", n, Event("f"), { code: "SISO_NOT_EVENT" });
    n.register(gate(core, "zero", () => 0));
    rej("output 0", n, Event("zero"), { code: "SISO_NOT_EVENT" });
    n.register(gate(core, "text", () => ""));
    rej("output an empty string", n, Event("text"), { code: "SISO_NOT_EVENT" });
    n.register(gate(core, "async", async () => Event("late")));
    rej("output a Promise (an async gate)", n, Event("async"), { code: "SISO_NOT_EVENT", eventId: 1 });

    const u = new Stream();
    u.register(gate(core, "boom", () => { throw new Error("x"); }));
    emitCatch(u, Event("boom"));
    u.emit(Event("fine"));
    if (u.takeResidue().length !== 1) fail("the stream is not usable after a rejection");
  });

  return p;
}

function readonly(core) {
  const p = [];
  const { Event, Stream } = core;
  const fail = (s) => { p.push(s); };
  const refuses = (label, fn) => {
    try {
      fn();
    } catch {
      return;
    }
    fail(`${label} was accepted`);
  };
  try {
    // read-only from construction: before any stream sees it
    const orig = { v: 1, list: [1] };
    const born = Event("a", orig);
    refuses("writing a field of a new event", () => { born.type = "z"; });
    refuses("adding a field to a new event", () => { born.extra = 1; });
    refuses("writing a new event's data", () => { born.data.v = 2; });
    refuses("writing into a new event's nested data", () => { born.data.list[0] = 0; });
    refuses("pushing onto a new event's nested array", () => { born.data.list.push(2); });
    refuses("writing a new event's default data", () => { Event("t").data.x = 1; });
    const spare = Event("p", { o: { k: 1 }, l: [1] });
    refuses("defineProperty on a new event", () => { Object.defineProperty(spare, "extra", { value: 1 }); });
    refuses("defineProperty on a new event's data", () => { Object.defineProperty(spare.data, "extra", { value: 1 }); });
    refuses("delete on a new event's nested data", () => { delete spare.data.o.k; });
    refuses("delete on a new event's field", () => { delete spare.type; });
    refuses("setPrototypeOf on a new event", () => { Object.setPrototypeOf(spare, {}); });
    refuses("setPrototypeOf on a new event's nested data", () => { Object.setPrototypeOf(spare.data.o, {}); });
    refuses("setting a new event's nested array's length", () => { spare.data.l.length = 0; });
    if (keysOf(born) !== "data,type") fail(`a new event's fields are ${keysOf(born)}`);
    if (Object.isFrozen(orig) || Object.isFrozen(orig.list)) fail("Event froze the caller's own object in place");
    try {
      orig.v = 2;
      orig.list[0] = 9;
      orig.late = true;
    } catch {
      // already reported: frozen in place
    }
    if (born.data.v !== 1 || born.data.list[0] !== 1 || born.data.late !== undefined) {
      fail("a write to the object passed to Event reached the event");
    }
    if (born.data === orig || born.data.list === orig.list) fail("an event holds the object passed to Event (a new event's data is not its own)");
    const inner = Event("i");
    const callerFrozen = Object.freeze({ k: 1 });
    const carried = Event("o", { inner, d: born.data, n: null, f: callerFrozen });
    if (carried.data.inner !== inner) fail("an event carried in data lost its identity");
    if (carried.data.d !== born.data) fail("read-only data passed on lost its identity");
    if (carried.data.n !== null) fail("null in new data is not null");
    if (carried.data.f === callerFrozen) fail("a caller's own frozen object was kept in the event, not copied");
    const shared = { s: 1 };
    const cyc = { shared, again: shared };
    cyc.self = cyc;
    const sc = Event("c", cyc);
    if (sc.data.shared !== sc.data.again) fail("sharing inside the data was not preserved");
    if (sc.data.self !== sc.data) fail("a cycle inside the data was not preserved");
    if (!Event.is(born)) fail("Event.is does not recognise a new event");

    // stateless through the event
    if (Object.getPrototypeOf(born) !== Object.prototype) {
      fail("an event's prototype is not Object.prototype (a shared object reachable from every event)");
    }
    for (const [label, e] of [["a new event", born], ["an event carrying events and data", carried], ["an event with shared data", sc]]) {
      const why = graphProblem(e, "event");
      if (why) fail(`${label}: ${why}`);
    }
    class Opaque { constructor() { this.v = 1; } }
    for (const [label, value] of [
      ["a function", () => 1],
      ["a Map", new Map()],
      ["a Date", new Date(0)],
      ["a class instance", new Opaque()],
      ["a sparse array", [1, , 3]], // eslint-disable-line no-sparse-arrays
      ["a boxed primitive", Object(1)],
      ["a symbol key", { [Symbol("k")]: 1 }],
      ["an accessor", Object.defineProperty({}, "g", { get: () => 1, enumerable: true })],
      ["a non-enumerable property", Object.defineProperty({}, "h", { value: 1, enumerable: false })],
    ]) {
      let threw = false;
      let err;
      try {
        Event("t", { x: value });
      } catch (e) {
        threw = true;
        err = e;
      }
      if (!threw) fail(`Event accepted ${label} in data (not plain data)`);
      else if (!(err instanceof TypeError) || !str(err.message).includes("not plain data")) {
        fail(`Event refused ${label} with ${str(err)}, not a TypeError naming "not plain data"`);
      }
    }
    for (const [label, root] of [["Event", core.Event], ["Gate", core.Gate], ["Stream", core.Stream]]) {
      const where = writableReach(root, label);
      if (where) fail(`${where} is not frozen (state anyone can write, reachable from the core's exports)`);
    }
    // a gate holds its caller's transform and shape as given; the gate
    // object, its prototype and the core's default transform are frozen
    const g = core.Gate("g");
    if (!Object.isFrozen(g)) fail("a gate is not frozen");
    const gp = writableReach(Object.getPrototypeOf(g), "a gate's prototype");
    if (gp) fail(`${gp} is not frozen`);
    const gt = writableReach(g.transform, "a gate's default transform");
    if (gt) fail(`${gt} is not frozen (one function every gate without a transform shares)`);
    const rs = new Stream();
    rs.register(core.Gate("r", () => { throw new Error("r"); }));
    const caught = emitCatch(rs, Event("r"));
    if (!caught.settled && isObject(caught.err)) {
      const where = writableReach(Object.getPrototypeOf(caught.err), "a rejection's prototype");
      if (where) fail(`${where} is not frozen: what a holder of one rejection writes there reaches another`);
    }
    // a gate that counts through anything writable its event hands out,
    // short of the realm's intrinsics (never written)
    const countRun = () => {
      const cs = new Stream();
      cs.register(core.Gate("count", (e) => {
        let n = 0;
        for (const o of [e, e.data]) {
          const proto = Object.getPrototypeOf(o);
          if (!isObject(proto) || isIntrinsic(proto) || Object.isFrozen(proto)) continue;
          try {
            proto.n = (proto.n || 0) + 1;
            n += proto.n;
          } catch {
            // refused: nothing counted
          }
        }
        return Event("counted", { n });
      }));
      cs.emit(Event("count", {}));
      return cs.takeResidue()[0].data.n;
    };
    countRun();
    if (countRun() !== 0) {
      fail("a gate carried a count from one stream to another through what its event's prototypes hand out");
    }

    let seen;
    const s = new Stream();
    s.register(core.Gate("a", (e) => {
      seen = e;
      refuses("a gate writing its event's data", () => { e.data.v = 2; });
      refuses("a gate writing into nested data", () => { e.data.list[0] = 0; });
      if (e.data.list !== e.data.list) fail("two reads of one field give different objects");
      return Event("b", { list: [1, 2], v: e.data.v, n: null });
    }));
    s.emit(Event("a", { v: 1, list: [1, 2] }));
    refuses("writing a field of an event", () => { seen.type = "z"; });
    if (!Event.is(seen)) fail("Event.is does not recognise a frozen event");
    const gwhy = graphProblem(seen, "event");
    if (gwhy) fail(`an event a gate is given: ${gwhy}`);
    const r = s.sampleHere().residue[0];
    if (r.data.n !== null) fail("null lost its identity");
    if (!Event.is(r)) fail("Event.is does not recognise a sampled event");
    const swhy = graphProblem(r, "event");
    if (swhy) fail(`a sampled event: ${swhy}`);
    refuses("writing a sampled event's data", () => { r.data.v = 99; });
    refuses("writing into a sampled event's nested data", () => { r.data.list[0] = 9; });
    if (s.sampleHere().residue[0].data.v !== 1) fail("a sample's write reached the stream");
    const l = r.data.list;
    if (l.length !== 2 || l[1] !== 2) fail("length and indexing do not read a frozen array");
    let n = 0;
    for (const v of l) n += v;
    if (n !== 3) fail("for…of does not read a frozen array");
    if ([...l].length !== 2) fail("spread does not read a frozen array");
    if (Object.keys(r.data).length !== 3) fail("Object.keys does not read frozen data");
    let inKeys = 0;
    for (const k in r.data) if (hasOwn(r.data, k)) inKeys += 1;
    if (inKeys !== 3) fail("for…in does not read frozen data");
    if (!Array.isArray(l)) fail("a frozen array is not an Array");
    const t = s.takeResidue()[0];
    refuses("writing a taken event's data", () => { t.data.v = 5; });
    const twhy = graphProblem(t, "event");
    if (twhy) fail(`a taken event: ${twhy}`);
  } catch (err) {
    fail(`raised ${str(err)}`);
  }
  return p;
}

function ownership(core) {
  const p = [];
  const { Event, Stream } = core;
  const fail = (s) => { p.push(s); };
  // A private-field stamp, this call's own: the substrate's attempt at
  // Lua's rawset (a writable slot on any object, frozen ones included).
  class Base { constructor(o) { return o; } }
  class Stamp extends Base {
    #mark = true;
    static put(o) {
      try {
        if (!(#mark in o)) new Stamp(o);
        return true;
      } catch {
        return false;
      }
    }
    static on(o) { return isObject(o) && #mark in o; }
  }
  class Counter extends Base {
    #n = 0;
    static bump(o) {
      try {
        if (!(#n in o)) new Counter(o);
        o.#n += 1;
        return o.#n;
      } catch {
        return 0;
      }
    }
  }
  // the substrate's own attempt at a write: Reflect.set and
  // Reflect.defineProperty answer false on a frozen object, never throw
  const attempt = (o, k, v) => {
    Reflect.set(o, k, v);
    Reflect.defineProperty(o, `${String(k)}Extra`, { value: v, enumerable: true });
    Stamp.put(o);
  };
  try {
    const s = new Stream();
    s.emit(Event("x", { v: 1, n: [1] }));
    const a = s.sampleHere().residue[0];
    attempt(a, "type", "z");
    attempt(a.data, "v", 7);
    attempt(a.data.n, 0, 9);
    const b = s.sampleHere().residue[0];
    if (b.type !== "x" || Stamp.on(b)) fail("a write or stamp on a sampled event reached the stream");
    if (b.data.v !== 1 || Stamp.on(b.data)) fail("a write or stamp on a sampled event's data reached the stream");
    if (b.data.n[0] !== 1 || Stamp.on(b.data.n)) fail("a write or stamp on a sampled event's nested data reached the stream");
    try {
      b.data.v = 2;
      fail("a sampled copy accepts assignment");
    } catch {
      // refused, as it should be
    }
    if (!Event.is(b)) fail("Event.is does not recognise a sampled copy");
    const t = s.takeResidue()[0];
    if (t.type !== "x" || t.data.v !== 1 || t.data.n[0] !== 1 || Stamp.on(t) || Stamp.on(t.data) || Stamp.on(t.data.n)) {
      fail("a write or stamp on a sample reached the residue later taken");
    }

    // an entry too deep to copy is refused as a rejection, never thrown
    // raw (nothing else is thrown): every depth Event accepts
    for (const depth of [1000, 10000, 50000, 100000]) {
      const deep = {};
      let cur = deep;
      for (let i = 0; i < depth; i += 1) {
        cur.x = {};
        cur = cur.x;
      }
      let built;
      try {
        built = Event("deep", deep);
      } catch {
        continue;
      }
      const ds = new Stream();
      try {
        ds.emit(built);
      } catch (raisedE) {
        if (!(isObject(raisedE) && isCode(raisedE.code))) {
          fail(`an entry ${depth} deep made emit throw ${str(raisedE).slice(0, 60)}, not a rejection`);
        }
      }
    }

    // entry takes the stream's own copy
    const h = new Stream();
    const held = Event("x", { v: 1, n: [1] });
    h.emit(held);
    attempt(held, "type", "z");
    attempt(held.data, "v", 7);
    attempt(held.data.n, 0, 9);
    // a take hands over the stream's own objects, so it shows what it held
    const back = h.takeResidue()[0];
    if (back === held || back.data === held.data || back.data.n === held.data.n || back.type !== "x" ||
        back.data.v !== 1 || back.data.n[0] !== 1 || Stamp.on(back) || Stamp.on(back.data) || Stamp.on(back.data.n)) {
      fail("the residue holds the entering code's own event (what it does to the event reached the residue)");
    }
    h.emit(back);
    attempt(back.data, "v", 8);
    const again = h.takeResidue()[0];
    if (again === back || again.data === back.data || again.data.v !== 1 || Stamp.on(again.data)) {
      fail("taken residue entered again is the residue (not the stream's own copy)");
    }
    // a gate that counts on its input through a private-field stamp sees
    // 1 on each of two emits of the same held event
    const c = new Stream();
    c.register(core.Gate("count", (e) => Event("counted", { n: Counter.bump(e) + Counter.bump(e.data) })));
    const same = Event("count", { k: 1 });
    c.emit(same);
    c.emit(same);
    const counts = c.takeResidue().map((e) => e.data.n);
    if (counts.length !== 2 || counts.some((n) => n > 2)) {
      fail(`a gate carried a count from one emit to the next through a stamp on the entering code's own event (counts ${counts.join(",")})`);
    }
  } catch (err) {
    fail(`raised ${str(err)}`);
  }
  return p;
}

export const check = Object.freeze({ doors, readonly, ownership });
