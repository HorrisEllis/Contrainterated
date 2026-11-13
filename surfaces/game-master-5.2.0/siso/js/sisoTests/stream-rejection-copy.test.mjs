// stream-rejection-copy.test.mjs — each observer is shown its own
// read-only copy of the rejection (js-observer-seam THE REJECTION, core
// 13), while the entering code receives the very rejection thrown,
// `cause` included. Port of lua/sisoTests/stream-rejection-copy.test.lua.
// Runs the observer-seam checks on the real core, then pins each code, the
// copies' independence, and that a copy is built inside its observer's
// protected call. The gates below are fixtures for the stream, not project
// gates.
//   node js/sisoTests/stream-rejection-copy.test.mjs
//
// Adapted to the substrate (js-core-api / js-observer-seam SUBSTRATE),
// case by case:
//   - The refusal: Lua's "read-only: …" error becomes the language's own
//     TypeError (Object.freeze, strict code).
//   - Lua's thrown table `{ "the gate's own", nested = {1}, message =
//     "bad" }` (list part and named fields at once) is an array carrying
//     named properties; Lua's `rawset` probes become the substrate's own
//     attempts (Reflect.set, Reflect.defineProperty, a private-field
//     stamp).
//   - "a copy that cannot be built": Lua's poisoned table (an __index and
//     __pairs that raise) is a Proxy whose traps throw; the rejection
//     still reads its message raw and under protection (X1), so only the
//     observer's copy fails.
// No case is left out.
import * as H from "../code/harness.mjs";
import * as API from "../code/contracts/core_api.mjs";
import * as SEAM from "../code/contracts/observer_seam.mjs";
import { Event, Gate, Stream } from "../code/core/index.mjs";

const core = { Event, Gate, Stream };
const { test, assert, assertEqual } = H;

const noProblems = (list, label) => assert(list.length === 0, `${label ? `${label}: ` : ""}${list.join("; ")}`);
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
const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");
class Base { constructor(o) { return o; } }
class Stamp extends Base {
  #mark = true;
  static put(o) {
    try {
      if (!(#mark in o)) new Stamp(o); // eslint-disable-line no-new
    } catch {
      // refused
    }
  }
  static on(o) { return isObject(o) && #mark in o; }
}
const attempt = (o, k, v) => {
  Reflect.set(o, k, v);
  Reflect.defineProperty(o, k, { value: v });
  Stamp.put(o);
};

// Emit `entry` on `s` with `n` recording observers attached; → the thrown
// rejection (undefined when it settled) and each observer's batches.
const watched = (s, entry, n) => {
  const seen = [];
  for (let i = 0; i < n; i += 1) {
    const into = [];
    seen.push(into);
    s.attach((b) => { into.push(b); });
  }
  try {
    s.emit(entry);
    return [undefined, seen];
  } catch (raised) {
    return [raised, seen];
  }
};

// ── the contract on the real core ──────────────

test("observer-seam: check.seam holds on the core", () => {
  noProblems(SEAM.check.seam(core));
});

test("observer-seam: check.ownership holds on the core (records and the rejection)", () => {
  noProblems(SEAM.check.ownership(core));
});

// ── every code: a copy for the observer, the rejection itself for the host ──

const cases = [
  ["SISO_SELF_LOOP", () => {
    const s = new Stream();
    s.register(Gate("fx_loop", () => Event("fx_loop")));
    return [s, Event("fx_loop")];
  }],
  ["SISO_CYCLE", () => {
    const s = new Stream();
    s.register(Gate("fx_a", () => Event("fx_b")));
    s.register(Gate("fx_b", () => Event("fx_a")));
    return [s, Event("fx_a")];
  }],
  ["SISO_GATE_THREW", () => {
    const s = new Stream();
    s.register(Gate("fx_boom", () => { throw new Error("boom"); }));
    return [s, Event("fx_boom")];
  }],
  ["SISO_NOT_EVENT", () => {
    const s = new Stream();
    s.register(Gate("fx_bad", () => 42));
    return [s, Event("fx_bad")];
  }],
  ["SISO_NOT_EVENT", () => [new Stream(), { type: "not", data: {} }]],
];

test("each code: two observers each get their own read-only copy; the host gets the rejection itself", () => {
  cases.forEach(([code, build], i) => {
    const where = `${code} (case ${i + 1})`;
    const [s, entry] = build();
    const [raised, seen] = watched(s, entry, 2);
    assert(raised !== undefined, `${where}: settled`);
    noProblems(API.rejectionProblems(raised, { code }), where);
    assertEqual(String(raised), raised.message, `${where}: String of the thrown rejection`);
    assertEqual(seen[0].length, 1, `${where}: observer 1 batches`);
    assertEqual(seen[1].length, 1, `${where}: observer 2 batches`);
    const a = seen[0][0].rejection;
    const b = seen[1][0].rejection;
    const why = SEAM.rejectionCopyProblem(a, raised) ?? SEAM.rejectionCopyProblem(b, raised);
    assert(why === null, `${where}: ${why}`);
    assert(a !== b, `${where}: the two observers share one copy`);
    refuses(`${where}: assigning to a copy`, () => { a.code = "X"; });
    assertEqual(a.message, raised.message, `${where}: the copy's message is read by indexing`);
  });
});

test("SISO_GATE_THREW: the host's cause is exactly what the gate raised; each copy's cause is its own", () => {
  const thrown = Object.assign(["the gate's own"], { nested: [1], message: "bad" });
  const s = new Stream();
  s.register(Gate("fx_throw", () => { throw thrown; }));
  const [raised, seen] = watched(s, Event("fx_throw"), 2);
  assert(raised.cause === thrown, "the host receives the very value thrown as cause");
  const a = seen[0][0].rejection;
  const b = seen[1][0].rejection;
  assert(a.cause !== thrown && b.cause !== thrown, "a copy's cause is not the thrown value");
  assert(a.cause !== b.cause, "two copies share one cause");
  assert(a.cause.nested !== thrown.nested, "a copy's nested cause is its own");
  assertEqual(a.cause[0], "the gate's own");
  assertEqual(a.cause.nested[0], 1);
  assertEqual(a.cause.message, "bad");
  assert(Array.isArray(a.cause), "an array cause copies to an Array");
  refuses("assigning into a copy's cause", () => { a.cause.nested[0] = 9; });
  attempt(a, "code", "CHANGED");
  attempt(a.cause, 0, "CHANGED");
  assertEqual(raised.code, "SISO_GATE_THREW", "an attempt on a copy reached the thrown rejection");
  assert(!Stamp.on(raised), "a stamp on a copy reached the thrown rejection");
  assertEqual(thrown[0], "the gate's own", "an attempt on a copy reached what the gate threw");
  assert(!Stamp.on(thrown), "a stamp on a copy reached what the gate threw");
  assertEqual(b.code, "SISO_GATE_THREW", "an attempt on one copy reached another");
  assertEqual(b.cause[0], "the gate's own", "an attempt on one copy's cause reached another");
  assert(!Stamp.on(b) && !Stamp.on(b.cause), "a stamp on one copy reached another");
});

test("a non-table cause is carried as it is", () => {
  const s = new Stream();
  s.register(Gate("fx_str", () => { throw "plain string"; })); // eslint-disable-line no-throw-literal
  const [raised, seen] = watched(s, Event("fx_str"), 1);
  assertEqual(raised.cause, "plain string");
  assertEqual(seen[0][0].rejection.cause, "plain string");
});

test("a settled batch carries no rejection; with no observer the rejection is raised all the same", () => {
  const s = new Stream();
  const [raised, seen] = watched(s, Event("fx_free"), 1);
  assertEqual(raised, undefined);
  assertEqual(seen[0][0].outcome, "settled");
  assertEqual(seen[0][0].rejection, undefined);
  assert(!Object.prototype.hasOwnProperty.call(seen[0][0], "rejection"), "the key is absent");
  const t = new Stream();
  t.register(Gate("fx_boom", () => { throw new Error("boom"); }));
  let r;
  try {
    t.emit(Event("fx_boom"));
  } catch (e) {
    r = e;
  }
  assert(r !== undefined);
  noProblems(API.rejectionProblems(r, { code: "SISO_GATE_THREW", eventId: 1 }));
});

// ── the copy is built inside the observer's protected call ──

test("a copy that cannot be built fails that observer alone: emit raises exactly as without observers", () => {
  // A cause on which every read throws: building the rejection reads its
  // message raw and under protection (X1), so the host's rejection is
  // built; the observer's copy (its own properties) cannot be.
  const poisoned = new Proxy({}, {
    get() { throw new Error("poisoned read"); },
    getOwnPropertyDescriptor() { throw new Error("poisoned descriptor"); },
    ownKeys() { throw new Error("poisoned keys"); },
  });
  const run = (n) => {
    const s = new Stream();
    s.register(Gate("fx_poison", () => { throw poisoned; }));
    let called = 0;
    for (let i = 0; i < n; i += 1) s.attach(() => { called += 1; });
    let r;
    try {
      s.emit(Event("fx_poison"));
    } catch (e) {
      r = e;
    }
    const calledThen = called;
    s.emit(Event("fx_after"));
    return [r, calledThen, s.takeResidue().length];
  };
  const [r0] = run(0);
  const [r2, called, left] = run(2);
  assert(r0 !== undefined && r2 !== undefined, "the poisoned gate settled");
  assertEqual(r2.code, r0.code);
  assertEqual(r2.code, "SISO_GATE_THREW");
  assertEqual(r2.message, r0.message, "the same rejection with or without observers");
  assert(r2.cause === poisoned, "the host still receives the very value thrown");
  assertEqual(called, 0, "an observer whose batch could not be built is not called");
  assertEqual(left, 1, "the stream is usable afterwards and committed nothing from the rejection");
});

H.finish("stream-rejection-copy");
