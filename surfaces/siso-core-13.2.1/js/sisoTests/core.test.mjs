// core.test.mjs — port of lua/sisoTests/core.test.lua (itself the port of
// core 11.1 legacy/js/tests/core.test.js). One test per Lua test, same
// names, same expectations. The gates below are fixtures for the stream,
// not project gates.
//
// Adapted to the substrate (js-core-api SUBSTRATE), case by case:
//   - "Stream: register and lookup" and "different signatures coexist"
//     read the Lua stream's `gates` table, which has no counterpart: the
//     JS stream's state is in private fields (Lua's R5 vanishes). Both
//     become JavaScript's own attempt: `s.gates` is undefined, and the
//     lookup is seen through the doors (the gate claims its type;
//     sampleHere().gateCount counts the gates).
//   - "indirect cycle" reads the gate count from sampleHere().gateCount
//     instead of counting `s.gates`.
//   - "gate receives event only": select('#', ...) becomes
//     arguments.length; the receiver is the other way a JS function is
//     handed something, so `this` is pinned undefined as well.
//   - Lua's raised tables (Error(message)) are JS Errors; `error("…", 0)`
//     is `throw "…"`.
// No case is left out.
import * as H from "../code/harness.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";

const { test, assert, assertEqual, assertThrows } = H;

const keysOf = (t) => Object.keys(t).sort().join(",");
const keyCount = (t) => Object.keys(t).length;
const emitCatch = (s, e) => {
  try {
    s.emit(e);
    return undefined;
  } catch (err) {
    return err;
  }
};
const events = (types) => types.map((t) => Event(t, {}));

// ── Event ──────────────────────────────────────

test("Event: type and data", () => {
  const e = Event("foo", { x: 1 });
  assertEqual(e.type, "foo");
  assertEqual(e.data.x, 1);
});

test("Event: data defaults to empty object", () => {
  const e = Event("bar");
  assertEqual(e.type, "bar");
  assert(typeof e.data === "object" && e.data !== null && !Array.isArray(e.data));
  assertEqual(keyCount(e.data), 0);
});

// ── Gate ───────────────────────────────────────

test("Gate: signature stored", () => {
  const g = Gate("test_sig");
  assertEqual(g.signature, "test_sig");
});

test("Gate: transform returns undefined by default", () => {
  const g = Gate("noop");
  const result = g.transform(Event("noop"));
  assertEqual(result, undefined);
});

// ── Stream: registration ───────────────────────

test("Stream: register and lookup", () => {
  const s = new Stream();
  let claimed = false;
  s.register(Gate("x", () => { claimed = true; }));
  // JS: the gate table is private; the lookup is seen through the doors
  assertEqual(s.gates, undefined, "no gate table on the stream");
  s.emit(Event("x"));
  assert(claimed, "the registered gate claims its type");
  assertEqual(s.sampleHere().gateCount, 1);
});

test("Stream: signature collision throws", () => {
  const s = new Stream();
  s.register(Gate("x"));
  assertThrows(() => s.register(Gate("x")), "Signature collision");
});

test("Stream: different signatures coexist", () => {
  const s = new Stream();
  s.register(Gate("a"));
  s.register(Gate("b"));
  assertEqual(s.sampleHere().gateCount, 2);
});

// ── Stream: emit and residue ───────────────────

test("Stream: unclaimed event goes to residue", () => {
  const s = new Stream();
  s.emit(Event("unknown", { v: 42 }));
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 1);
  assertEqual(sample.residue[0].type, "unknown");
  assertEqual(sample.residue[0].data.v, 42);
});

test("Stream: claimed event does not go to residue", () => {
  const s = new Stream();
  s.register(Gate("sink", () => null));
  s.emit(Event("sink", {}));
  assertEqual(s.sampleHere().residue.length, 0);
});

test("Stream: gate receives event only", () => {
  let transformArgs = null;
  const s = new Stream();
  s.register(Gate("spy", function (...args) {
    const event = args[0];
    transformArgs = { argCount: arguments.length, self: this, type: event.type, data: event.data };
    return null;
  }));
  s.emit(Event("spy", { val: 99 }));
  assertEqual(transformArgs.argCount, 1);
  assertEqual(transformArgs.self, undefined, "no receiver");
  assertEqual(transformArgs.type, "spy");
  assertEqual(transformArgs.data.val, 99);
});

test("Stream: gate returns single event → re-emitted", () => {
  const s = new Stream();
  s.register(Gate("double", (event) => Event("result", { value: event.data.x * 2 })));
  s.emit(Event("double", { x: 5 }));
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 1);
  assertEqual(sample.residue[0].data.value, 10);
});

test("Stream: gate returns array of events → all re-emitted", () => {
  const s = new Stream();
  s.register(Gate("split", (event) => [
    Event("part_a", { half: Math.floor(event.data.x / 2) }),
    Event("part_b", { doubled: event.data.x * 2 }),
  ]));
  s.emit(Event("split", { x: 10 }));
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 2);
  assertEqual(sample.residue[0].data.half, 5);
  assertEqual(sample.residue[1].data.doubled, 20);
});

test("Stream: gate returns null → consumed, no follow-up", () => {
  const s = new Stream();
  s.register(Gate("consume", () => null));
  // 12.0: no eventCount; the one delivery is seen in an observer's batch
  const batches = [];
  s.attach((b) => { batches.push(b); });
  s.emit(Event("consume", {}));
  assertEqual(s.sampleHere().residue.length, 0);
  assertEqual(batches.length, 1, "one batch");
  assertEqual(batches[0].records.length, 1, "one delivery");
  assertEqual(batches[0].records[0].claimed, true, "claimed by the gate");
});

test("Stream: gate returns undefined → same as null", () => {
  const s = new Stream();
  s.register(Gate("silent", () => { /* no return */ }));
  s.emit(Event("silent", {}));
  assertEqual(s.sampleHere().residue.length, 0);
});

test("Stream: chain processes in causal order", () => {
  const order = [];
  const s = new Stream();
  s.register(Gate("a", () => { order.push("a"); return Event("b", {}); }));
  s.register(Gate("b", () => { order.push("b"); return Event("c", {}); }));
  s.register(Gate("c", () => { order.push("c"); return null; }));
  s.emit(Event("a", {}));
  assertEqual(order.join(","), "a,b,c");
});

test("Stream: fan-out delivers all branches", () => {
  const order = [];
  const s = new Stream();
  s.register(Gate("root", () => [Event("left", {}), Event("right", {})]));
  s.register(Gate("left", () => { order.push("left"); return null; }));
  s.register(Gate("right", () => { order.push("right"); return null; }));
  s.emit(Event("root", {}));
  assertEqual(order.length, 2);
  assert(order.includes("left"), "left branch fired");
  assert(order.includes("right"), "right branch fired");
});

// ── Stream: delivery discipline ────────────────

test("Stream: delivery is breadth-first — causal generations", () => {
  const order = [];
  const mk = (sig, out) => Gate(sig, () => {
    order.push(sig);
    return out ? events(out) : null;
  });
  const s = new Stream();
  s.register(mk("root", ["a", "b"]));
  s.register(mk("a", ["a1"]));
  s.register(mk("b", ["b1"]));
  s.register(mk("a1", null));
  s.register(mk("b1", null));
  s.emit(Event("root", {}));
  // Generation by generation: root, then both children, then both
  // grandchildren. Depth-first would give root,a,a1,b,b1. Order is not
  // semantic (Rule 3) — this pins the implementation choice, not a
  // contract.
  assertEqual(order.join(","), "root,a,b,a1,b1");
});

test("Stream: chain length unbounded by call stack", () => {
  // A legal chain of N distinct gates: step_0 → step_1 → … No type
  // repeats, so acyclicity holds — chain length is bounded by gate count,
  // exactly as Rule 3 permits. The recursive implementation overflowed
  // the engine stack on chains this long; queue delivery must not.
  const N = 20000;
  const Step = (i) => Gate(`step_${i}`, () => (i < N - 1 ? Event(`step_${i + 1}`, {}) : null));
  const s = new Stream();
  for (let i = 0; i < N; i += 1) s.register(Step(i));
  // 12.0: no eventCount; an observer's batch shows the N deliveries
  let deliveries = null;
  s.attach((b) => { deliveries = b.records.length; });
  s.emit(Event("step_0", {}));
  assertEqual(deliveries, N);
  assertEqual(s.sampleHere().residue.length, 0);
});

test("Stream: events are never annotated by delivery", () => {
  const s = new Stream();
  s.register(Gate("pass", () => Event("out", { v: 1 })));
  const rootEvent = Event("pass", {});
  s.emit(rootEvent);
  const out = s.sampleHere().residue[0];
  assertEqual(keysOf(rootEvent), "data,type", "emitted event untouched");
  assertEqual(keysOf(out), "data,type", "delivered event untouched");
});

// ── Event: shape ───────────────────────────────

test("Event: carries only type and data", () => {
  // Core 11 removed the retries field. A stray third argument must not
  // attach anything to the event.
  const e = Event("foo", { x: 1 }, { attempt: 1, max: 3 });
  assertEqual(keysOf(e), "data,type");
});

// ── Stream: acyclicity (enforced) ──────────────

test("Stream: self-loop throws SISO_SELF_LOOP", () => {
  const s = new Stream();
  s.register(Gate("echo", (event) => Event("echo", { v: event.data.v + 1 })));
  const err = emitCatch(s, Event("echo", { v: 0 }));
  assert(err, "expected a throw");
  assertEqual(err.code, "SISO_SELF_LOOP");
  assertEqual(err.eventType, "echo");
  assertEqual(s.sampleHere().residue.length, 0, "nothing committed");
});

test("Stream: self-loop inside a fan-out array throws", () => {
  const s = new Stream();
  s.register(Gate("mixed", () => [Event("other", {}), Event("mixed", {})]));
  assertThrows(() => s.emit(Event("mixed", {})), "Self-loop");
});

test("Stream: indirect cycle a → b → a throws SISO_CYCLE", () => {
  const s = new Stream();
  s.register(Gate("a", () => Event("b", {})));
  s.register(Gate("b", () => Event("a", {})));
  const err = emitCatch(s, Event("a", {}));
  assert(err, "expected a throw — core 10 looped forever here");
  assertEqual(err.code, "SISO_CYCLE");
  // Caught at the first claimed event deeper than the gate count.
  assertEqual(err.depth, s.sampleHere().gateCount + 1);
});

test("Stream: longer indirect cycle a → b → c → a throws", () => {
  const mk = (sig, out) => Gate(sig, () => Event(out, {}));
  const s = new Stream();
  s.register(mk("a", "b"));
  s.register(mk("b", "c"));
  s.register(mk("c", "a"));
  assertThrows(() => s.emit(Event("a", {})), "Cycle");
});

test("Stream: same type in sibling branches is legal", () => {
  // Acyclicity is per causal path, not across the tree.
  const mk = (sig, out) => Gate(sig, () => (out ? events(out) : null));
  const s = new Stream();
  s.register(mk("root", ["x", "y"]));
  s.register(mk("x", ["z"]));
  s.register(mk("y", ["z"]));
  s.register(mk("z", ["done"]));
  s.emit(Event("root", {}));
  const residue = s.sampleHere().residue;
  assertEqual(residue.length, 2, "z ran on both branches");
  assert(residue.every((e) => e.type === "done"));
});

test("Stream: longest legal chain ends unclaimed at depth gates + 1", () => {
  // Every gate claimed once, then a final unclaimed event. That is the
  // deepest a legal chain can go — no throw.
  const mk = (sig, out) => Gate(sig, () => Event(out, {}));
  const s = new Stream();
  s.register(mk("a", "b"));
  s.register(mk("b", "c"));
  s.register(mk("c", "answer"));
  s.emit(Event("a", {}));
  assertEqual(s.sampleHere().residue[0].type, "answer");
});

test("Stream: a propagation that throws commits no residue", () => {
  const mk = (sig, out) => Gate(sig, () => events(out));
  const s = new Stream();
  s.register(mk("root", ["leftover", "a"]));
  s.register(mk("a", ["b"]));
  s.register(mk("b", ["a"]));
  s.emit(Event("earlier", {})); // settles normally
  assertThrows(() => s.emit(Event("root", {})), "Cycle");
  const residue = s.sampleHere().residue;
  assertEqual(residue.length, 1, "only the earlier residue remains");
  assertEqual(residue[0].type, "earlier");
});

test("Stream: stream remains usable after a throw", () => {
  const s = new Stream();
  s.register(Gate("echo", () => Event("echo", {})));
  assertThrows(() => s.emit(Event("echo", {})), "Self-loop");
  s.emit(Event("fine", {}));
  assertEqual(s.sampleHere().residue.length, 1);
});

test("Stream: a gate that throws rejects with SISO_GATE_THREW, ids and cause", () => {
  const original = new TypeError("bad input");
  const s = new Stream();
  s.register(Gate("a", () => Event("b", {})));
  s.register(Gate("b", () => { throw original; }));
  const err = emitCatch(s, Event("a", {}));
  assert(err !== undefined, "emit threw");
  assertEqual(err.code, "SISO_GATE_THREW");
  assertEqual(err.eventType, "b", "the event the gate was given");
  assertEqual(err.eventId, 2, "the delivery that threw");
  assertEqual(err.causeId, 1, "the delivery that caused it");
  assert(err.cause === original, "the gate's own error is kept as cause");
  assert(err.message.includes("bad input"), "message carries the original");
});

test("Stream: a gate that throws commits no residue; stream stays usable", () => {
  const s = new Stream();
  s.register(Gate("root", () => [Event("leftover", {}), Event("b", {})]));
  s.register(Gate("b", () => { throw new Error("boom"); }));
  assertThrows(() => s.emit(Event("root", {})), "boom");
  assertEqual(s.sampleHere().residue.length, 0, "the queued sibling is not committed");
  s.emit(Event("fine", {}));
  assertEqual(s.sampleHere().residue.length, 1);
});

test("Stream: a gate that throws a non-Error is wrapped the same way", () => {
  const s = new Stream();
  s.register(Gate("x", () => { throw "plain string"; })); // eslint-disable-line no-throw-literal
  const err = emitCatch(s, Event("x", {}));
  assert(err instanceof Error && String(err) === err.message, "a real Error leaves emit");
  assertEqual(err.code, "SISO_GATE_THREW");
  assertEqual(err.cause, "plain string");
  assertEqual(err.causeId, undefined, "an external entry has no cause");
});

// ── Stream: take residue ───────────────────────

test("Stream: takeResidue returns the residue and removes it", () => {
  const s = new Stream();
  s.emit(Event("x", { v: 1 }));
  s.emit(Event("y", { v: 2 }));
  const taken = s.takeResidue();
  assertEqual(taken.length, 2);
  assertEqual(taken[0].type, "x");
  assertEqual(taken[1].data.v, 2);
  assertEqual(s.sampleHere().residue.length, 0, "stream emptied");
});

test("Stream: residue after a take holds only new arrivals", () => {
  const s = new Stream();
  s.emit(Event("old", {}));
  s.takeResidue();
  s.emit(Event("new", {}));
  const residue = s.sampleHere().residue;
  assertEqual(residue.length, 1);
  assertEqual(residue[0].type, "new");
});

test("Stream: takeResidue on an empty stream returns []", () => {
  const s = new Stream();
  const taken = s.takeResidue();
  assert(Array.isArray(taken) && taken.length === 0 && Reflect.ownKeys(taken).length === 1);
});

test("Stream: sampling does not take", () => {
  const s = new Stream();
  s.emit(Event("x", {}));
  s.sampleHere();
  s.sampleHere();
  assertEqual(s.takeResidue().length, 1);
});

// ── Stream: sampleHere ─────────────────────────

test("Stream: sampleHere returns copy of residue", () => {
  const s = new Stream();
  s.emit(Event("x", {}));
  const sample = s.sampleHere();
  sample.residue.pop();
  assertEqual(s.sampleHere().residue.length, 1, "original not affected");
});

test("Stream: eventCount tracks all emits including re-emits", () => {
  // 12.0: eventCount is gone (ledger R1 retired). Each propagation numbers
  // its own deliveries 1, 2, … including re-emits, shown to an observer;
  // nothing is counted across propagations and the sample has no counter.
  const s = new Stream();
  s.register(Gate("echo", () => Event("out", {})));
  const ids = [];
  s.attach((b) => { ids.push(b.records.map((r) => String(r.eventId)).join(",")); });
  s.emit(Event("echo", {}));
  s.emit(Event("echo", {}));
  assertEqual(ids[0], "1,2", "the entry and its re-emit");
  assertEqual(ids[1], "1,2", "the next propagation numbers its own");
  assertEqual(keysOf(s.sampleHere()), "gateCount,residue", "no counter in the sample");
});

test("Stream: gateCount in sampleHere", () => {
  const s = new Stream();
  s.register(Gate("a"));
  s.register(Gate("b"));
  s.register(Gate("c"));
  assertEqual(s.sampleHere().gateCount, 3);
});

test("Stream: residue accumulates across emits", () => {
  const s = new Stream();
  s.emit(Event("x", {}));
  s.emit(Event("y", {}));
  assertEqual(s.sampleHere().residue.length, 2);
});

// ── Stream: constructor options ────────────────

test("Stream: no-arg constructor still works", () => {
  // 12.0: new Stream() is the only constructor: no gates, no residue, no
  // observers, and no log, stream id or lineage on the stream.
  const s = new Stream();
  assertEqual(s.log, undefined);
  assertEqual(s.streamId, undefined);
  assertEqual(s.parentStreamId, undefined);
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 0);
  assertEqual(sample.gateCount, 0);
  let seen = 0;
  s.attach(() => { seen += 1; });
  s.emit(Event("x", {}));
  assertEqual(seen, 1, "the only observer is the one attached");
});

test("Stream: constructor accepts log", () => {
  // 12.0: Stream takes no options. A log is an observer, attached through
  // the observe door; a `{ log: … }` argument is not read, so nothing is
  // called on it and the stream holds no log or stream id.
  let calls = 0;
  const fakeLog = {
    nextStreamId: () => { calls += 1; return 42; },
    record: () => { calls += 1; },
  };
  const s = new Stream({ log: fakeLog, parentStreamId: 7 });
  s.emit(Event("x", {}));
  assertEqual(calls, 0, "the log option is not read");
  assertEqual(s.log, undefined);
  assertEqual(s.streamId, undefined);
  assertEqual(s.parentStreamId, undefined);
  let batches = 0;
  s.attach(() => { batches += 1; });
  s.emit(Event("y", {}));
  assertEqual(batches, 1, "an observer attaches through the door");
});

H.finish("core");
