// stream-doors.test.mjs — the core's doors against js-core-api and
// js-observer-seam. Port of lua/sisoTests/stream-doors.test.lua.
// Runs both contracts' checks on the real core, then pins what the checks
// leave open. The gates below are fixtures for the stream, not project
// gates.
//
// Adapted to the substrate (js-core-api SUBSTRATE), case by case:
//   - "a gate's raised value is kept exactly, even nil": Lua's nil is
//     JS's undefined (and null, a value of its own, is pinned too). A
//     field that would be undefined is absent, so `cause` is absent when
//     the gate threw undefined.
//   - "SISO_NOT_EVENT at the entry": Lua's entries (a string, numbers,
//     booleans, tables, a Gate) are kept; JS adds its own forgeries of an
//     event: undefined, null, a Proxy around an Event, a structured clone
//     and an object made from an event's prototype.
//   - "SISO_NOT_EVENT for every return": Lua's list shapes map to arrays
//     (a hole, a named key, an array whose first index is a hole for "a
//     list starting at 2"); JS adds a Promise (an async gate), a Proxy
//     around an Event and a frozen look-alike.
//   - "ids restart at 1": `s.eventCount` is still pinned absent (private
//     fields: nothing is on the stream at all).
//   - "attach takes a function; misuse raises a string": Lua raises a
//     string; JS throws an Error (a TypeError for a non-function), as the
//     contract says "throws".
// Added for X1 (doc/SPEC.md), which the port applies: the stream queues
// exactly the members its judgement read (an array read once), and a
// thrown value's message is read raw and under protection.
// Added for js-core-api 13.1.0 (no Lua counterpart: Lua's R5): a stream
// instance is frozen (no own property, no shadowed door), and the doors
// call no rewritable array, Map or iterator method on private state.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as API from "../code/contracts/core_api.mjs";
import * as SEAM from "../code/contracts/observer_seam.mjs";
import { Event, Gate, Stream, Rejection } from "../code/core/index.mjs";

const core = { Event, Gate, Stream };
const { test, assert, assertEqual } = H;

const joined = (list) => list.join("; ");
const keysOf = (t) => Object.keys(t).sort().join(",");
const emitCatch = (s, e) => {
  try {
    s.emit(e);
    return undefined;
  } catch (err) {
    return err;
  }
};
const noProblems = (list, label) => assert(list.length === 0, `${label ? `${label}: ` : ""}${joined(list)}`);
// a stream with one fixture gate `sig` returning `ret`
const oneGate = (sig, ret) => {
  const s = new Stream();
  s.register(Gate(sig, () => ret));
  return s;
};

// ── the contracts' own checks ──────────────────

test("core-api: check.doors holds on the real core", () => {
  noProblems(API.check.doors(core));
});

test("observer-seam: check.seam holds on the real core", () => {
  noProblems(SEAM.check.seam(core));
});

// ── the stream instance (13.1.0) ───────────────

test("JS 13.1.0: a stream instance is frozen: no own property can be added, no door shadowed", () => {
  const s = new Stream();
  assert(Object.isFrozen(s), "a new stream is frozen");
  assertEqual(Reflect.ownKeys(s).length, 0, "a stream has no own property");
  assertEqual(Reflect.set(s, "emit", () => {}), false, "assigning a door is refused");
  assertEqual(Reflect.defineProperty(s, "emit", { value: () => {} }), false, "defining a door is refused");
  assertEqual(Reflect.defineProperty(s, "extra", { value: 1 }), false, "adding a field is refused");
  assertEqual(Reflect.setPrototypeOf(s, {}), false, "changing its prototype is refused");
  let threw = false;
  try {
    s.takeResidue = () => [Event("forged")];
  } catch (e) {
    threw = e instanceof TypeError;
  }
  assert(threw, "a strict assignment to a door throws");
  // the doors still work on the frozen instance (private fields)
  s.register(Gate("a", () => Event("b")));
  s.emit(Event("a"));
  assertEqual(s.takeResidue().map((e) => e.type).join(","), "b");
  assertEqual(s.sampleHere().gateCount, 1);
});

test("JS 13.1.0: the doors call no rewritable intrinsic on the stream's private state", () => {
  // SUBSTRATE: rewriting an intrinsic is a global write by trusted code
  // and is not defended; where it costs nothing the core captures what
  // it calls at load. This probe swaps the array, Map and iterator
  // methods a door would most naturally call, records every receiver,
  // and restores them before asserting.
  const patched = [
    [Array.prototype, ["push", "pop", "map", "slice", "splice", "includes", "indexOf", "forEach", "concat", Symbol.iterator]],
    [Map.prototype, ["get", "set", "has", "delete", "forEach", Symbol.iterator]],
  ];
  const saved = [];
  const receivers = [];
  let nReceivers = 0;
  let recording = false;
  let caught;
  const apply = Reflect.apply;
  for (const [proto, names] of patched) {
    for (const k of names) {
      const d = Object.getOwnPropertyDescriptor(proto, k);
      saved.push([proto, k, d]);
      const original = d.value;
      // the recorder itself calls no patched method (no spread, no push)
      const recorder = function () {
        if (recording) {
          receivers[nReceivers] = this;
          nReceivers += 1;
        }
        return apply(original, this, arguments);
      };
      Object.defineProperty(proto, k, { __proto__: null, value: recorder, writable: d.writable, enumerable: d.enumerable, configurable: d.configurable });
    }
  }
  recording = true;
  try {
    const s = new Stream();
    s.register(Gate("fx_a", () => [Event("fx_b", { l: [1] }), Event("fx_c")]));
    s.register(Gate("fx_b", () => Event("fx_d")));
    s.register(Gate("fx_bad", () => { throw new Error("x"); }));
    const f = () => {};
    s.attach(f);
    s.attach(() => {});
    s.emit(Event("fx_a", { k: [1, 2] }));
    try { s.emit(Event("fx_bad")); } catch { /* the rejection */ }
    try { s.emit("not an event"); } catch { /* the refusal */ }
    s.sampleHere();
    s.takeResidue();
    s.detach(f);
  } catch (e) {
    caught = e;
  } finally {
    recording = false;
    for (let i = 0; i < saved.length; i += 1) Object.defineProperty(saved[i][0], saved[i][1], saved[i][2]);
  }
  assertEqual(caught, undefined, "the doors ran");
  assertEqual(nReceivers, 0, `the doors called a rewritten intrinsic ${nReceivers} times`);
});

// ── rejections (core/rejection.mjs) ────────────

test("rejection: each builder carries exactly its code's fields", () => {
  const d = { id: 3, causeId: 2, type: "t", depth: 3 };
  const cases = [
    [Rejection.selfLoop(d, "t"), "causeId,code,eventId,eventType,message"],
    [Rejection.cycle(d, 2), "causeId,code,depth,eventId,eventType,message"],
    [Rejection.gateThrew(d, "t", "x"), "cause,causeId,code,eventId,eventType,message"],
    [Rejection.notEvent(d, "t", "a string"), "causeId,code,eventId,eventType,message"],
    [Rejection.notEvent(undefined, undefined, "undefined"), "code,message"],
  ];
  cases.forEach(([r, keys], i) => {
    assertEqual(keysOf(r), keys, `case ${i + 1}`);
    noProblems(API.rejectionProblems(r), `case ${i + 1}`);
    assertEqual(String(r), r.message, `case ${i + 1} String`);
    assert(Rejection.is(r), `case ${i + 1} Rejection.is`);
  });
  assert(!Rejection.is({ code: "SISO_CYCLE", message: "m" }), "a plain object is not a rejection");
  assert(!Rejection.is(Object.assign(new Error("m"), { code: "SISO_CYCLE" })), "an Error is not a rejection");
  let refused = false;
  try {
    new Rejection("SISO_CYCLE", "m"); // eslint-disable-line no-new
  } catch {
    refused = true;
  }
  assert(refused, "only the stream builds a rejection");
});

test("rejection: a gate's raised value is kept exactly, even nil", () => {
  const s = new Stream();
  s.register(Gate("raises_nil", () => { throw undefined; })); // eslint-disable-line no-throw-literal
  const r = emitCatch(s, Event("raises_nil"));
  assertEqual(r.code, "SISO_GATE_THREW");
  assertEqual(r.cause, undefined);
  assert(!Object.prototype.hasOwnProperty.call(r, "cause"), "an undefined cause is absent, not present-but-undefined");
  noProblems(API.rejectionProblems(r));
  const t = new Stream();
  t.register(Gate("raises_null", () => { throw null; })); // eslint-disable-line no-throw-literal
  const q = emitCatch(t, Event("raises_null"));
  assertEqual(q.cause, null, "null is a value: kept");
  noProblems(API.rejectionProblems(q, { code: "SISO_GATE_THREW" }));
});

// ── SISO_SELF_LOOP carries causeId ─────────────

test("SISO_SELF_LOOP names the returning delivery and its cause", () => {
  const s = new Stream();
  s.register(Gate("fx_head", () => [Event("fx_side"), Event("fx_loop")]));
  s.register(Gate("fx_loop", () => Event("fx_loop")));
  const r = emitCatch(s, Event("fx_head"));
  noProblems(API.rejectionProblems(r, { code: "SISO_SELF_LOOP", eventId: 3, causeId: 1, eventType: "fx_loop" }));
});

// ── SISO_NOT_EVENT: the entry door ─────────────

test("SISO_NOT_EVENT at the entry: nothing is delivered", () => {
  let delivered = false;
  const s = new Stream();
  s.register(Gate("x", () => { delivered = true; }));
  const e = Event("x");
  const entries = [
    ["a string", "x"], ["a number", 42], ["false", false], ["true", true], ["an empty object", {}],
    ["a look-alike", { type: "x", data: {} }], ["a Gate", Gate("x")],
    ["undefined", undefined], ["null", null], ["a Proxy around an Event", new Proxy(e, {})],
    ["a structured clone of an Event", structuredClone(e)], ["an object made from an event's prototype", Object.create(Object.getPrototypeOf(e))],
    ["a frozen look-alike", Object.freeze({ type: "x", data: Object.freeze({}) })],
  ];
  for (const [label, v] of entries) {
    const r = emitCatch(s, v);
    noProblems(API.rejectionProblems(r, { code: "SISO_NOT_EVENT", noDelivery: true }), label);
  }
  assert(!delivered, "a refused entry reached a gate");
  assertEqual(s.sampleHere().residue.length, 0);
});

// ── SISO_NOT_EVENT: gate returns ───────────────

test("SISO_NOT_EVENT for every return that is not nil, an Event or a list", () => {
  const cases = [
    ["false", false],
    ["true", true],
    ["a number", 0],
    ["a string", "fx_out"],
    ["a function", () => {}],
    ["a plain object shaped like an event", { type: "fx_out", data: {} }],
    ["a list with a named key", Object.assign([Event("fx_out")], { extra: Event("fx_out") })],
    ["a list with a hole", [Event("fx_out"), , Event("fx_out")]], // eslint-disable-line no-sparse-arrays
    ["a list starting at 2", [, Event("fx_out")]], // eslint-disable-line no-sparse-arrays
    ["a list with a plain-object member", [Event("fx_out"), { type: "fx_out", data: {} }]],
    ["a list with a string member", [Event("fx_out"), "fx_out"]],
    ["a list with a false member", [false]],
    ["a list of lists", [[Event("fx_out")]]],
    ["a Promise (an async gate)", Promise.resolve(Event("fx_out"))],
    ["a Proxy around an Event", new Proxy(Event("fx_out"), {})],
    ["a list with a Proxy member", [new Proxy(Event("fx_out"), {})]],
    ["a frozen look-alike", Object.freeze({ type: "fx_out", data: Object.freeze({}) })],
  ];
  for (const [label, ret] of cases) {
    const s = new Stream();
    s.emit(Event("before"));
    const before = s.sampleHere().residue[0];
    s.register(Gate("fx_feed", () => Event("fx_bad")));
    s.register(Gate("fx_bad", () => ret));
    const r = emitCatch(s, Event("fx_feed"));
    noProblems(API.rejectionProblems(r, { code: "SISO_NOT_EVENT", eventId: 2, causeId: 1, eventType: "fx_bad" }), label);
    const residue = s.sampleHere().residue;
    // 12.0.1: OWNERSHIP — a sample is the receiver's own copy, so compare values
    assert(residue.length === 1 && residue[0].type === before.type && keysOf(residue[0].data) === keysOf(before.data),
      `${label}: the residue changed`);
  }
});

test("accepted returns: nil, nothing, an Event, an empty list, a list", () => {
  const cases = [
    ["undefined", undefined, ""],
    ["null", null, ""],
    ["an Event", Event("fx_a"), "fx_a"],
    ["an empty list", [], ""],
    ["a list", [Event("fx_a"), Event("fx_b")], "fx_a,fx_b"],
    ["a frozen list", Object.freeze([Event("fx_a"), Event("fx_b")]), "fx_a,fx_b"],
  ];
  for (const [label, ret, want] of cases) {
    const s = oneGate("fx_g", ret);
    s.emit(Event("fx_g"));
    assertEqual(s.takeResidue().map((e) => e.type).sort().join(","), want, label);
  }
  const s = new Stream();
  s.register(Gate("fx_silent", () => {}));
  s.emit(Event("fx_silent"));
  assertEqual(s.takeResidue().length, 0, "nothing returned");
});

test("a list is judged in list order: the first bad member decides", () => {
  const r1 = emitCatch(oneGate("fx_g", [Event("fx_ok"), 42, Event("fx_g")]), Event("fx_g"));
  assertEqual(r1.code, "SISO_NOT_EVENT", "non-Event before the self-loop");
  const r2 = emitCatch(oneGate("fx_g", [Event("fx_ok"), Event("fx_g"), 42]), Event("fx_g"));
  assertEqual(r2.code, "SISO_SELF_LOOP", "self-loop before the non-Event");
  const r3 = emitCatch(oneGate("fx_g", Object.assign([Event("fx_g")], { extra: 1 })), Event("fx_g"));
  assertEqual(r3.code, "SISO_SELF_LOOP", "a member's self-loop before a named key");
});

test("X1: the stream queues exactly the members it judged (a returned array is read once)", () => {
  // a Proxy that answers a different member on each read: the deception
  // by trusted code SUBSTRATE records; the stream reads each member once
  // and carries what it judged
  const reads = new Map();
  const target = [Event("fx_first")];
  const lying = new Proxy(target, {
    get(t, k, r) {
      if (k === "0") {
        const n = (reads.get(k) || 0) + 1;
        reads.set(k, n);
        return n === 1 ? Event("fx_first") : Event("fx_g");
      }
      return Reflect.get(t, k, r);
    },
  });
  const s = oneGate("fx_g", lying);
  const r = emitCatch(s, Event("fx_g"));
  assertEqual(r, undefined, "the member judged is the member carried, so it settled");
  assertEqual(reads.get("0"), 1, "member 0 was read once");
  assertEqual(s.takeResidue().map((e) => e.type).join(","), "fx_first");
  // an array whose reading throws is judged, never thrown raw
  const broken = new Proxy([Event("fx_x")], { ownKeys() { throw new Error("trap"); } });
  const b = emitCatch(oneGate("fx_g", broken), Event("fx_g"));
  noProblems(API.rejectionProblems(b, { code: "SISO_NOT_EVENT", eventId: 1 }), "a throwing trap");
});

test("X1: a thrown value's message is read raw and under protection", () => {
  const cases = [
    ["a message getter that throws", Object.defineProperty({}, "message", { get() { throw new Error("getter ran"); } })],
    ["an inherited message", Object.create({ message: "inherited" })],
    ["a Proxy whose traps throw", new Proxy({}, { getOwnPropertyDescriptor() { throw new Error("trap"); }, get() { throw new Error("trap"); } })],
    ["a symbol", Symbol("s")],
    ["an object whose toString throws", { toString() { throw new Error("toString ran"); } }],
  ];
  for (const [label, thrown] of cases) {
    const s = new Stream();
    s.register(Gate("fx_t", () => { throw thrown; }));
    const r = emitCatch(s, Event("fx_t"));
    noProblems(API.rejectionProblems(r, { code: "SISO_GATE_THREW", eventId: 1 }), label);
    assert(r.cause === thrown, `${label}: the cause is what the gate threw`);
    assert(!r.message.includes("ran") && !r.message.includes("inherited"), `${label}: the message ran or inherited: ${r.message}`);
  }
});

// ── ids are per propagation ────────────────────

test("ids restart at 1 in each propagation, rejected ones included", () => {
  const s = new Stream();
  s.register(Gate("fx_a", () => Event("fx_b")));
  s.register(Gate("fx_b", () => { throw new Error("boom"); }));
  const first = emitCatch(s, Event("fx_a"));
  s.emit(Event("other"));
  s.emit(Event("other"));
  const again = emitCatch(s, Event("fx_a"));
  assertEqual(first.eventId, 2);
  assertEqual(again.eventId, 2, "nothing counted across propagations");
  assertEqual(s.eventCount, undefined, "no counter on the stream");
  assertEqual(Reflect.ownKeys(s).length, 0, "nothing on the stream at all");
});

// ── a rejected propagation leaves the stream as it was ──

test("a rejection leaves residue, gates and observers exactly as before", () => {
  const s = new Stream();
  s.emit(Event("kept", { v: 1 }));
  const before = s.sampleHere().residue;
  s.register(Gate("fx_root", () => [Event("fx_leftover"), Event("fx_x")]));
  s.register(Gate("fx_x", () => Event("fx_y")));
  s.register(Gate("fx_y", () => Event("fx_x")));
  const batches = [];
  s.attach((b) => { batches.push(b); });
  const r = emitCatch(s, Event("fx_root"));
  assertEqual(r.code, "SISO_CYCLE");
  const after = s.sampleHere();
  assertEqual(after.residue.length, 1);
  // 12.0.1: OWNERSHIP — a sample is the receiver's own copy, so compare values
  assert(after.residue[0].type === before[0].type && after.residue[0].data.v === before[0].data.v &&
    keysOf(after.residue[0].data) === keysOf(before[0].data), "the same residue event");
  assertEqual(after.gateCount, 3);
  s.emit(Event("next"));
  assertEqual(batches.length, 2, "the observer stays attached");
  assertEqual(batches[1].records[0].eventId, 1);
});

// ── the observe door ───────────────────────────

test("observer: records carry ids, causes, types, claims and the data received", () => {
  const s = new Stream();
  let given;
  s.register(Gate("fx_root", (e) => { given = e.data; return [Event("fx_mid"), Event("fx_out", { k: 2 })]; }));
  s.register(Gate("fx_mid", () => Event("fx_end")));
  let b;
  s.attach((batch) => { b = batch; });
  s.emit(Event("fx_root", { k: 1 }));
  noProblems(SEAM.batchProblems(b));
  assertEqual(keysOf(b), "outcome,records");
  const shown = b.records.map((r) => `${r.eventId}:${String(r.causeId)}:${r.eventType}:${String(r.claimed)}`);
  assertEqual(shown.join(" "), "1:undefined:fx_root:true 2:1:fx_mid:true 3:1:fx_out:false 4:2:fx_end:false");
  // 12.0.1: OWNERSHIP — an observer record is the receiver's own copy, so compare values
  assert(b.records[0].data.k === given.k && keysOf(b.records[0].data) === keysOf(given), "data is what the gate received");
  assertEqual(b.records[2].data.k, 2);
});

test("observer: a rejected batch ends with the named delivery and runs before the raise", () => {
  const cases = [
    ["self-loop", () => Event("fx_bad"), "SISO_SELF_LOOP"],
    ["not an Event", () => 7, "SISO_NOT_EVENT"],
    ["threw", () => { throw new Error("x"); }, "SISO_GATE_THREW"],
  ];
  for (const [label, fn, code] of cases) {
    const s = new Stream();
    s.emit(Event("kept"));
    s.register(Gate("fx_feed", () => [Event("fx_side"), Event("fx_bad")]));
    s.register(Gate("fx_bad", fn));
    let b;
    let residueSeen;
    s.attach((batch) => { b = batch; residueSeen = s.sampleHere().residue.length; });
    const r = emitCatch(s, Event("fx_feed"));
    assertEqual(r.code, code, label);
    noProblems(SEAM.batchProblems(b), label);
    // core 13 (observer-seam THE REJECTION): the observer's own read-only copy
    const why = SEAM.rejectionCopyProblem(b.rejection, r);
    assert(why === null, `${label}: ${why}`);
    assertEqual(b.records.length, 3, `${label}: records`);
    assertEqual(b.records[2].eventType, "fx_bad", label);
    assertEqual(residueSeen, 1, `${label}: nothing committed when the observer ran`);
  }
});

test("observer: a refused entry's batch carries the rejection and no records", () => {
  const s = new Stream();
  let b;
  s.attach((batch) => { b = batch; });
  const r = emitCatch(s, { type: "x", data: {} });
  assertEqual(b.outcome, "rejected");
  const why = SEAM.rejectionCopyProblem(b.rejection, r);
  assert(why === null, `refused entry: ${why}`);
  assertEqual(b.records.length, 0);
});

test("observer: one observer's writes to its records reach no other", () => {
  const s = new Stream();
  let seen;
  s.attach((b) => { b.records[0].eventType = "changed"; b.records[0].claimed = "x"; });
  s.attach((b) => { seen = b; });
  s.emit(Event("q"));
  // the order observers are called in carries no meaning, so run both orders
  const t = new Stream();
  let seen2;
  t.attach((b) => { seen2 = b; });
  t.attach((b) => { b.records[0].eventType = "changed"; });
  t.emit(Event("q"));
  assertEqual(seen.records[0].eventType, "q");
  assertEqual(seen.records[0].claimed, false);
  assertEqual(seen2.records[0].eventType, "q");
});

test("observer: a raising observer leaves a rejection exactly as raised", () => {
  const s = new Stream();
  s.register(Gate("fx_t", () => { throw new Error("gate failure"); }));
  s.attach(() => { throw new Error("observer failure"); });
  const r = emitCatch(s, Event("fx_t"));
  assertEqual(r.code, "SISO_GATE_THREW");
  assertEqual(r.cause.message.includes("gate failure"), true);
});

test("observer: an observer attached by another sees only later propagations", () => {
  const s = new Stream();
  const late = [];
  const lateObserver = (b) => { late.push(b.records[0].eventType); };
  let added = false;
  s.attach(() => {
    if (!added) {
      added = true;
      s.attach(lateObserver);
    }
  });
  s.emit(Event("first"));
  s.emit(Event("second"));
  assertEqual(late.join(","), "second");
});

test("observer: attach takes a function; misuse raises a string", () => {
  // JS: misuse throws an Error with a message (Lua raises a string)
  const s = new Stream();
  const throwsError = (fn, Kind) => {
    try {
      fn();
    } catch (e) {
      return e instanceof Kind && typeof e.message === "string" && e.message.length > 0;
    }
    return false;
  };
  assert(throwsError(() => s.attach({}), TypeError), "attaching an object");
  assert(throwsError(() => s.attach(null), TypeError), "attaching null");
  const f = () => {};
  s.attach(f);
  assert(throwsError(() => s.attach(f), Error), "attaching twice");
  s.detach(f);
  assert(throwsError(() => s.detach(f), Error), "detaching twice");
});

H.finish("stream-doors");
