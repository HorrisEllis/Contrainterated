// kit-data-frozen — js/code/kit/data.mjs's data.encode reads frozen data
// (core-api READ-ONLY) as the plain data it presents (kit-api), so
// encode(freeze(v)) === encode(v), and residue, samples and observer
// records from a real core Stream serialise as handed out. Port of
// lua/sisoTests/kit-data-frozen.test.lua case by case.
//   node js/sisoTests/kit-data-frozen.test.mjs   (from the project root)
// Expected values: the encoding of the same data built plainly (the
// plain-data encoder is pinned by kit-data.test.mjs), and for the fixed
// samples a literal canonical text written by hand.
//
// `freeze` is the real core's (kit-api SUBSTRATE: any function returning a
// frozen version of plain data): v => Event("t", { v }).data.v, the core's
// own frozen deep copy (core-api EVENT; wrapping lets any value, an array
// or a primitive too, be frozen).
// Ported as JavaScript's counterpart:
//   - "marks answered through getmetatable" / "getmetatable of a frozen
//     unmarked table is false" / "next sees contents" → a frozen Array is
//     an Array, a frozen object's prototype is Object.prototype, and
//     Object.keys reads a frozen object's contents.
//   - "marked arrays" / "unmarked arrays (keys exactly 1..n)" → Arrays
//     (every Array is an array); "an empty unmarked table is an object" →
//     an empty Array is "[]", an empty object "{}".
//   - "floats kept apart from integers" → no float subtype: 1.0 is 1, -0
//     is "0", 2^53 keeps the float form "9007199254740992.0" (kit-api
//     13.1.0); integers at the edges are the safe-integer limits.
//   - "null keeps its identity" → frozen data's null is null.
//   - "invalid UTF-8" → a lone surrogate.
//   - refusals: the core's Event itself refuses a sparse array and an
//     Array with a named key (core-api: not plain data), and copies only
//     string keys, so those refusals are frozen with the language's
//     Object.freeze at every depth (what any holder of frozen data may
//     hand encode); NaN, Infinity, a lone surrogate and the nesting limit
//     go through the core's freeze.
//   - "a non-string, non-number key" → a symbol key; "a foreign
//     metatable" → a class instance or Map, plain and frozen.
//   - nesting: Lua's nest(n) wraps an empty table n times; the same counts
//     here (511 wraps = 512 objects pass, 512 wraps are refused).
// Cannot apply: none (the kit-api check takes no sha256 or helper module).
import * as H from "../code/harness.mjs";
import * as K from "../code/contracts/kit_api.mjs";
import { data } from "../code/kit/data.mjs";
import { Event } from "../code/core/event.mjs";
import { Gate } from "../code/core/gate.mjs";
import { Stream } from "../code/core/stream.mjs";

const { test, assert, assertEqual } = H;
const { array } = data;

const freeze = (v) => Event("t", { v }).data.v;

// the language's freeze at every depth (for values the core refuses)
function deepFreeze(v) {
  if (v !== null && typeof v === "object" && !Object.isFrozen(v)) {
    for (const k of Reflect.ownKeys(v)) deepFreeze(v[k]);
    Object.freeze(v);
  }
  return v;
}

function enc(v) {
  const [s, err] = data.encode(v);
  if (s === null) H.fail(`encode failed: ${err}`);
  return s;
}

function encErr(v, fragment) {
  const [s, err] = data.encode(v);
  assertEqual(s, null, "encode should fail");
  assert(typeof err === "string" && err.includes(fragment), `error ${H.show(err)} lacks '${fragment}'`);
}

// encode(freeze(build())) === encode(build()), and both equal `want` when given
function same(build, want) {
  const plain = enc(build());
  const frozenValue = freeze(build());
  assert(frozenValue === null || typeof frozenValue !== "object" || Object.isFrozen(frozenValue), "the value was frozen");
  const frozen = enc(frozenValue);
  assertEqual(frozen, plain, "frozen vs plain");
  if (want !== undefined) assertEqual(plain, want, "plain text");
}

// build() is refused plainly and frozen (by `by`), with the same message
function refusedAlike(build, fragment, by = freeze) {
  encErr(build(), fragment);
  const [, plainWhy] = data.encode(build());
  const f = by(build());
  assert(Object.isFrozen(f), "the value was frozen");
  const [, frozenWhy] = data.encode(f);
  assertEqual(frozenWhy, plainWhy, "frozen refusal vs plain refusal");
}

// ── the contract ──────────────────────────────

test("kit-api check passes with freeze given (frozen data reads as plain)", () => {
  const p = K.check(data, { freeze });
  assert(p.length === 0, p.join("; "));
});

test("freezing really happened: writes refused, Arrays and objects answer as before", () => {
  const f = freeze({ a: array([1]), o: {} });
  H.assertThrows(() => { f.x = 1; }, "", "a frozen object accepted a write");
  H.assertThrows(() => { f.a.push(2); }, "", "a frozen Array accepted a push");
  assert(data.isArray(f.a), "a frozen Array is not an array");
  assert(!data.isArray(f.o), "a frozen object is an array");
  assertEqual(Object.getPrototypeOf(f.o), Object.prototype, "a frozen object's prototype");
  assert(freeze(data.null) === data.null, "null did not pass through freezing");
  assertEqual(Object.keys(f).join(","), "a,o", "Object.keys reads a frozen object's contents");
});

// ── encode(freeze(v)) === encode(v) ───────────

test("nested objects", () => {
  same(() => ({ z: { y: { x: { w: "deep" } } }, a: { b: 1, c: { d: true } } }),
    '{"a":{"b":1,"c":{"d":true}},"z":{"y":{"x":{"w":"deep"}}}}');
});

test("arrays, nested and of objects", () => {
  same(() => array([array([1, 2]), { k: "v" }, array([array([])])]), '[[1,2],{"k":"v"},[[]]]');
});

test("arrays inside objects", () => {
  same(() => ({ l: [3, 2, 1], m: [[1], ["a", "b"]] }), '{"l":[3,2,1],"m":[[1],["a","b"]]}');
});

test("empty arrays and empty objects", () => {
  same(() => [], "[]");
  same(() => ({ e: [], o: {} }), '{"e":[],"o":{}}');
  same(() => ({}), "{}");
});

test("numbers: no float subtype, -0 is 0", () => {
  same(() => [1, 1.0, -0, 0.1, 1e21, 5e-324, 2 ** 53, 1.5], "[1,1,0,0.1,1e+21,5e-324,9007199254740992.0,1.5]");
});

test("null, in arrays and objects, stays null", () => {
  same(() => ({ n: data.null, l: [data.null, 1, data.null] }), '{"l":[null,1,null],"n":null}');
  const f = freeze({ n: data.null });
  assert(f.n === data.null, "frozen data's null is not null");
});

test("strings, escapes and non-ASCII", () => {
  same(() => ({ s: 'a"b\\c\n\u0000', u: "ääkköset ✓", "ü": "", "\u{1F600}": 1 }),
    '{"s":"a\\"b\\\\c\\n\\u0000","u":"ääkköset ✓","ü":"","\u{1F600}":1}');
});

test("booleans and integers at the edges", () => {
  same(() => [true, false, Number.MAX_SAFE_INTEGER, Number.MIN_SAFE_INTEGER, 0],
    "[true,false,9007199254740991,-9007199254740991,0]");
});

test("a frozen object inside plain data", () => {
  const inner = freeze({ k: array([1, 2.5]) });
  assertEqual(enc({ wrap: inner, l: [inner] }), '{"l":[{"k":[1,2.5]}],"wrap":{"k":[1,2.5]}}');
});

// ── refusals still refuse when frozen ─────────

test("rejection: a hole in a frozen Array is refused (the core refuses it too)", () => {
  // eslint-disable-next-line no-sparse-arrays
  H.assertThrows(() => freeze([1, , 3]), "not plain data", "the core's Event");
  // eslint-disable-next-line no-sparse-arrays
  refusedAlike(() => [1, , 3], "array has a hole", deepFreeze);
});

test("rejection: an Array with a named key, plain and frozen", () => {
  const named = () => { const t = [1, 2]; t.x = 3; return t; };
  H.assertThrows(() => freeze(named()), "not plain data", "the core's Event");
  refusedAlike(named, "array has keys other than its indices", deepFreeze);
});

test("rejection: an Array with a hole or a named key, nested and frozen", () => {
  refusedAlike(() => { const h = []; h[0] = 1; h[2] = 3; return { h }; }, "array has a hole", deepFreeze);
  refusedAlike(() => { const t = [1]; t.x = 2; return [t]; }, "array has keys other than its indices", deepFreeze);
});

test("rejection: a symbol key, frozen", () => {
  refusedAlike(() => ({ [Symbol("k")]: 1, a: 2 }), "not a string", deepFreeze);
});

test("rejection: NaN, Infinity and a lone surrogate inside frozen data", () => {
  refusedAlike(() => ({ x: NaN }), "NaN is not allowed");
  refusedAlike(() => ({ x: "\uDFFF" }), "lone surrogate");
  refusedAlike(() => ({ x: array([1, Infinity]) }), "Infinity is not allowed");
});

test("rejection: a class instance is refused, plain and frozen", () => {
  class Opaque { constructor() { this.v = 1; } }
  encErr(new Opaque(), "not plain");
  refusedAlike(() => new Opaque(), "not plain", deepFreeze);
  refusedAlike(() => ({ m: new Map() }), "not plain", deepFreeze);
});

test("rejection: nesting limit holds on frozen data", () => {
  const nest = (n) => { let v = {}; for (let i = 0; i < n; i += 1) v = [v]; return v; };
  same(() => nest(511));
  refusedAlike(() => nest(512), "nesting too deep");
});

// ── real stream: residue, samples, observer records ──

// What the gate returns, built plainly each time it is called.
function payload(v) {
  return {
    v,
    f: 2.5,
    whole: 3.0,
    n: data.null,
    s: "ä\t",
    list: [1, 2, 3],
    marked: array([{ k: "x" }, array([])]),
    empty: array([]),
    obj: { deep: { deeper: array([true, false]) } },
  };
}

const entryData = () => ({ v: 7, tags: array(["a", "b"]), meta: { at: 1.5 } });

function newStream() {
  const s = new Stream();
  s.register(Gate("in", (e) => [Event("out", payload(e.data.v)), Event("plain", { only: e.data.tags })]));
  return s;
}

// plain encodings to compare with
const plainEvents = () => ({
  out: enc(payload(7)),
  plain: enc({ only: array(["a", "b"]) }),
  in: enc(entryData()),
});

function byType(events) {
  const got = {};
  for (const e of events) got[e.type] = enc(e.data);
  return got;
}

test("residue from takeResidue encodes as the plain data", () => {
  const s = newStream();
  s.emit(Event("in", entryData()));
  const taken = s.takeResidue();
  assertEqual(taken.length, 2, "residue size");
  H.assertThrows(() => { taken[0].data.v = 0; }, "", "taken residue is not frozen");
  const want = plainEvents();
  const got = byType(taken);
  assertEqual(got.out, want.out, "out");
  assertEqual(got.plain, want.plain, "plain");
});

test("events from sampleHere encode as the plain data", () => {
  const s = newStream();
  s.emit(Event("in", entryData()));
  const { residue } = s.sampleHere();
  H.assertThrows(() => { residue[0].data.v = 0; }, "", "sampled residue is not frozen");
  const want = plainEvents();
  const got = byType(residue);
  assertEqual(got.out, want.out, "out");
  assertEqual(got.plain, want.plain, "plain");
  // a whole residue list of events encodes as built plainly
  const list = [...residue].sort((a, b) => (a.type < b.type ? -1 : 1));
  const plainList = ["out", "plain"].map((t) => ({ type: t, data: t === "out" ? payload(7) : { only: array(["a", "b"]) } }));
  assertEqual(enc(list), enc(plainList), "residue list (the events themselves)");
});

test("records' data from an observer batch encode as the plain data", () => {
  const s = newStream();
  const batches = [];
  s.attach((b) => { batches.push(b); });
  s.emit(Event("in", entryData()));
  assertEqual(batches.length, 1, "batches");
  const { records } = batches[0];
  assertEqual(records.length, 3, "records");
  const want = plainEvents();
  let seen = 0;
  for (const r of records) {
    H.assertThrows(() => { r.data.v = 0; }, "", "record data is not frozen");
    assertEqual(enc(r.data), want[r.eventType], `record ${r.eventType}`);
    seen += 1;
  }
  assertEqual(seen, 3, "records compared");
});

H.finish("kit-data-frozen");
