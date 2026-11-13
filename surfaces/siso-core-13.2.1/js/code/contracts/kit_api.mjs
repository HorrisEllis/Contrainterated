/*
contract: kit-api — what the kit promises every JavaScript project that
vendors it (core 13, JavaScript instance), and this project's own
fixtures runner. Port of lua/code/contracts/kit_api.lua. Only a
contracts phase changes this file.

Between: kit -> fixtures (and consumers outside this project).

MODULES (import root js/code/; consumers vendor js/code/core/,
js/code/kit/ and the shared harness js/code/harness.mjs)

  kit/data.mjs   export data: plain data and its canonical JSON
                 (decision 5)
    data.null              null: JSON null is the primitive null (a
                           value; not undefined)
    data.array(t) → t      t itself when t is an Array (an empty one
                           too), unchanged (same prototype, nothing
                           attached); throws a TypeError on anything else
    data.isArray(v)        Array.isArray (a frozen Array is an Array)
    data.encode(v) → [text, null] | [null, why]
                           canonical JSON; errors returned, never thrown
    data.decode(s) → [value, null] | [undefined, why]
                           strict RFC 8259; errors returned, never thrown
    Canonical form (the Lua kit.data header's, mapped):
      - object keys sorted by Unicode code point (= UTF-8 byte order, as
        Lua sorts; NOT the UTF-16 default sort); no whitespace anywhere;
      - numbers: a safe integer as digits; any other finite number as
        ECMAScript Number::toString (String(n)), which keeps "." or an
        exponent for a non-integer; an integral number outside the safe
        range whose String(n) has neither (2**53 → "9007199254740992")
        gets ".0" appended ("9007199254740992.0", the Lua kit's float
        form), so it decodes back as the same number instead of as a
        refused unsafe integer (13.1.0); -0 is "0"; NaN and ±Infinity
        are rejected;
      - strings: `"` `\` and code points below 0x20 escaped (\b \f \n \r
        \t, else \u00xx, lowercase hex); everything else as is; a lone
        surrogate is rejected (not valid UTF-8);
      - Arrays must be dense; plain objects only (prototype
        Object.prototype or null; own enumerable string keys);
        undefined, functions, symbols, bigints, class instances (Map,
        Date, …) and cycles are rejected; nesting is limited to 512
        arrays/objects, in both directions.
      Frozen data (core-api READ-ONLY) is encoded as the plain data it
      presents: encode(frozen v) === encode(v), so residue, samples and
      observer records can be serialised as they are handed out.
    decode: strict RFC 8259 (no trailing commas, comments, NaN, leading
      zeros or duplicate keys; the whole input is one value). Objects
      come back as plain objects whose own keys are exactly the JSON
      keys ("__proto__" included, as an own data property; the decoder
      never assigns it, so no prototype is set or polluted), arrays as
      Arrays, null as null; an integer must be a safe integer; \u escapes
      are decoded with surrogate pairs joined, and a lone surrogate is an
      error.
    Nothing kit/data.mjs hands out gives a gate an object it could write
    and later read back: it keeps no state, and its exports are frozen.

  Command line (run from the project root):
    node js/code/kit/run.mjs [tests-dir]   every *.test.mjs suite under
                                 the tests directory (default
                                 js/sisoTests), each in its own process.
                                 Final line "run: S suites, P/T passed"
                                 (or ", F FAILED (files…)"); exit 0 only
                                 when all pass.

SUBSTRATE (JavaScript; what the Lua kit-api holds that this one does not)
  - Not ported (tooling for Lua gate authors): kit.helper (the per-gate
    test API), kit.sha256, kit.digest, kit.canon, kit.census, kit.lint.
    The kit's command line is the suite runner alone, so there is no
    register list, no gate group and no digest line.
  - Lua's marks vanish: an array is an Array (no data.array metatable),
    JSON null is null (no "readonly:kit.data.null" sentinel), so the
    Lua probes "getmetatable(data.null)" and "a marked array answers
    getmetatable" become "data.null is null" and "data.array returns the
    same Array with its prototype unchanged".
  - JS has no integer/float subtype: 1.0 is 1, so the Lua probe of a
    float surviving as 1.0 becomes a non-integral float (1.5) surviving;
    an integral float renders as an integer (the polyglot condition
    holds for integers and non-integral floats).
  - `freeze` (Lua's core.freeze) is an option here: any function that
    returns a frozen version of plain data (core/freeze.mjs's freeze, or
    `v => Event("t", v).data`).

check(data, { freeze }) runs the module half of this contract against a
kit/data.mjs module object `data` and returns an Array of problems
(empty when it holds). `freeze` is optional; when given, the check also
requires encode to read frozen data as the plain data it presents.
*/

export const EXPORTS = Object.freeze({
  data: Object.freeze({ null: "null", array: "function", isArray: "function", encode: "function", decode: "function" }),
});

export const TOOLS = Object.freeze(["code/kit/run.mjs"]);

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

export function check(d, opts = {}) {
  const p = [];
  const fail = (s) => { p.push(s); };
  if (d === null || typeof d !== "object") return ["kit.data is missing"];
  for (const [k, kind] of Object.entries(EXPORTS.data)) {
    if (!(k in d)) fail(`kit.data.${k} is missing`);
    else if (kind === "null" ? d[k] !== null : typeof d[k] !== kind) fail(`kit.data.${k} is not a ${kind}`);
  }
  p.sort();
  if (p.length > 0) return p;

  // an encode call: → [text, why]; anything else is a problem named here
  const encode = (v, label) => {
    let r;
    try {
      r = d.encode(v);
    } catch (err) {
      fail(`data.encode threw on ${label} instead of returning why: ${str(err)}`);
      return [null, "threw"];
    }
    if (!Array.isArray(r) || r.length !== 2) {
      fail(`data.encode does not return [text, why] on ${label}`);
      return [null, "shape"];
    }
    return r;
  };
  const decode = (s, label) => {
    let r;
    try {
      r = d.decode(s);
    } catch (err) {
      fail(`data.decode threw on ${label} instead of returning why: ${str(err)}`);
      return [undefined, "threw"];
    }
    if (!Array.isArray(r) || r.length !== 2) {
      fail(`data.decode does not return [value, why] on ${label}`);
      return [undefined, "shape"];
    }
    return r;
  };

  try {
    const v = { b: 1, a: d.array([1.5, "x", true, d.null]), e: d.array([]) };
    const [s, why] = encode(v, "plain data");
    if (s !== '{"a":[1.5,"x",true,null],"b":1,"e":[]}' || why !== null) {
      fail(`data.encode is not canonical: ${str(s === null ? why : s)}`);
    }
    const [ordered] = encode({ "\u{1F600}": 1, "｡": 2, "z": 3, "é": 4 }, "keys beyond the BMP");
    if (ordered !== '{"z":3,"é":4,"｡":2,"😀":1}') {
      fail(`data.encode does not sort keys by code point: ${str(ordered)}`);
    }
    const [scalars] = encode([-0, 1e21, 0.1, "q\"\\\n\u0001"], "scalars");
    if (scalars !== '[0,1e+21,0.1,"q\\"\\\\\\n\\u0001"]') fail(`data.encode does not render numbers and strings canonically: ${str(scalars)}`);
    // an integral number past the safe range renders as a float and round-trips
    const [big] = encode([2 ** 53, 1e20], "integral numbers past the safe range");
    if (big !== "[9007199254740992.0,100000000000000000000.0]") {
      fail(`data.encode does not render an unsafe integral number as a float: ${str(big)}`);
    } else {
      const [bigBack, bigWhy] = decode(big, "unsafe integral numbers");
      if (bigWhy !== null || !Array.isArray(bigBack) || bigBack[0] !== 2 ** 53 || bigBack[1] !== 1e20) {
        fail(`an unsafe integral number does not round-trip: ${str(bigWhy)}`);
      }
    }

    const [back, bwhy] = decode(typeof s === "string" ? s : '{"a":[1.5,"x",true,null],"b":1,"e":[]}', "canonical text");
    if (back === null || typeof back !== "object" || bwhy !== null || !d.isArray(back.a) || !d.isArray(back.e)) {
      fail("data.decode loses arrays");
    } else {
      if (back.a[0] !== 1.5 || back.a[3] !== null) fail("data.decode loses a float or null");
      if (!Number.isInteger(back.b) || back.b !== 1) fail("data.decode does not give back an integer");
      if (Object.getPrototypeOf(back) !== Object.prototype && Object.getPrototypeOf(back) !== null) {
        fail("data.decode builds an object that is not plain");
      }
    }
    if (d.isArray({})) fail("a plain object is an array");
    if (!d.isArray([])) fail("an empty Array is not an array");
    if (d.null !== null) fail(`data.null is ${str(d.null)}, not null`);
    const marked = [];
    const same = d.array(marked);
    if (same !== marked) fail("data.array on an Array is not that Array");
    if (Object.getPrototypeOf(marked) !== Array.prototype || Reflect.ownKeys(marked).length !== 1) {
      fail("data.array changed the Array it was given (a mark that hands out an object)");
    }
    let arrayAccepted = true;
    try {
      d.array({});
    } catch {
      arrayAccepted = false;
    }
    if (arrayAccepted) fail("data.array accepted an object that is not an Array");

    const cyclic = { k: 1 };
    cyclic.self = cyclic;
    class Opaque { constructor() { this.v = 1; } }
    for (const [label, value] of [
      ["a function", { f: () => 1 }],
      ["NaN", [Number.NaN]],
      ["Infinity", [Infinity]],
      ["undefined", undefined],
      ["a symbol", [Symbol("s")]],
      ["a bigint", [1n]],
      ["a Map", new Map()],
      ["a class instance", new Opaque()],
      ["a cycle", cyclic],
      ["a sparse array", [1, , 3]], // eslint-disable-line no-sparse-arrays
      ["a lone surrogate", ["\uD800"]],
    ]) {
      const [text, w] = encode(value, label);
      if (text !== null || typeof w !== "string") {
        if (w !== "threw" && w !== "shape") fail(`data.encode accepted ${label}`);
      }
    }
    for (const [label, text] of [
      ["a trailing comma", "[1,]"],
      ["a comment", "// c\n1"],
      ["NaN", "NaN"],
      ["a leading zero", "01"],
      ["a duplicate key", '{"a":1,"a":2}'],
      ["two values", "1 2"],
      ["an unsafe integer", "9007199254740993"],
      ["a lone surrogate escape", '"\\ud800"'],
    ]) {
      const [value, w] = decode(text, label);
      if (value !== undefined || typeof w !== "string") {
        if (w !== "threw" && w !== "shape") fail(`data.decode accepted ${label}`);
      }
    }
    const [proto] = decode('{"__proto__":{"x":1}}', "a __proto__ key");
    const desc = proto !== null && typeof proto === "object" ? Object.getOwnPropertyDescriptor(proto, "__proto__") : undefined;
    if (!desc || !("value" in desc) || desc.value === null || typeof desc.value !== "object" || desc.value.x !== 1 ||
        (Object.getPrototypeOf(proto) !== Object.prototype && Object.getPrototypeOf(proto) !== null)) {
      fail('data.decode does not keep "__proto__" as an own key of a plain object');
    }

    if (opts.freeze) {
      const sample = () => ({ b: 1, a: d.array([1.5, "x", true, d.null]), e: d.array([]), o: { k: "v", n: [2] }, l: [1, 2] });
      const [plain] = encode(sample(), "plain data");
      const frozenSample = opts.freeze(sample());
      const [frozen, fwhy] = encode(frozenSample, "frozen data");
      if (frozen !== plain) {
        fail(`data.encode does not read frozen data as the plain data it presents: ${str(frozen === null ? fwhy : frozen)}`);
      }
      if (frozenSample.a[3] !== null) fail("freezing did not pass null through");
      if (!d.isArray(frozenSample.a)) fail("a frozen array is not an array");
    }
  } catch (err) {
    fail(`raised ${str(err)}`);
  }
  return p;
}
