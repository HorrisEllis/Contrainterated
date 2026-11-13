// kit-data — js/code/kit/data.mjs (canonical JSON; null and Arrays), port
// of lua/sisoTests/kit-data.test.lua case by case, then the kit-api
// contract check on the real module.
//   node js/sisoTests/kit-data.test.mjs        (from the project root)
// Expected values come from outside the code: number texts are
// ECMAScript's Number::toString (Node 22 `String(x)`), the values the Lua
// suite pasted from Node's output with its ".0" suffix for integral floats
// removed, and -0 rendered "0" (kit-api canonical form).
//
// Ported as JavaScript's counterpart (kit-api SUBSTRATE):
//   - "null ... answers readonly:kit.data.null" → data.null is the
//     primitive null (no sentinel, nothing to write or to answer).
//   - "array: marks, is idempotent ..." / "a table that answers the mark"
//     → data.array returns the same Array unchanged and throws on a
//     non-Array; every Array, frozen ones included, is an array.
//   - "integers and floats are kept apart" → JS has no float subtype:
//     1.0 is 1, -0 is "0", and a non-integral float (1.5) survives.
//   - 64-bit integer limits → the safe-integer limits (±(2^53 - 1)).
//   - "invalid UTF-8 is rejected" (bytes) → a lone surrogate is rejected
//     (a JS string holds UTF-16 code units, not bytes).
//   - "a table with holes or mixed keys" → a JS object's keys are strings,
//     so {2: "x"} and {1.5: "x"} are objects; holes and named keys are
//     Array cases ("a marked array with a hole or extra keys").
//   - "values that are not data": userdata, a thread, a foreign metatable
//     and a boolean key → a bigint, a symbol, class instances (Map, Date,
//     an Array subclass, Object.create of a non-null prototype), a symbol
//     key and a getter.
//   - "the modules require nothing outside code/kit/" → data.mjs imports
//     nothing.
//   - error paths index arrays from 0 ($.x[1], $.a.b[1].c).
//   - "10,000 floats come back bit-exact" skips -0 (canonically "0").
//     An integral number past the safe range keeps Lua's float form
//     (kit-api 13.1.0: ".0" appended when String(n) has neither "." nor an
//     exponent), so it round-trips; the next case pins that.
// Cannot apply (not ported, kit-api SUBSTRATE: kit.sha256 is not part of
// the JavaScript kit): every sha256 case — the empty message, "abc", the
// 448- and 896-bit messages, one million 'a', the 14 padding-boundary
// lengths, all 256 byte values, the hex shape, independent calls and the
// non-string raise. The kit-api check runs on data alone (no sha256 or
// helper module to pass it).
import * as H from "../code/harness.mjs";
import * as K from "../code/contracts/kit_api.mjs";
import * as mod from "../code/kit/data.mjs";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const { test, assert, assertEqual, assertThrows } = H;
const { data } = mod;
const { array } = data;

function enc(v) {
  const [s, err] = data.encode(v);
  if (s === null) H.fail(`encode failed: ${err}`);
  return s;
}

function encErr(v, fragment) {
  const r = data.encode(v);
  assert(Array.isArray(r) && r.length === 2, "encode returns [text, why]");
  assertEqual(r[0], null, "encode should fail");
  assert(typeof r[1] === "string" && r[1].includes(fragment), `error ${H.show(r[1])} lacks '${fragment}'`);
}

function dec(s) {
  const [v, err] = data.decode(s);
  if (err !== null) H.fail(`decode failed: ${err}`);
  return v;
}

function decErr(s, fragment) {
  const r = data.decode(s);
  assert(Array.isArray(r) && r.length === 2, "decode returns [value, why]");
  assertEqual(r[0], undefined, `decode of ${H.show(s)} should fail`);
  assert(typeof r[1] === "string" && r[1].includes(fragment), `error ${H.show(r[1])} lacks '${fragment}'`);
}

// structural equality: Object.is on numbers (0 and -0 differ), Arrays and
// objects told apart, the same prototypes; for assertions only
function deepEqual(a, b) {
  if (typeof a !== typeof b) return false;
  if (typeof a === "number") return Object.is(a, b);
  if (a === b) return true;
  if (a === null || b === null || typeof a !== "object") return false;
  if (Array.isArray(a) !== Array.isArray(b) || Object.getPrototypeOf(a) !== Object.getPrototypeOf(b)) return false;
  const ka = Reflect.ownKeys(a);
  const kb = Reflect.ownKeys(b);
  if (ka.length !== kb.length) return false;
  for (const k of ka) {
    if (!Object.prototype.hasOwnProperty.call(b, k) || !deepEqual(a[k], b[k])) return false;
  }
  return true;
}

// ── exports and the contract ──────────────────

test("kit-api check passes on the real data module", () => {
  const p = K.check(data);
  assert(p.length === 0, p.join("; "));
});

test("exports are exactly the contract's", () => {
  assertEqual(Object.keys(mod).sort().join(","), Object.keys(K.EXPORTS).sort().join(","), "module exports");
  const want = K.EXPORTS.data;
  for (const k of Reflect.ownKeys(data)) assert(Object.prototype.hasOwnProperty.call(want, k), `kit.data.${String(k)} is not in kit-api`);
  for (const k of Object.keys(want)) assert(Object.prototype.hasOwnProperty.call(data, k), `kit.data.${k} is missing`);
});

test("the module imports nothing (nothing outside js/code/kit/)", () => {
  const path = fileURLToPath(new URL("../code/kit/data.mjs", import.meta.url));
  // block comments, and whole-line comments, are not code
  const src = readFileSync(path, "utf8").replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  const found = [...src.matchAll(/^\s*(?:import\b[^;]*?from\s*["']([^"']+)["']|import\s*["']([^"']+)["']|export\b[^;]*?from\s*["']([^"']+)["'])/gm)];
  assertEqual(found.length, 0, `imports in data.mjs: ${found.map((m) => m[1] || m[2] || m[3]).join(", ")}`);
  assert(!/\bimport\s*\(/.test(src), "data.mjs has a dynamic import");
  assert(!/\brequire\s*\(/.test(src), "data.mjs calls require");
});

// ── null and arrays ───────────────────────────

test("null: data.null is the primitive null, renders and encodes as null, cannot be written to", () => {
  assertEqual(data.null, null);
  assertEqual(String(data.null), "null");
  assertThrows(() => { data.null.x = 1; }, "null");
  assertEqual(enc(data.null), "null");
});

test("array: returns the same Array unchanged, throws on anything that is not an Array", () => {
  const t = [1];
  assert(data.isArray(t));
  assert(array(t) === t, "the same Array");
  assert(Object.getPrototypeOf(t) === Array.prototype && Reflect.ownKeys(t).join(",") === "0,length", "nothing attached");
  assert(array(array(t)) === t, "idempotent");
  assert(data.isArray(array([])));
  assert(data.isArray([1, 2]), "every Array is an array (no mark)");
  assert(!data.isArray({}));
  assert(!data.isArray("x"));
  assert(!data.isArray(data.null));
  assert(!data.isArray({ length: 1, 0: 1 }), "an array-like object");
  assertThrows(() => array({}), "not an Array");
  assertThrows(() => array(undefined), "not an Array");
  let err;
  try { array(new Map()); } catch (e) { err = e; }
  assert(err instanceof TypeError, "a TypeError");
});

test("array: a frozen Array is an array, and data.array returns it", () => {
  // what the core hands out (kit-api: frozen data reads as plain)
  const f = Object.freeze([1]);
  assert(data.isArray(f));
  assert(array(f) === f);
});

// ── encoding scalars ──────────────────────────

test("encode: booleans and integers", () => {
  assertEqual(enc(true), "true");
  assertEqual(enc(false), "false");
  assertEqual(enc(0), "0");
  assertEqual(enc(-17), "-17");
  assertEqual(enc(Number.MAX_SAFE_INTEGER), "9007199254740991");
  assertEqual(enc(Number.MIN_SAFE_INTEGER), "-9007199254740991");
});

const FLOATS = [
  // value, Node 22 String(x)
  [1.0, "1"],
  [0.1, "0.1"],
  [0.1 + 0.2, "0.30000000000000004"],
  [100.0, "100"],
  [1.5, "1.5"],
  [-2.5, "-2.5"],
  [1e20, "100000000000000000000.0"],
  [1e21, "1e+21"],
  [1.5e21, "1.5e+21"],
  [1e-6, "0.000001"],
  [1.5e-6, "0.0000015"],
  [1e-7, "1e-7"],
  [1.23e-7, "1.23e-7"],
  [2 ** 53, "9007199254740992.0"],
  [5e-324, "5e-324"],
  [1.7976931348623157e308, "1.7976931348623157e+308"],
  [123456789012345680000.0, "123456789012345680000.0"],
  [0.0, "0"],
  [-0.0, "0"],
  // exact powers of two: the rounding interval is lopsided
  [2.0 ** -1017, "7.120236347223045e-307"],
  [2.0 ** 896, "5.282945311356653e+269"],
  [2.0 ** -1022, "2.2250738585072014e-308"],
  [2.0 ** 1023, "8.98846567431158e+307"],
  [-(2.0 ** -1000), "-9.332636185032189e-302"],
];

for (const [x, text] of FLOATS) {
  test(`encode float ${text}${Object.is(x, -0) ? " (-0)" : ""}`, () => {
    assertEqual(enc(x), text);
    assertEqual(enc([x]), `[${text}]`);
  });
}

test("encode: no integer/float subtype; a non-integral float survives", () => {
  assertEqual(enc([1, 1.0, -0, 0, 1.5]), "[1,1,0,0,1.5]");
  assertEqual(dec(enc(1.5)), 1.5);
});

test("encode: NaN and infinities are rejected", () => {
  encErr(NaN, "NaN");
  encErr(Infinity, "Infinity");
  encErr(-Infinity, "Infinity");
  encErr({ x: [1.5, Infinity] }, "$.x[1]");
});

test("encode: string escapes", () => {
  assertEqual(enc('a"b\\c'), '"a\\"b\\\\c"');
  assertEqual(enc("\b\f\n\r\t"), '"\\b\\f\\n\\r\\t"');
  assertEqual(enc("\u0000\u0001\u001f"), '"\\u0000\\u0001\\u001f"');
  assertEqual(enc("\u007f/"), '"\u007f/"', "DEL and '/' are not escaped");
  assertEqual(enc("hé \u{1F600}"), '"hé \u{1F600}"', "everything else as is");
});

test("encode: a lone surrogate is rejected (not valid UTF-8)", () => {
  encErr("\uD800", "lone surrogate");
  encErr("\uDC00", "lone surrogate");
  encErr("a\uDE00\uD83D", "lone surrogate"); // a pair in the wrong order
  encErr({ "\uD800": 1 }, "lone surrogate");
  assertEqual(enc("😀"), '"\u{1F600}"', "a pair is one code point");
});

// ── encoding objects and arrays ───────────────

test("encode: object keys sorted by code point (UTF-8 byte order)", () => {
  assertEqual(enc({ b: 1, a: 2, B: 3, "é": 4, z: 5, "": 6 }),
    '{"":6,"B":3,"a":2,"b":1,"z":5,"é":4}');
  assertEqual(enc({ ab: 1, a: 2, abcdefghij: 3, abcdefghi: 4, abcdefghik: 5 }),
    '{"a":2,"ab":1,"abcdefghi":4,"abcdefghij":3,"abcdefghik":5}', "prefixes");
  // UTF-16 order would put U+1F600 (a surrogate pair) before U+FF61
  assertEqual(enc({ "\u{1F600}": 1, "｡": 2, "": 3 }), '{"":3,"｡":2,"\u{1F600}":1}', "beyond the BMP");
  assertEqual(enc({ 10: "a", 9: "b", 1: "c" }), '{"1":"c","10":"a","9":"b"}', "integer-like keys as strings");
});

test("encode: the same object always gives the same text", () => {
  const keys = [];
  for (let i = 1; i <= 200; i += 1) keys.push(`k${(i * 7919) % 1000}`);
  const a = {};
  const b = {};
  for (let i = 0; i < keys.length; i += 1) a[keys[i]] = i;
  for (let i = keys.length - 1; i >= 0; i -= 1) b[keys[i]] = i;
  assertEqual(enc(a), enc(b));
});

test("encode: arrays and objects, empty ones included", () => {
  assertEqual(enc([1, 2, 3]), "[1,2,3]");
  assertEqual(enc(array(["x", data.null, 1.5])), '["x",null,1.5]');
  assertEqual(enc([]), "[]", "an empty Array is an array (no mark needed)");
  assertEqual(enc({}), "{}");
  assertEqual(enc(Object.create(null)), "{}", "a null-prototype object is plain");
});

test("encode: nested structures", () => {
  assertEqual(enc({ rows: [[1, "a"], [2, null]], columns: ["id", "v"], n: 2 }),
    '{"columns":["id","v"],"n":2,"rows":[[1,"a"],[2,null]]}');
});

test("encode: numeric keys are string keys of an object", () => {
  assertEqual(enc({ 2: "x" }), '{"2":"x"}');
  assertEqual(enc({ 1.5: "x" }), '{"1.5":"x"}');
});

test("encode: an Array with a hole or extra keys is rejected", () => {
  encErr([1, , 3], "array has a hole"); // eslint-disable-line no-sparse-arrays
  const sparse = [];
  sparse.length = 2;
  encErr(sparse, "array has a hole");
  const t = [1, 2];
  t.x = 1;
  encErr(t, "array has keys other than its indices");
  const s = [1];
  s[Symbol("k")] = 1;
  encErr(s, "array has keys other than its indices");
});

test("encode: values that are not data are rejected", () => {
  class Opaque { constructor() { this.v = 1; } }
  class Sub extends Array {}
  encErr(undefined, "undefined is not a value");
  encErr({ u: undefined }, "undefined is not a value");
  encErr({ f() {} }, "function");
  encErr(() => {}, "function");
  encErr(1n, "bigint");
  encErr(Symbol("s"), "symbol");
  encErr(new Map(), "not plain");
  encErr(new Date(0), "not plain");
  encErr(new Opaque(), "not plain");
  encErr(Object.create({}), "not plain");
  encErr(Object(1), "not plain");
  encErr(Sub.from([1]), "prototype is not Array.prototype");
  encErr({ [Symbol("k")]: 1 }, "not a string");
  encErr({ get g() { return 1; } }, "accessor");
  const c = {};
  c.self = c;
  encErr(c, "cycle");
  const l = [];
  l.push(l);
  encErr(l, "cycle");
});

test("encode: non-enumerable own keys are not part of the object", () => {
  const o = { a: 1 };
  Object.defineProperty(o, "hidden", { value: 2, enumerable: false });
  assertEqual(enc(o), '{"a":1}');
});

test("encode: nesting up to 512 levels, as decode allows", () => {
  const nest = (n) => { let v = 1; for (let i = 0; i < n; i += 1) v = [v]; return v; };
  const text = enc(nest(512));
  assertEqual(data.decode(text)[1], null, "512 levels decode");
  encErr(nest(513), "nesting too deep");
  decErr(`${"[".repeat(513)}${"]".repeat(513)}`, "too deep");
});

test("encode: a deep failure costs little (paths are built lazily)", () => {
  let v = 1;
  for (let i = 0; i < 20000; i += 1) v = [v];
  const t0 = process.hrtime.bigint();
  encErr(v, "nesting too deep");
  assert(Number(process.hrtime.bigint() - t0) / 1e9 < 1.0, "fast failure");
});

test("encode: the error names the path", () => {
  encErr({ a: { b: [1, { c: NaN }] } }, "at $.a.b[1].c");
});

test("encode: the same object in two places is fine (not a cycle)", () => {
  const shared = [1];
  assertEqual(enc({ a: shared, b: shared }), '{"a":[1],"b":[1]}');
});

test("encode: does not modify its input", () => {
  const v = { b: [1, 2], a: "x" };
  enc(v);
  assert(deepEqual(v, { b: [1, 2], a: "x" }));
  assert(!Object.isFrozen(v) && !Object.isFrozen(v.b), "encoding froze nothing");
  assertEqual(Object.keys(v).join(","), "b,a", "key order untouched");
});

// ── decoding ──────────────────────────────────

test("decode: scalars and types", () => {
  assertEqual(dec("true"), true);
  assertEqual(dec("false"), false);
  assert(dec("null") === data.null);
  assertEqual(dec("12"), 12);
  assert(Object.is(dec("-0"), 0), '"-0" is the integer 0');
  assertEqual(dec("1.0"), 1.0);
  assertEqual(dec("1e2"), 100.0);
  assertEqual(dec("1E-2"), 0.01);
  assertEqual(dec('"x"'), "x");
  assert(Number.isInteger(dec("12")), "an integer");
  assert(Object.is(dec("-0.0"), -0), "-0.0 keeps its sign");
});

test("decode: safe-integer limits", () => {
  assertEqual(dec("9007199254740991"), Number.MAX_SAFE_INTEGER);
  assertEqual(dec("-9007199254740991"), Number.MIN_SAFE_INTEGER);
  decErr("9007199254740992", "safe range");
  decErr("-9007199254740992", "safe range");
  decErr("123456789012345678901234", "safe range");
  assertEqual(dec("9007199254740993.0"), 9007199254740992, "a fraction makes it a double");
});

test("decode: arrays come back as Arrays; objects plain", () => {
  const v = dec('{"a":[1,[],{}],"b":{}}');
  assert(data.isArray(v.a));
  assert(data.isArray(v.a[1]));
  assert(!data.isArray(v.a[2]));
  assert(!data.isArray(v));
  assertEqual(Object.getPrototypeOf(v), Object.prototype, "a plain object");
  assertEqual(Object.getPrototypeOf(v.a), Array.prototype, "a plain Array");
  assertEqual(v.a.length, 3);
});

test("decode: nulls inside arrays keep the length", () => {
  const v = dec("[null,null,3]");
  assertEqual(v.length, 3);
  assert(v[0] === null && v[1] === null);
  assert(Object.prototype.hasOwnProperty.call(v, 0), "a null member is not a hole");
});

test("decode: string escapes and \\u, including surrogate pairs", () => {
  assertEqual(dec('"a\\"b\\\\c\\/d\\b\\f\\n\\r\\t"'), 'a"b\\c/d\b\f\n\r\t');
  assertEqual(dec('"\\u00e9\\u0041\\u0000"'), "éA\u0000");
  assertEqual(dec('"\\ud83d\\ude00"'), "\u{1F600}");
  assertEqual(dec('"\\uD83D\\uDE00"'), "\u{1F600}", "upper-case hex");
});

test("decode: bad strings are rejected", () => {
  decErr('"\\ud83d"', "surrogate");
  decErr('"\\ude00"', "surrogate");
  decErr('"\\ud83d\\u0041"', "surrogate");
  decErr('"\\x"', "bad escape");
  decErr('"\\u12"', "\\u");
  decErr('"a\nb"', "control character");
  decErr('"abc', "unterminated");
  decErr('"\uD800"', "lone surrogate"); // a raw lone surrogate, not an escape
});

test("decode: bad numbers are rejected", () => {
  decErr("01", "leading zero");
  decErr("-01", "leading zero");
  decErr("1.", "fraction");
  decErr(".5", "unexpected character");
  decErr("1e", "exponent");
  decErr("+1", "unexpected character");
  decErr("-", "bad number");
  decErr("1E400", "out of range");
  decErr("NaN", "unexpected character");
  decErr("Infinity", "unexpected character");
});

test("decode: structure errors", () => {
  decErr("", "end of input");
  decErr("   ", "end of input");
  decErr("[1,]", "unexpected character");
  decErr('{"a":1,}', "string key");
  decErr('{"a":1,"a":2}', "duplicate key");
  decErr('{"a" 1}', "':'");
  decErr("[1 2]", "','");
  decErr("{1:2}", "string key");
  decErr("[1] x", "trailing");
  decErr("// c\n1", "unexpected character");
  decErr("tru", "unexpected character");
  decErr(`${"[".repeat(600)}${"]".repeat(600)}`, "too deep");
});

test("decode: whitespace between tokens is allowed", () => {
  const v = dec(' \n\t{ "a" : [ 1 , 2 ] ,\r\n "b" : null } \n');
  assertEqual(enc(v), '{"a":[1,2],"b":null}');
});

test("decode: a non-string input is an error, not a throw", () => {
  const [v, err] = data.decode(undefined);
  assertEqual(v, undefined);
  assert(err.includes("must be a string"), err);
  assert(data.decode(Buffer.from("1"))[1].includes("must be a string"), "a Buffer is not a string");
});

test('decode: "__proto__" is an own key; no prototype is set or polluted', () => {
  const v = dec('{"__proto__":{"x":1},"a":2}');
  assertEqual(Object.getPrototypeOf(v), Object.prototype);
  assertEqual(Object.keys(v).join(","), "__proto__,a");
  assertEqual(Object.getOwnPropertyDescriptor(v, "__proto__").value.x, 1);
  assertEqual({}.x, undefined, "Object.prototype untouched");
  assertEqual(enc(v), '{"__proto__":{"x":1},"a":2}', "and it encodes back");
});

// ── round trips and canonical form ────────────

test("round trip: canonical text → value → the same text", () => {
  const texts = [
    '{"a":[1,2.5,"x",null,true,false],"b":{"c":{}},"d":[]}',
    '[[],[[]],{"":""}]',
    '{"n":-9007199254740991,"f":-1.5e-300}',
  ];
  for (const t of texts) {
    const canonical = enc(dec(t));
    assertEqual(enc(dec(canonical)), canonical);
  }
  assertEqual(enc(dec(texts[0])), texts[0]);
});

test("canonicalization: key order, whitespace and number spelling", () => {
  assertEqual(enc(dec('{ "b" : 1E2, "a" : 0.50 }')), '{"a":0.5,"b":100}');
});

test("round trip: value → text → an equal value", () => {
  const v = { rows: array([array([1, 1.5, "1", data.null]), array([])]), meta: {} };
  assert(deepEqual(dec(enc(v)), v));
});

// the bits of a double, and back
const view = new DataView(new ArrayBuffer(8));
const bitsOf = (x) => { view.setFloat64(0, x, true); return view.getBigUint64(0, true); };
const fromBits = (b) => { view.setBigUint64(0, b, true); return view.getFloat64(0, true); };

test("round trip: 10,000 doubles come back bit-exact", () => {
  let seed = 7n;
  let checked = 0;
  for (let i = 1; i <= 10000; i += 1) {
    seed = BigInt.asUintN(64, seed * 6364136223846793005n + 1442695040888963407n);
    const x = fromBits(seed);
    if (Number.isFinite(x) && !Object.is(x, -0)) {
      const y = dec(enc(x));
      assert(typeof y === "number" && bitsOf(y) === bitsOf(x), `double ${x} at ${i}`);
      checked += 1;
    }
  }
  assert(checked > 9900, `only ${checked} doubles checked`);
});

test("an integral number past the safe range renders as a float and round-trips (kit-api 13.1.0)", () => {
  assertEqual(enc(2 ** 53), "9007199254740992.0");
  assertEqual(enc(-(2 ** 53)), "-9007199254740992.0", "negative");
  assertEqual(enc(1e20), "100000000000000000000.0");
  assertEqual(enc([2 ** 53, 1e20]), "[9007199254740992.0,100000000000000000000.0]");
  assertEqual(enc(Number.MAX_SAFE_INTEGER), "9007199254740991", "the last safe integer stays digits");
  assertEqual(enc(1e21), "1e+21", "an exponent form gets no suffix");
  assertEqual(enc(1.5e300), "1.5e+300");
  for (const x of [2 ** 53, -(2 ** 53), 2 ** 53 + 2, 1e20, 123456789012345680000, 2 ** 69, 1e21, 2 ** 1023]) {
    const y = dec(enc(x));
    assert(y === x, `${x} round-trips: got ${H.show(y)}`);
  }
  decErr("9007199254740992", "safe range"); // bare digits are still a refused unsafe integer
});

H.finish("kit-data");
