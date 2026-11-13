// stream-born-frozen.test.mjs — an event is read-only from the moment
// Event builds it (js-core-api EVENT / READ-ONLY, core 13). Port of
// lua/sisoTests/stream-born-frozen.test.lua. Runs the core-api read-only
// check on the real core, then pins what it leaves open: the refusal,
// defaults, identities kept, and that the stream passes a born-frozen
// event a gate returns through untouched. The gates below are fixtures
// for the stream, not project gates.
//   node js/sisoTests/stream-born-frozen.test.mjs
//
// Adapted to the substrate (js-core-api SUBSTRATE), case by case:
//   - The refusal: Lua's "read-only: …" error becomes the language's own
//     TypeError (Object.freeze, strict code: ESM is strict).
//   - "read-only data keeps its identity: … a __readonly sentinel":
//     sentinels vanish (JSON null is the primitive null, kept as itself);
//     the case keeps frozen data and a carried event, pins null, and adds
//     the contract's JS clause that a caller's own frozen object is the
//     caller's, so it is copied.
//   - "marks survive and sharing is kept": data marks vanish (an array is
//     an Array) and getmetatable becomes the prototype: an array copies to
//     a frozen Array, plain data's prototype is Object.prototype, and an
//     object with a null prototype keeps it.
//   - "R3 concession: rawset on a born-frozen event is not refused":
//     JS has no rawset (R3's rawset clause vanishes). The substrate's own
//     attempts are Reflect.set and Reflect.defineProperty, which a frozen
//     object refuses, and a private-field stamp, which V8 accepts on a
//     frozen object today: pinned so a change is seen.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as API from "../code/contracts/core_api.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";
import { isFrozen } from "../code/core/freeze.mjs";

const core = { Event, Gate, Stream };
const { test, assert, assertEqual } = H;

const refuses = (label, fn) => {
  let err;
  try {
    fn();
  } catch (e) {
    err = e;
  }
  assert(err !== undefined, `${label} was accepted`);
  assert(err instanceof TypeError, `${label}: threw ${String(err)}, not the read-only refusal`);
};
const keysOf = (t) => Object.keys(t).sort().join(",");

// ── the contract on the real core ──────────────

test("core-api read-only holds on the core (from construction)", () => {
  const p = API.check.readonly(core);
  assert(p.length === 0, p.join("; "));
});

test("core-api doors still hold on the core", () => {
  const p = API.check.doors(core);
  assert(p.length === 0, p.join("; "));
});

// ── what Event builds ──────────────────────────

test("a new event refuses assignment at every depth, with the read-only refusal", () => {
  const e = Event("t", { v: 1, list: [1, 2], m: { inner: { x: 5 } } });
  refuses("the type", () => { e.type = "z"; });
  refuses("the data", () => { e.data = {}; });
  refuses("a new field", () => { e.extra = 1; });
  refuses("a data field", () => { e.data.v = 2; });
  refuses("a new data key", () => { e.data.added = 1; });
  refuses("a list member", () => { e.data.list[0] = 0; });
  refuses("a list push", () => { e.data.list.push(3); });
  refuses("deep data", () => { e.data.m.inner.x = 0; });
  refuses("delete", () => { delete e.data.v; });
  assertEqual(keysOf(e), "data,type");
  assertEqual(`${e.type}:${e.data.v}:${e.data.list[0]}:${e.data.m.inner.x}`, "t:1:1:5", "it reads back unchanged");
  assert(isFrozen(e) && isFrozen(e.data), "the event and its data are frozen by the core");
  assert(Object.isFrozen(e) && Object.isFrozen(e.data.m.inner), "frozen by the language");
  assert(Event.is(e), "Event.is recognises it");
});

test("default data is an empty frozen table; extra arguments attach nothing", () => {
  const d = Event("t");
  assertEqual(keysOf(d.data), "");
  refuses("default data", () => { d.data.x = 1; });
  const x = Event("t", { v: 1 }, { attempt: 1 }, "more");
  assertEqual(keysOf(x), "data,type");
  assertEqual(keysOf(x.data), "v");
});

test("data is taken as it stands: later writes to the passed table do not reach the event", () => {
  const orig = { v: 1, list: [1], m: { k: "a" } };
  const e = Event("t", orig);
  orig.v = 2;
  orig.list[0] = 9;
  orig.m.k = "b";
  orig.late = true;
  assertEqual(e.data.v, 1);
  assertEqual(e.data.list[0], 1);
  assertEqual(e.data.m.k, "a");
  assertEqual(e.data.late, undefined);
  assert(!isFrozen(orig) && !Object.isFrozen(orig) && !Object.isFrozen(orig.list), "the caller's object stays its own");
  assertEqual(keysOf(orig), "late,list,m,v", "the caller's object carries nothing new");
});

test("read-only data keeps its identity: frozen data, a carried event, a __readonly sentinel", () => {
  const a = Event("a", { v: 1 });
  const passed = Event("b", a.data);
  assert(passed.data === a.data, "frozen data passed on is the same object");
  const callerFrozen = Object.freeze({ k: 1 });
  const carried = Event("c", { inner: a, d: a.data, n: null, list: [null], f: callerFrozen });
  assert(carried.data.inner === a, "an event in data is that event");
  assert(carried.data.d === a.data, "frozen data nested is that data");
  assert(carried.data.n === null, "null nested is null (JS: no sentinel)");
  assert(carried.data.list[0] === null, "null in an array is null");
  assert(Event("s", null).data === null, "null as the data itself is null");
  assert(carried.data.f !== callerFrozen && carried.data.f.k === 1, "a caller's own frozen object is copied (JS)");
});

test("marks survive and sharing is kept; a cycle terminates (not plain data)", () => {
  const shared = [1];
  const bare = Object.create(null);
  bare.k = 1;
  const e = Event("t", { list: [1, 2], plain: {}, a: shared, b: shared, bare });
  assert(Array.isArray(e.data.list) && Object.getPrototypeOf(e.data.list) === Array.prototype, "an array stays an Array");
  assertEqual(Object.getPrototypeOf(e.data.plain), Object.prototype, "plain data's prototype is the intrinsic");
  assertEqual(Object.getPrototypeOf(e.data.bare), null, "a null prototype is kept");
  assert(e.data.a === e.data.b, "an object reached twice is one frozen object");
  const cyclic = { name: "c" };
  cyclic.self = cyclic;
  const c = Event("cyc", cyclic).data;
  assert(c.self === c, "a cycle freezes to the same cycle");
  refuses("a write into a frozen cycle", () => { c.self.name = "x"; });
});

test("JS 13.1.0: a symbol key, a non-enumerable property or an accessor is refused, never dropped or run (Drift 7)", () => {
  let ran = 0;
  const getter = { get() { ran += 1; return 1; }, enumerable: true };
  const cases = [
    ["a symbol key", () => ({ [Symbol("k")]: 1 }), "data.v", "symbol key"],
    ["a symbol key beside string keys", () => ({ a: 1, [Symbol("k")]: 2 }), "data.v", "symbol key"],
    ["an accessor", () => Object.defineProperty({}, "g", getter), "data.v.g", "accessor"],
    ["a setter only", () => Object.defineProperty({}, "s", { set() {}, enumerable: true }), "data.v.s", "accessor"],
    ["a non-enumerable property", () => Object.defineProperty({ a: 1 }, "h", { value: 1, enumerable: false }), "data.v.h", "non-enumerable"],
    ["an accessor on an array index", () => Object.defineProperty([1], 0, getter), "data.v[0]", "accessor"],
    ["a symbol key on an array", () => Object.assign([1], { [Symbol("k")]: 1 }), "data.v", "symbol key"],
    ["a null-prototype object with an accessor", () => Object.defineProperty(Object.create(null), "g", getter), "data.v.g", "accessor"],
    ["deep inside the data", () => ({ a: { b: [Object.defineProperty({}, "g", getter)] } }), "data.v.a.b[0].g", "accessor"],
  ];
  for (const [label, make, path, what] of cases) {
    let err;
    try {
      Event("t", { v: make() });
    } catch (e) {
      err = e;
    }
    assert(err instanceof TypeError && err.message.includes("not plain data"), `${label}: ${err === undefined ? "accepted" : String(err)}`);
    assert(err.message.includes(`data at ${path} `) && err.message.includes(what), `${label}: message ${err.message}`);
  }
  // as the data itself, and as the event's type
  let err;
  try {
    Event("t", { [Symbol("k")]: 1 });
  } catch (e) {
    err = e;
  }
  assert(err instanceof TypeError && err.message.includes("data at data is not plain data"), `the data itself: ${String(err)}`);
  assertEqual(ran, 0, "no accessor was run");
  // an ordinary object and array still go through
  const ok = Event("t", { a: [1, { b: 2 }], c: null });
  assertEqual(ok.data.a[1].b, 2);
});

// ── the stream with born-frozen events ─────────

test("entry takes the stream's own copy of a born-frozen event; a returned one passes untouched", () => {
  // 13.0.0 (core-api OWNERSHIP): entry copies, so the gate is given the
  // stream's own event, equal in value; inside the propagation a gate's
  // return is shared with the stream and keeps its identity.
  const entered = Event("fx_in", { v: 1, list: [1] });
  let returned;
  let given;
  const s = new Stream();
  s.register(Gate("fx_in", (e) => {
    given = e;
    returned = Event("fx_out", { v: e.data.v });
    return returned;
  }));
  s.emit(entered);
  assert(given !== entered, "the gate is given the stream's own copy, not the entering code's event");
  assert(given.data !== entered.data && given.data.list !== entered.data.list, "the copy shares no object with the entering code's event");
  assertEqual(`${given.type}:${given.data.v}:${given.data.list[0]}`, "fx_in:1:1", "the copy reads the same");
  assert(Event.is(given) && isFrozen(given) && isFrozen(given.data), "the copy is a frozen event");
  const out = s.takeResidue()[0];
  assert(out === returned, "the residue is the very event the gate built");
});

test("an event a gate builds is read-only inside the gate, before it is returned", () => {
  const s = new Stream();
  let inGate;
  s.register(Gate("fx_build", () => {
    const out = Event("fx_out", { v: 1 });
    try {
      out.data.v = 2;
      inGate = true;
    } catch {
      inGate = false;
    }
    return out;
  }));
  s.emit(Event("fx_build"));
  assertEqual(inGate, false, "a write to a just-built event is refused in the gate");
  assertEqual(s.takeResidue()[0].data.v, 1);
});

test("a gate writing its born-frozen input rejects as a throw, and commits nothing", () => {
  const s = new Stream();
  s.register(Gate("fx_writer", (e) => { e.data.v = 2; }));
  let r;
  try {
    s.emit(Event("fx_writer", { v: 1 }));
  } catch (err) {
    r = err;
  }
  assert(r !== undefined, "a writing gate settled");
  const p = API.rejectionProblems(r, { code: "SISO_GATE_THREW", eventId: 1, eventType: "fx_writer", entry: true });
  assert(p.length === 0, p.join("; "));
  assert(r.cause instanceof TypeError, `the cause is the refusal: ${String(r.cause)}`);
  assertEqual(s.sampleHere().residue.length, 0);
});

test("R3 concession: rawset on a born-frozen event is not refused (pinned so a change is seen)", () => {
  // JS: no rawset. Reflect.set / defineProperty answer false; a
  // private-field stamp is accepted (SUBSTRATE), and reads nothing back
  // as data.
  const e = Event("t", { v: 1 });
  assertEqual(Reflect.set(e.data, "v", 2), false, "Reflect.set is refused");
  assertEqual(Reflect.defineProperty(e.data, "w", { value: 1 }), false, "Reflect.defineProperty is refused");
  assertEqual(e.data.v, 1);
  class Base { constructor(o) { return o; } }
  class Stamp extends Base {
    #mark = true;
    static on(o) { return #mark in o; }
  }
  let stamped = true;
  try {
    new Stamp(e.data); // eslint-disable-line no-new
  } catch {
    stamped = false;
  }
  assertEqual(stamped, true, "a private-field stamp on a frozen object is accepted (V8 today)");
  assert(Stamp.on(e.data), "the stamp is on the object");
  assertEqual(keysOf(e.data), "v", "the stamp is no field: nothing reads it as data");
});

H.finish("stream-born-frozen");
