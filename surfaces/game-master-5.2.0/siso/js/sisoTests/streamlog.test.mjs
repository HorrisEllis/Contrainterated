// streamlog: js/code/observer/streamlog.mjs on the observer seam (core 13,
// JavaScript instance). Port, case by case, of
// lua/sisoTests/streamlog.test.lua, which ports the intent of 11.1's
// doc/log/legacy-js/tests/streamlog.test.js. Where 12 differs from 11.1
// (batches per propagation, per-propagation ids, labels instead of
// nextStreamId, boolean claimed) a comment says so, as in the reference.
// Integration cases drive js/sisoTests/contract-fake-core.mjs, as the Lua
// suite drives its fake core; nothing here imports js/code/core/.
//
// Every case of the Lua suite is kept: each mechanism it exercises
// exists in JavaScript. Where the substrate differs, the case uses
// JavaScript's own counterpart:
//   - pcall(s.emit, s, …)       → try/catch around s.emit(…)
//   - error("boom") in a gate   → throw new Error("boom")
//   - os.time() / math.type     → Math.floor(Date.now() / 1000) /
//                                 Number.isInteger
//   - `field == nil`            → the key is absent (not present-but-
//                                 undefined), checked with hasOwn
//   - Stream() / s:attach(…)    → new Stream() / s.attach(…)
//   - seam.batch_problems       → batchProblems
// Additions that exist only because of the substrate (marked "JS:"):
//   - an inherited key of the level table ("toString", "__proto__") is an
//     unknown level like any other;
//   - the factory, a log and an observer are frozen, so a JS function
//     object cannot become module-global writable state;
//   - data recorded from a stream is the frozen copy the seam shows.
// Cases that cannot apply in JavaScript: none.
import * as H from "../code/harness.mjs";
import StreamLog from "../code/observer/streamlog.mjs";
import { batchProblems } from "../code/contracts/observer_seam.mjs";
import { fake } from "./contract-fake-core.mjs";

const { Event, Gate, Stream } = fake();
const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

// A hand-built batch stands in for 11.1's direct log.record(…) calls.
// 12: the log is fed a whole propagation, never one delivery.
function rec(id, cause, t, claimed, data) {
  const r = { eventId: id };
  if (cause !== undefined) r.causeId = cause;
  r.eventType = t;
  r.claimed = claimed;
  r.data = data === undefined ? {} : data;
  return r;
}
function batch(records, outcome = "settled", rejection) {
  const b = { outcome };
  if (rejection !== undefined) b.rejection = rejection;
  b.records = records;
  return b;
}
const one = (t, claimed, data) => batch([rec(1, undefined, t, claimed, data)]);

// Walk causeId within one propagation of one stream: → "t1,t2,…".
function thread(entries, label, propagation, fromId) {
  const byId = new Map();
  for (const e of entries) {
    if (e.propagation === propagation && (label === undefined || e.streamLabel === label)) byId.set(e.eventId, e);
  }
  const out = [];
  let cur = byId.get(fromId);
  while (cur) {
    out.unshift(cur.type);
    cur = cur.causeId !== undefined ? byId.get(cur.causeId) : undefined;
  }
  return out.join(",");
}

// emit and catch: → the rejection, or undefined when it settled
function emitCatch(s, e) {
  try {
    s.emit(e);
    return undefined;
  } catch (err) {
    return err;
  }
}

// ── Levels ─────────────────────────────────────

H.test("StreamLog: OFF records nothing", () => {
  const log = StreamLog("OFF");
  log.observer("s")(one("x", true));
  H.assertEqual(log.sample().count, 0);
});

H.test("StreamLog: default level is EVENTS", () => {
  H.assertEqual(StreamLog().level(), "EVENTS");
});

H.test("StreamLog: EVENTS records type and claimed", () => {
  const log = StreamLog("EVENTS");
  log.observer("s")(one("foo", true, { big: true }));
  const e = log.sample().entries;
  H.assertEqual(e.length, 1);
  H.assertEqual(e[0].type, "foo");
  // 12: claimed is the seam's boolean (11.1 gave the signature, which is the type)
  H.assertEqual(e[0].claimed, true);
  H.assertEqual(e[0].eventId, 1);
  H.assert(!hasOwn(e[0], "causeId"), "no causeId on the entry delivery");
  H.assertEqual(e[0].propagation, 1);
  H.assertEqual(e[0].outcome, "settled");
  H.assert(!hasOwn(e[0], "streamLabel"), "no streamLabel at EVENTS");
  H.assert(!hasOwn(e[0], "parentLabel"), "no parentLabel at EVENTS");
  H.assert(!hasOwn(e[0], "data"), "no data at EVENTS");
});

H.test("StreamLog: EVENTS records claimed false for residue", () => {
  // 11.1: claimed null; 12: false
  const log = StreamLog("EVENTS");
  log.observer("s")(one("orphan", false));
  H.assertEqual(log.sample().entries[0].claimed, false);
});

H.test("StreamLog: DEEP includes streamLabel and parentLabel", () => {
  // 12: labels chosen by whoever attaches, in place of 11.1's numeric stream ids
  const log = StreamLog("DEEP");
  log.observer("parent");
  log.observer("child", "parent")(one("x", true));
  const e = log.sample().entries[0];
  H.assertEqual(e.streamLabel, "child");
  H.assertEqual(e.parentLabel, "parent");
  H.assert(!hasOwn(e, "data"), "no data at DEEP");
});

H.test("StreamLog: DEEP omits parentLabel when there is none", () => {
  const log = StreamLog("DEEP");
  log.observer("s")(one("x", false));
  const e = log.sample().entries[0];
  H.assertEqual(e.streamLabel, "s");
  H.assert(!hasOwn(e, "parentLabel"), "parentLabel is absent, not present-but-undefined");
});

H.test("StreamLog: DATA includes everything", () => {
  const log = StreamLog("DATA");
  log.observer("p");
  log.observer("c", "p")(one("y", true, { val: 42 }));
  const e = log.sample().entries[0];
  H.assertEqual(e.streamLabel, "c");
  H.assertEqual(e.parentLabel, "p");
  H.assertEqual(e.data.val, 42);
});

// ── Unknown levels (X2) ────────────────────────

H.test("StreamLog: an unknown level is refused at construction (X2)", () => {
  // 11.1: an unknown level silently acted as OFF
  H.assertThrows(() => StreamLog("VERBOSE"), "unknown level 'VERBOSE'");
  H.assertThrows(() => StreamLog("events"), "unknown level");
  H.assertThrows(() => StreamLog(3), "unknown level");
  H.assertThrows(() => StreamLog(null), "unknown level");
  // JS: an inherited key of the level table is not a level
  H.assertThrows(() => StreamLog("toString"), "unknown level 'toString'");
  H.assertThrows(() => StreamLog("__proto__"), "unknown level");
  // JS: `new` returns the same kind of log
  H.assertEqual(new StreamLog("DATA").level(), "DATA");
});

H.test("StreamLog: an unknown level is refused when set, and the level is kept (X2)", () => {
  const log = StreamLog("DEEP");
  H.assertThrows(() => log.setLevel("LOUD"), "unknown level 'LOUD'");
  H.assertThrows(() => log.setLevel(undefined), "unknown level");
  H.assertThrows(() => log.setLevel("constructor"), "unknown level");
  H.assertEqual(log.level(), "DEEP");
  log.observer("s")(one("x", true));
  H.assertEqual(log.sample().entries[0].streamLabel, "s", "still recording at DEEP");
});

// ── Sequencing ─────────────────────────────────

H.test("StreamLog: seq increments", () => {
  const log = StreamLog("EVENTS");
  const obs = log.observer("s");
  obs(batch([rec(1, undefined, "a", true), rec(2, 1, "b", false)]));
  obs(one("c", false));
  const e = log.sample().entries;
  H.assertEqual(e[0].seq, 0);
  H.assertEqual(e[1].seq, 1);
  H.assertEqual(e[2].seq, 2);
});

H.test("StreamLog: entries have timestamps", () => {
  // 12: whole seconds (11.1: Date.now() milliseconds); Lua's os.time()
  // becomes Math.floor(Date.now() / 1000), math.type "integer" Number.isInteger
  const log = StreamLog("EVENTS");
  const before = Math.floor(Date.now() / 1000);
  log.observer("s")(one("x", false));
  const after = Math.floor(Date.now() / 1000);
  const t = log.sample().entries[0].time;
  H.assert(Number.isInteger(t) && t >= before && t <= after, `time within the call, in seconds (got ${H.show(t)})`);
});

// ── Labels (12, in place of nextStreamId) ──────

H.test("StreamLog: labels are per log and unique within it", () => {
  const a = StreamLog();
  const b = StreamLog();
  a.observer("s");
  b.observer("s"); // no module-global counter or registry: another log is unaffected
  H.assertThrows(() => a.observer("s"), "already used");
  H.assertThrows(() => a.observer(""), "non-empty string");
  H.assertThrows(() => a.observer(7), "non-empty string");
  H.assertThrows(() => a.observer("t", "nope"), "not a label of this log");
  H.assertThrows(() => b.observer("t", "x"), "not a label of this log");
  // JS: a function object can carry writable state; nothing here can
  H.assert(Object.isFrozen(StreamLog), "the factory is frozen (no module-global state hangs on it)");
  H.assert(Object.isFrozen(a), "a log is frozen");
  H.assert(Object.isFrozen(a.observer("u")), "an observer is frozen");
});

H.test("StreamLog: propagations are numbered per observed stream", () => {
  const log = StreamLog("DEEP");
  const p = log.observer("p");
  const c = log.observer("c", "p");
  p(one("a", false)); c(one("b", false)); p(one("d", false));
  const e = log.sample().entries;
  H.assertEqual(e[0].propagation, 1);
  H.assertEqual(e[1].propagation, 1);
  H.assertEqual(e[2].propagation, 2);
});

H.test("StreamLog: propagations at OFF are still counted", () => {
  const log = StreamLog("OFF");
  const obs = log.observer("s");
  obs(one("a", false));
  log.setLevel("EVENTS");
  obs(one("b", false));
  const e = log.sample().entries;
  H.assertEqual(e.length, 1);
  H.assertEqual(e[0].propagation, 2);
});

// ── sample and clear ───────────────────────────

H.test("StreamLog: sample returns copy", () => {
  const log = StreamLog("EVENTS");
  log.observer("s")(one("x", false));
  const s = log.sample();
  s.entries.pop();
  H.assertEqual(log.sample().count, 1);
  const s2 = log.sample();
  s2.entries[0].type = "changed";
  H.assertEqual(log.sample().entries[0].type, "x", "an entry write does not reach the log");
  H.assertEqual(s2.level, "EVENTS");
});

H.test("StreamLog: clear resets entries and seq", () => {
  const log = StreamLog("EVENTS");
  const obs = log.observer("s");
  obs(batch([rec(1, undefined, "x", true), rec(2, 1, "y", false)]));
  log.clear();
  H.assertEqual(log.sample().count, 0);
  obs(one("z", false));
  const e = log.sample().entries[0];
  H.assertEqual(e.seq, 0, "seq resets");
  H.assertEqual(e.propagation, 2, "propagation numbers are kept");
});

// ── Runtime level change ───────────────────────

H.test("StreamLog: level change takes effect immediately", () => {
  const log = StreamLog("OFF");
  const obs = log.observer("s");
  obs(one("a", false));
  H.assertEqual(log.sample().count, 0);
  log.setLevel("EVENTS");
  obs(one("b", false));
  H.assertEqual(log.sample().count, 1);
  log.setLevel("OFF");
  obs(one("c", false));
  H.assertEqual(log.sample().count, 1);
});

H.test("StreamLog: upgrade level mid-run adds detail", () => {
  const log = StreamLog("EVENTS");
  const obs = log.observer("s");
  obs(one("a", false, { x: 1 }));
  log.setLevel("DATA");
  obs(one("b", false, { x: 2 }));
  const e = log.sample().entries;
  H.assert(!hasOwn(e[0], "data"), "first entry has no data");
  H.assertEqual(e[1].data.x, 2, "second entry has data");
});

// ── Integration with a stream (contract fake core) ───

H.test("StreamLog: what it is shown passes the seam's batch check", () => {
  const s = new Stream();
  const log = StreamLog("DATA");
  const obs = log.observer("s");
  let shown;
  s.attach((b) => { shown = b; });
  s.attach(obs);
  s.register(Gate("root", () => [Event("a"), Event("b")]));
  s.emit(Event("root", { k: 1 }));
  const p = batchProblems(shown);
  H.assertEqual(p.length, 0, p.join("; "));
  const e = log.sample().entries;
  H.assertEqual(e.length, 3);
  H.assertEqual(e[0].data.k, 1);
  // JS: the data recorded is the frozen copy the seam shows the observer
  H.assert(Object.isFrozen(e[0].data), "recorded data is the seam's frozen copy");
});

H.test("StreamLog: Stream.emit records to log", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("sink", () => undefined));
  s.emit(Event("sink", {}));
  s.emit(Event("orphan", {}));
  const e = log.sample().entries;
  H.assertEqual(e.length, 2);
  H.assertEqual(e[0].claimed, true);
  H.assertEqual(e[1].claimed, false);
});

H.test("StreamLog: shared log across parent and sub-stream", () => {
  const log = StreamLog("DEEP");
  const parent = new Stream();
  const child = new Stream();
  parent.attach(log.observer("parent"));
  child.attach(log.observer("child", "parent"));
  parent.emit(Event("parent_event", {}));
  child.emit(Event("child_event", {}));
  const e = log.sample().entries;
  H.assertEqual(e.length, 2);
  H.assertEqual(e[0].streamLabel, "parent");
  H.assert(!hasOwn(e[0], "parentLabel"), "the parent stream has no parentLabel");
  H.assertEqual(e[1].streamLabel, "child");
  H.assertEqual(e[1].parentLabel, "parent");
  // 12: both are delivery 1 of propagation 1 of their own stream; the label disambiguates
  H.assertEqual(e[0].eventId, e[1].eventId);
});

H.test("StreamLog: returned events are recorded via log", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("forward", (ev) => Event("forwarded", { from: ev.data.origin })));
  s.emit(Event("forward", { origin: "test" }));
  const e = log.sample().entries;
  H.assertEqual(e.length, 2);
  H.assertEqual(e[0].type, "forward");
  H.assertEqual(e[0].claimed, true);
  H.assertEqual(e[1].type, "forwarded");
  H.assertEqual(e[1].claimed, false);
});

// ── Causal threads ─────────────────────────────

H.test("StreamLog: chain entries carry eventId and causeId", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("x", () => Event("y")));
  s.register(Gate("y", () => Event("z")));
  s.emit(Event("x", {}));
  const e = log.sample().entries;
  H.assertEqual(e.length, 3);
  H.assertEqual(e[0].eventId, 1);
  H.assert(!hasOwn(e[0], "causeId"), "external emit has no cause");
  H.assertEqual(e[1].eventId, 2);
  H.assertEqual(e[1].causeId, 1, "y caused by x");
  H.assertEqual(e[2].eventId, 3);
  H.assertEqual(e[2].causeId, 2, "z caused by y");
});

H.test("StreamLog: fan-out siblings share a cause", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("root", () => [Event("left"), Event("right")]));
  s.emit(Event("root", {}));
  const e = log.sample().entries;
  H.assertEqual(e.length, 3);
  H.assertEqual(e[1].causeId, e[0].eventId, "left caused by root");
  H.assertEqual(e[2].causeId, e[0].eventId, "right caused by root");
});

H.test("StreamLog: separate external emits are separate roots", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.emit(Event("a", {}));
  s.emit(Event("b", {}));
  const e = log.sample().entries;
  H.assert(!hasOwn(e[0], "causeId"), "the first entry delivery has no cause");
  H.assert(!hasOwn(e[1], "causeId"), "the second entry delivery has no cause");
  // 12: ids restart in every propagation (11.1: "ids continue across emits");
  // the log's propagation number tells the two apart
  H.assertEqual(e[0].eventId, 1);
  H.assertEqual(e[1].eventId, 1);
  H.assertEqual(e[0].propagation, 1);
  H.assertEqual(e[1].propagation, 2);
});

H.test("StreamLog: causal chain reconstructible from a mixed tree", () => {
  // root fans out to two chains; entries interleave by generation, but
  // walking causeId untangles each thread: order means time, causeId cause.
  const log = StreamLog("DEEP");
  const s = new Stream();
  s.attach(log.observer("s"));
  const mk = (sig, outs) => Gate(sig, () => outs.map((t) => Event(t)));
  s.register(mk("root", ["a", "b"]));
  s.register(mk("a", ["a1"]));
  s.register(mk("b", ["b1"]));
  s.register(mk("a1", ["a2"]));
  s.emit(Event("x")); // 12: an earlier propagation reuses the same ids; the walk must not cross it
  s.emit(Event("root", {}));
  const entries = log.sample().entries;
  const a2 = entries.find((e) => e.type === "a2");
  H.assert(a2 !== undefined, "a2 was recorded");
  H.assertEqual(thread(entries, "s", a2.propagation, a2.eventId), "root,a,a1,a2");
});

// ── Acyclicity diagnostics ─────────────────────

H.test("StreamLog: entries carry no retries field", () => {
  const log = StreamLog("DATA");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.emit(Event("plain", {}));
  const e = log.sample().entries;
  H.assertEqual(e.length, 1);
  H.assert(!("retries" in e[0]), "no retries in core 12");
});

H.test("StreamLog: a cycle is readable from the causal chain", () => {
  // The rejection cannot name the loop by itself (K5); an attached log can,
  // by walking causeId from the delivery the rejection names.
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("a", () => Event("b")));
  s.register(Gate("b", () => Event("a")));
  const err = emitCatch(s, Event("a", {}));
  H.assert(err !== undefined, "the cycle rejects");
  H.assertEqual(err.code, "SISO_CYCLE");
  const entries = log.sample().entries;
  const last = entries[entries.length - 1];
  H.assertEqual(last.eventId, err.eventId, "the records end with the delivery the rejection names");
  H.assertEqual(last.outcome, "rejected");
  H.assertEqual(thread(entries, undefined, last.propagation, err.eventId), "a,b,a");
});

H.test("StreamLog: self-loop rejection points at the producing entry", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("loop", () => Event("loop")));
  const err = emitCatch(s, Event("loop", {}));
  H.assert(err !== undefined, "the self-loop rejects");
  H.assertEqual(err.code, "SISO_SELF_LOOP");
  const e = log.sample().entries;
  H.assertEqual(e.length, 1, "the looping return is never delivered");
  H.assertEqual(e[0].eventId, err.eventId);
  H.assertEqual(e[0].claimed, true);
  H.assertEqual(e[0].outcome, "rejected");
});

H.test("StreamLog: gate-threw rejection points at the claiming entry", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  s.attach(log.observer("s"));
  s.register(Gate("a", () => Event("b")));
  s.register(Gate("b", () => { throw new Error("boom"); }));
  const err = emitCatch(s, Event("a", {}));
  H.assert(err !== undefined, "the throw rejects");
  H.assertEqual(err.code, "SISO_GATE_THREW");
  const found = log.sample().entries.find((x) => x.eventId === err.eventId);
  H.assert(found !== undefined, "the rejection names a logged delivery");
  H.assertEqual(found.type, "b");
  H.assertEqual(found.claimed, true);
  H.assertEqual(found.causeId, err.causeId);
});

H.test("StreamLog: a refused entry is a propagation with no entries", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  const obs = log.observer("s");
  s.attach(obs);
  emitCatch(s, "not an event");
  H.assertEqual(log.sample().count, 0);
  s.emit(Event("ok"));
  H.assertEqual(log.sample().entries[0].propagation, 2);
});

H.test("StreamLog: log presence does not change acyclicity outcome", () => {
  const run = (withLog) => {
    const s = new Stream();
    if (withLog) s.attach(StreamLog("DATA").observer("s"));
    s.register(Gate("a", () => Event("b")));
    s.register(Gate("b", () => Event("a")));
    const e = emitCatch(s, Event("a", {}));
    if (e === undefined) return "none";
    return `${e.code}@${String(e.eventId)}/${String(e.depth)}`;
  };
  H.assertEqual(run(true), run(false));
});

H.test("StreamLog: log absence changes nothing", () => {
  // 12: sampleHere carries no eventCount (core-api), so compare residue only
  const run = (withLog) => {
    const s = new Stream();
    if (withLog) s.attach(StreamLog("EVENTS").observer("s"));
    s.register(Gate("x", () => Event("y")));
    s.emit(Event("x", {}));
    const r = s.sampleHere().residue;
    return `${r.length}:${r[0].type}`;
  };
  H.assertEqual(run(true), run(false));
});

H.test("StreamLog: detaching stops recording; re-attaching continues numbering", () => {
  const log = StreamLog("EVENTS");
  const s = new Stream();
  const obs = log.observer("s");
  s.attach(obs);
  s.emit(Event("a"));
  s.detach(obs);
  s.emit(Event("b"));
  H.assertEqual(log.sample().count, 1);
  s.attach(obs);
  s.emit(Event("c"));
  const e = log.sample().entries;
  H.assertEqual(e.length, 2);
  H.assertEqual(e[1].propagation, 2);
});

H.finish("streamlog");
