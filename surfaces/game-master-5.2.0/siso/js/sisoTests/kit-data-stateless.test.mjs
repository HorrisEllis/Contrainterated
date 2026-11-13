// kit-data-stateless — nothing js/code/kit/data.mjs hands out gives a gate
// an object it could write and later read back (kit-api; core-api
// STATELESS THROUGH THE EVENT). Port of
// lua/sisoTests/kit-data-stateless.test.lua case by case.
//   node js/sisoTests/kit-data-stateless.test.mjs  (from the project root)
// Expected values: the kit-api contract's (js/code/contracts/kit_api.mjs,
// its SUBSTRATE in particular); the counting probe is core-api's
// check.readonly "count" gate, run here on what kit/data.mjs hands out
// directly, without a stream. Freezing is the stream's and is checked in
// kit-data-frozen.
//
// Ported as JavaScript's counterpart (kit-api SUBSTRATE: Lua's marks
// vanish; an array is an Array, JSON null is null):
//   - "data.null answers getmetatable with readonly:kit.data.null" →
//     data.null is null (no sentinel object).
//   - "a marked array answers getmetatable with kit.data.array" →
//     data.array returns the same Array with its prototype unchanged.
//   - "decoded arrays answer the string mark, decoded null is data.null"
//     → decoded arrays are Arrays (Array.prototype), objects plain,
//     null is null.
//   - "nothing reachable ... answers getmetatable with a table" → every
//     object reachable from what kit/data.mjs hands out has an intrinsic
//     prototype (Object.prototype, Array.prototype) or none, holds data
//     properties only, and is fresh per call; the module's exports and
//     everything reachable from them short of the intrinsics are frozen.
//   - "a gate cannot carry a count through the marks" → a gate cannot
//     carry a count through what kit/data.mjs hands out (prototypes short
//     of the intrinsics, the exports, the functions).
//   - "the marks cannot be replaced or stripped" → the exports cannot be
//     replaced, extended or re-prototyped.
//   - "data.null still refuses assignment" → writing to null throws.
//   - "data.array on a table that answers the mark (a frozen marked
//     array)" → on a frozen Array (Object.freeze and the core's).
//   - "data.array raises on any other metatable, table or string" →
//     throws a TypeError on anything that is not an Array.
//   - "is_array: true exactly for kit.data.array" → isArray is true
//     exactly for Arrays (Array.isArray).
//   - "a second load of kit.data agrees on arrays" → a second module
//     instance (a distinct import URL) agrees.
//   - "encode reads a table answering kit.data.array" → encode reads a
//     frozen Array as an array.
//   - "encode refuses a table answering any other metatable" → encode
//     refuses an object whose prototype is not Object.prototype or null,
//     and an Array whose prototype is not Array.prototype.
// Cannot apply: none.
import * as H from "../code/harness.mjs";
import { data } from "../code/kit/data.mjs";
import { Event } from "../code/core/event.mjs";

const { test, assert, assertEqual, assertThrows } = H;
const { array } = data;

const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");
const INTRINSIC = new Set([Object.prototype, Array.prototype, Function.prototype]);

// every object reachable from v by own properties (values and accessor
// functions) and prototypes, short of the intrinsics above; v included
function reach(v) {
  const out = [];
  const seen = new Set();
  const stack = [v];
  while (stack.length > 0) {
    const x = stack.pop();
    if (!isObject(x) || seen.has(x) || INTRINSIC.has(x)) continue;
    seen.add(x);
    out.push(x);
    stack.push(Object.getPrototypeOf(x));
    for (const k of Reflect.ownKeys(x)) {
      const d = Object.getOwnPropertyDescriptor(x, k);
      if ("value" in d) stack.push(d.value);
      else stack.push(d.get, d.set);
    }
  }
  return out;
}

const handedOut = () => [
  data.null, array([]), array([1, array([data.null])]),
  data.decode('{"a":[1,[],{"b":null}],"n":null,"o":{"l":[[true]]}}')[0],
  data.decode("[]")[0], data.decode("null")[0],
];

// ── what null and arrays are ──────────────────

test("data.null is the primitive null (no sentinel object)", () => {
  assert(data.null === null);
  assertEqual(typeof data.null, "object");
  assert(!isObject(data.null), "nothing to hold state in");
});

test("data.array returns the same Array with its prototype unchanged", () => {
  for (const [label, t] of [["one member", [1]], ["empty", []]]) {
    const before = Reflect.ownKeys(t).map(String).join(",");
    assert(array(t) === t, label);
    assertEqual(Object.getPrototypeOf(t), Array.prototype, label);
    assertEqual(Reflect.ownKeys(t).map(String).join(","), before, `${label}: nothing attached`);
    assert(Object.isExtensible(t), `${label}: not frozen or sealed by the mark`);
  }
});

test("decoded arrays are Arrays, objects plain, decoded null is null", () => {
  const [v] = data.decode('{"a":[1,[],{"b":null}],"n":null}');
  assertEqual(Object.getPrototypeOf(v.a), Array.prototype);
  assertEqual(Object.getPrototypeOf(v.a[1]), Array.prototype);
  assertEqual(Object.getPrototypeOf(v.a[2]), Object.prototype, "an object is plain");
  assert(v.n === null && v.a[2].b === null, "decoded null is null");
});

test("nothing reachable from what kit/data.mjs hands out is a shared or non-data object", () => {
  handedOut().forEach((h, i) => {
    for (const o of reach(h)) {
      const proto = Object.getPrototypeOf(o);
      assert(proto === null || proto === Object.prototype || (proto === Array.prototype && Array.isArray(o)),
        `value ${i} reaches an object whose prototype is not an intrinsic`);
      for (const k of Reflect.ownKeys(o)) {
        const d = Object.getOwnPropertyDescriptor(o, k);
        assert("value" in d && typeof d.value !== "function", `value ${i} has an accessor or a function at ${String(k)}`);
      }
    }
  });
  const [a] = data.decode('{"o":{},"l":[]}');
  const [b] = data.decode('{"o":{},"l":[]}');
  assert(a !== b && a.o !== b.o && a.l !== b.l, "two decodes share an object");
});

test("the module's exports and everything reachable from them are frozen", () => {
  for (const o of reach(data)) assert(Object.isFrozen(o), `an object reachable from data is not frozen: ${H.show(o)}`);
  for (const k of ["array", "isArray", "encode", "decode"]) {
    assertEqual(data[k].prototype, undefined, `data.${k} hands out no prototype object`);
  }
});

// core-api check.readonly's probe: a gate that counts through what its
// input's prototypes and the kit's exports hand out. Run twice on fresh
// inputs, a second run sees 0 when nothing handed out is writable state.
// The input's own objects are fresh per call (a write there is carried
// nowhere), so the targets are what outlives a call: their prototypes and
// the kit's exports and functions. Intrinsics are never written.
function countThroughKit(input) {
  let n = 0;
  const targets = new Set(reach(data));
  for (const o of reach(input)) targets.add(Object.getPrototypeOf(o));
  for (const target of targets) {
    if (!isObject(target) || INTRINSIC.has(target)) continue;
    try {
      target.n = (target.n || 0) + 1;
      n += target.n;
    } catch {
      // refused: nothing counted
    }
  }
  return n;
}

test("a gate cannot carry a count from one call to the next through what kit/data.mjs hands out", () => {
  const input = () => ({ l: array([data.null, array([])]), d: data.decode("[null,[1]]")[0], n: data.null });
  countThroughKit(input());
  const before = reach(data).length;
  assertEqual(countThroughKit(input()), 0, "second call");
  assertEqual(reach(data).length, before, "nothing was added to the kit");
});

test("the exports cannot be replaced, extended or re-prototyped", () => {
  assertThrows(() => { data.null = {}; }, "read only", "data.null");
  assertThrows(() => { data.array = (t) => t; }, "read only", "data.array");
  assertThrows(() => { data.extra = 1; }, "not extensible", "a new export");
  assertThrows(() => { delete data.isArray; }, "delete", "deleting an export");
  assertThrows(() => { Object.setPrototypeOf(data, {}); }, "", "re-prototyping");
  assertThrows(() => { data.encode.cache = {}; }, "not extensible", "a field on a function");
  assert(data.null === null, "still null after the attempts");
});

// ── null cannot be written ────────────────────

test("data.null refuses assignment and still reads as null", () => {
  assertThrows(() => { data.null.x = 1; }, "null");
  assertThrows(() => { data.null[1] = true; }, "null");
  assertEqual(String(data.null), "null");
  assertEqual(data.encode(data.null)[0], "null");
});

// ── data.array and isArray ────────────────────

test("data.array on an Array returns that Array", () => {
  const t = [1];
  assert(array(t) === t);
  const [d] = data.decode("[1]");
  assert(array(d) === d, "a decoded array");
});

test("data.array on a frozen Array returns it", () => {
  const f = Object.freeze([1, 2]);
  assert(array(f) === f, "Object.freeze");
  const c = Event("t", { l: [1, 2] }).data.l;
  assert(Object.isFrozen(c), "the core froze it");
  assert(array(c) === c, "the core's frozen Array");
});

test("rejection: data.array throws a TypeError on anything that is not an Array", () => {
  class Opaque {}
  for (const [label, v] of [
    ["a plain object", {}],
    ["an array-like", { length: 0 }],
    ["a class instance", new Opaque()],
    ["a typed array", new Uint8Array(1)],
    ["a string", "kit.data.array"],
    ["null", null],
    ["undefined", undefined],
    ["an event's data", Event("t", { k: 1 }).data],
  ]) {
    let err;
    try {
      array(v);
    } catch (e) {
      err = e;
    }
    assert(err instanceof TypeError && err.message.includes("not an Array"), `${label}: ${H.show(err && err.message)}`);
  }
});

test("isArray: true exactly for Arrays", () => {
  class Sub extends Array {}
  assert(data.isArray(array([])));
  assert(data.isArray(Object.freeze([1])), "a frozen Array");
  assert(data.isArray(data.decode("[1]")[0]), "a decoded Array");
  assert(data.isArray(Sub.from([1])), "an Array subclass is an Array (encode refuses it)");
  assert(!data.isArray({ length: 1, 0: 1 }), "an array-like");
  assert(!data.isArray(new Uint8Array(1)), "a typed array");
  assert(!data.isArray(data.null), "null");
  assert(!data.isArray("kit.data.array"), "a string");
});

// a distinct URL is a separate module instance (loaded before the tests,
// which are synchronous)
const other = (await import(new URL("../code/kit/data.mjs?second-instance", import.meta.url).href)).data;

test("nothing carries identity: a second module instance agrees on arrays and null", () => {
  assert(other !== data, "a separate module instance");
  const a = array([1]);
  assert(other.isArray(a), "the other instance sees this one's array");
  assert(data.isArray(other.array([])), "this instance sees the other's array");
  assertEqual(other.encode(a)[0], "[1]");
  assert(other.array(a) === a, "the other's data.array returns this one's array");
  assert(other.null === data.null, "one null");
  assertEqual(data.encode(other.decode('{"l":[null]}')[0])[0], '{"l":[null]}', "the other's decoded value encodes here");
});

// ── encode's prototype rule ───────────────────

test("encode reads a frozen Array as an array", () => {
  assertEqual(data.encode(Object.freeze([]))[0], "[]");
  assertEqual(data.encode(Object.freeze([1, "x", data.null]))[0], '[1,"x",null]');
  assertEqual(data.encode({ a: Event("t", { l: [2.5] }).data.l })[0], '{"a":[2.5]}', "the core's frozen Array");
});

test("rejection: encode refuses an object or Array whose prototype is not the plain one", () => {
  class Sub extends Array {}
  for (const [label, v, fragment] of [
    ["Object.create of an object", Object.create({}), "an object that is not plain"],
    ["Object.create of an event's data", Object.create(Event("t", { k: 1 }).data), "an object that is not plain"],
    ["a Map", new Map(), "an object that is not plain"],
    ["a Date", new Date(0), "an object that is not plain"],
    ["a typed array", new Uint8Array(1), "an object that is not plain"],
    ["an Array subclass", Sub.from([1]), "prototype is not Array.prototype"],
    ["an Array re-prototyped", Object.setPrototypeOf([1], Object.create(Array.prototype)), "prototype is not Array.prototype"],
  ]) {
    const [s, why] = data.encode(v);
    assertEqual(s, null, label);
    assert(typeof why === "string" && why.includes(fragment), `${label}: ${H.show(why)}`);
  }
});

test("rejection: an Array with extra keys is still refused", () => {
  const t = [1];
  t.x = 2;
  const [s, why] = data.encode(t);
  assertEqual(s, null);
  assert(typeof why === "string" && why.includes("array has keys other than its indices"), H.show(why));
});

H.finish("kit-data-stateless");
