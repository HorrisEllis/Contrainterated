// fixtures-marks: the runner (js/code/fixtures/run.mjs) copies fixture
// data through kit/data.mjs's API (js-kit-api): an Array is copied as an
// Array (data.isArray / data.array), data.null (null) is kept as itself,
// and every other object is a fresh plain object. Nothing it hands a gate
// or Event is the fixture's own object, and no two calls share one. Port
// of lua/sisoTests/fixtures-marks.test.lua.
//   node js/sisoTests/fixtures-marks.test.mjs     (from the project root)
//
// The runner is given a core whose Event and Gate wrap the real ones
// (run(fixture, core)), so the test sees exactly the data the runner
// hands Event, and every transform it builds, before the core touches
// them. That is the counterpart of the Lua suite's package.loaded swap of
// core.event and core.gate.
//
// Turned into JavaScript's counterpart (js-kit-api SUBSTRATE: "Lua's
// marks vanish: an array is an Array, JSON null is null"):
//   - "a marked array stays marked" → the copy of an Array is an Array
//     whose prototype is Array.prototype; "data.null is kept as itself" →
//     null is kept as null; "the copy has no metatable" → every object in
//     the copy has prototype Object.prototype (Array.prototype for an
//     array), and an object is not an Array.
//   - Lua's "raw return" case on an inert stream: the inert stream is a
//     class whose doors take registrations and run nothing; the transform
//     the runner built is then called directly.
// Added (the JavaScript counterpart of "it never copies a metatable"): a
// decoded own "__proto__" key is copied as an own key, so no prototype is
// set on what the runner hands Event, and the fixture passes end to end.
// Cannot apply (no counterpart in JavaScript):
//   - The two test-local kit.data stand-ins, "marks answering strings"
//     (kit-api, core 13.0.1) and "marks answering tables" (the kit before
//     stateless-marks), and their three cases each: JavaScript has no
//     marks and no metatables, so there are no two forms of mark for the
//     runner to be indifferent to. The three cases run once, on the
//     workspace's kit/data.mjs.
import * as H from "../code/harness.mjs";
import { data } from "../code/kit/data.mjs";
import { Event as RealEvent, Gate as RealGate, Stream } from "../code/core/index.mjs";
import { run } from "../code/fixtures/run.mjs";

const joined = (list) => list.join("; ");

// ── a core that shows what the runner hands it ──────────────────

// → { core, entered, transforms }: `entered` is every (type, data) the
// runner handed Event, `transforms` every gate transform it built, by
// signature
function watched(StreamClass = Stream) {
  const entered = [];
  const transforms = new Map();
  const Event = (t, x) => {
    entered.push({ type: t, data: x });
    return RealEvent(t, x);
  };
  const Gate = (sig, fn) => {
    transforms.set(sig, fn);
    return RealGate(sig, fn);
  };
  return { core: { Event, Gate, Stream: StreamClass }, entered, transforms };
}

// data with every kind of value the fixture JSON decodes to
const sample = () => ({ list: data.array([1, "x", data.null]), obj: { k: "v", inner: data.array([2]) }, n: data.null });

const protoOf = (v) => Object.getPrototypeOf(v);

// the whole of a runner's copy of sample(), against the original
function copyProblems(original, got) {
  const p = [];
  const bad = (s) => { p.push(s); };
  if (got === null || typeof got !== "object") return [`the data is ${got === null ? "null" : typeof got}`];
  if (got === original) bad("the data is the fixture's own object, not a copy");
  if (protoOf(got) !== Object.prototype) bad("the data's prototype is not Object.prototype");
  if (data.isArray(got)) bad("the data, an object, is an Array");
  const list = got.list;
  if (list === null || typeof list !== "object") bad(`list is ${typeof list}`);
  else {
    if (list === original.list) bad("list is the fixture's own Array");
    if (!data.isArray(list)) bad("list is no longer an Array");
    else if (protoOf(list) !== Array.prototype) bad("list's prototype is not Array.prototype");
    if (list[0] !== 1 || list[1] !== "x") bad("list's values changed");
    if (list[2] !== data.null) bad("list[2] is not null");
    if (list.length !== 3) bad(`list holds ${list.length} values`);
  }
  const obj = got.obj;
  if (obj === null || typeof obj !== "object") bad(`obj is ${typeof obj}`);
  else {
    if (obj === original.obj) bad("obj is the fixture's own object");
    if (protoOf(obj) !== Object.prototype) bad("obj, an object, has a prototype other than Object.prototype");
    if (data.isArray(obj)) bad("obj, an object, is an Array");
    if (obj.k !== "v") bad("obj.k changed");
    if (!data.isArray(obj.inner) || obj.inner[0] !== 2 || obj.inner.length !== 1) bad("obj.inner lost its value or is no longer an Array");
    else if (obj.inner === original.obj.inner) bad("obj.inner is the fixture's own Array");
  }
  if (got.n !== data.null) bad("n is not null");
  const keys = Object.keys(got).length;
  if (keys !== 3) bad(`the data holds ${keys} fields, expected 3`);
  return p;
}

// what the runner handed Event for `type`, the last time
function enteredAs(entered, t) {
  for (let i = entered.length - 1; i >= 0; i -= 1) if (entered[i].type === t) return entered[i].data;
  return undefined;
}

// ── cases ───────────────────────────────────────────────────────

const KIT = "the workspace's kit/data.mjs";

H.test(`${KIT}: an entry's data is copied`, () => {
  const { core, entered } = watched();
  const d = sample();
  const f = {
    format: 1, name: "an entry with arrays and null", covers: ["Rule 4"], gates: [],
    steps: [{ emit: { type: "q", data: d }, expect: "settled" }],
  };
  const r = run(f, core);
  const p = copyProblems(d, enteredAs(entered, "q"));
  H.assert(p.length === 0, joined(p));
  H.assert(r.passed === r.total, `${r.passed}/${r.total}: ${joined(r.problems)}`);
});

H.test(`${KIT}: a gate's emitted data is copied, every call`, () => {
  const { core, entered } = watched();
  const d = sample();
  const f = {
    format: 1, name: "a gate emitting arrays and null", covers: ["Rule 1"],
    gates: [{ signature: "a", does: { emit: [{ type: "out", data: d }] } }],
    steps: [{ emit: { type: "a" }, expect: "settled" }, { emit: { type: "a" }, expect: "settled" }],
  };
  const r = run(f, core);
  const outs = entered.filter((e) => e.type === "out").map((e) => e.data);
  H.assertEqual(outs.length, 2, "out events built");
  outs.forEach((got, i) => {
    const p = copyProblems(d, got);
    H.assert(p.length === 0, `call ${i + 1}: ${joined(p)}`);
  });
  H.assert(outs[0] !== outs[1], "two calls share one copy");
  H.assert(r.passed === r.total, `${r.passed}/${r.total}: ${joined(r.problems)}`);
});

H.test(`${KIT}: a raw return is copied`, () => {
  // a stream that only takes registrations: no gate runs inside it
  class Inert {
    register() {}
    attach() {}
    emit() {}
  }
  const { core, transforms } = watched(Inert);
  const raw = data.array([1, data.null]);
  const f = {
    format: 1, name: "a raw array", covers: ["Rule 1"],
    gates: [{ signature: "a", does: { raw } }],
    steps: [{ emit: { type: "a" }, expect: { rejected: { code: "SISO_NOT_EVENT" } } }],
  };
  run(f, core);
  // the transform the runner built, called directly: only it runs
  const fn = transforms.get("a");
  H.assert(typeof fn === "function", "the runner built no gate a");
  const returned = fn(RealEvent("a"));
  H.assert(returned !== null && typeof returned === "object", `the gate returned ${typeof returned}`);
  H.assert(returned !== raw, "the raw value is the fixture's own Array");
  H.assert(data.isArray(returned) && protoOf(returned) === Array.prototype, "the raw copy is no longer an Array");
  H.assert(returned[0] === 1 && returned[1] === data.null && returned.length === 2, "the raw copy's values changed");
  H.assert(fn(RealEvent("a")) !== returned, "two calls share one copy");
});

H.test(`${KIT}: a decoded fixture with arrays and null passes end to end`, () => {
  const [f, why] = data.decode(`{"format":1,"name":"decoded arrays","covers":["Rule 4"],
    "gates":[{"signature":"a","does":{"emitOne":{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}}}],
    "steps":[{"emit":{"type":"a","data":{"l":[3]}},"expect":"settled"},
             {"take":[{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}]}]}`);
  H.assert(why === null, String(why));
  const r = run(f);
  H.assert(r.passed === r.total && r.problems.length === 0, `${r.passed}/${r.total}: ${joined(r.problems)}`);
});

H.test(`${KIT}: a decoded "__proto__" key is copied as an own key, setting no prototype`, () => {
  const { core, entered } = watched();
  const [f, why] = data.decode(`{"format":1,"name":"an own __proto__ key","covers":["Rule 4"],"gates":[],
    "steps":[{"emit":{"type":"q","data":{"__proto__":{"x":1}}},"expect":"settled"},
             {"take":[{"type":"q","data":{"__proto__":{"x":1}}}]}]}`);
  H.assert(why === null, String(why));
  const r = run(f, core);
  const got = enteredAs(entered, "q");
  H.assert(got !== null && typeof got === "object", "no data handed to Event");
  H.assert(protoOf(got) === Object.prototype, "the copy's prototype was set");
  const desc = Object.getOwnPropertyDescriptor(got, "__proto__");
  H.assert(desc !== undefined && "value" in desc && desc.value.x === 1, "the copy lost its own __proto__ key");
  H.assert(desc.value !== f.steps[0].emit.data.__proto__, "the copy shares the fixture's object");
  H.assert(r.passed === r.total && r.problems.length === 0, `${r.passed}/${r.total}: ${joined(r.problems)}`);
});

H.finish("fixtures-marks");
