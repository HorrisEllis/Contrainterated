/*
kit/data.mjs — plain data and its canonical JSON (kit-api, decision 5).
Port of lua/code/kit/data.lua (itself ported from jää 2.14.4 lib.json).
Imports nothing.

  import { data } from "./kit/data.mjs";
  data.null               null: JSON null is the primitive null (a value;
                          not undefined). No sentinel object.
  data.array(t) → t       t itself when t is an Array (an empty one too),
                          unchanged: same prototype, nothing attached.
                          Throws a TypeError on anything else.
  data.isArray(v)         Array.isArray (a frozen Array is an Array)
  data.encode(v)          → [text, null] | [null, why]
  data.decode(s)          → [value, null] | [undefined, why]

Canonical form (the Lua header's, mapped onto JavaScript):
  - object keys sorted by Unicode code point (= UTF-8 byte order, as the
    Lua kit sorts; not the UTF-16 default sort); no whitespace anywhere;
  - numbers: a safe integer as digits; any other finite number as
    ECMAScript Number::toString (String(n)); an integral number outside
    the safe range whose String(n) has neither "." nor an exponent
    (2**53, 1e20) gets ".0" appended ("9007199254740992.0", the Lua kit's
    float form), so it decodes back as the same number (kit-api 13.1.0);
    -0 is "0"; NaN and ±Infinity are rejected. JS has no integer/float
    subtype, so an integral float in the safe range renders as an
    integer (SUBSTRATE);
  - strings: `"` `\` and code points below 0x20 escaped (\b \f \n \r \t,
    else \u00xx, lowercase hex); everything else as is. A lone surrogate
    is rejected (the string is not valid UTF-8);
  - an Array (prototype Array.prototype) encodes as an array and must be
    dense, with no own key beyond its indices and `length`; a plain object
    (prototype Object.prototype or null) encodes as an object of its own
    enumerable string keys, which must be data properties (a getter is a
    function). undefined, functions, symbols, bigints, symbol keys, class
    instances (Map, Date, an Array subclass, …) and cycles are rejected;
  - nesting is limited to 512 arrays/objects, in both directions.

Frozen data (core-api READ-ONLY) is read through the ordinary read surface
(property reads, Reflect.ownKeys, Object.keys, descriptors), which a frozen
object answers as the plain object it is, so encode(frozen v) === encode(v).

Decoding is strict RFC 8259 (no trailing commas, comments, NaN, leading
zeros or duplicate keys; the whole input is one value). Objects come back
as fresh plain objects whose own keys are exactly the JSON keys, each
defined as an own data property ("__proto__" included: no prototype is
ever set or polluted); arrays as Arrays; null as null. A number with no
fraction or exponent must be a safe integer ("-0" is 0, as in Lua); any
other number is a double, and one out of range (1E400) is an error.
\u escapes are decoded with surrogate pairs joined; a lone surrogate,
escaped or raw, is an error. Error positions are 0-based UTF-16 offsets
(Lua's are 1-based bytes).

Pure: no state written after load, no I/O. The exported object and its
functions are frozen; nothing handed out is shared between calls.
Errors are returned, never thrown (data.array throws on a non-Array: that
is a caller's bug, not data).
*/

const MAX_DEPTH = 512; // nested arrays/objects; encode and decode alike

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

// ── marks ─────────────────────────────────────

const array = (t) => {
  if (!Array.isArray(t)) throw new TypeError("data.array: not an Array");
  return t;
};

const isArray = (v) => Array.isArray(v);

// ── key order ─────────────────────────────────

// Code point order on well-formed strings. UTF-16 order differs from it
// only where a surrogate meets a unit in U+E000..U+FFFF, so at the first
// differing unit those two ranges swap places.
function codePointCompare(a, b) {
  const n = Math.min(a.length, b.length);
  for (let i = 0; i < n; i += 1) {
    let x = a.charCodeAt(i);
    let y = b.charCodeAt(i);
    if (x !== y) {
      if (x >= 0xd800 && y >= 0xd800) {
        x += x >= 0xe000 ? -0x800 : 0x2000;
        y += y >= 0xe000 ? -0x800 : 0x2000;
      }
      return x - y;
    }
  }
  return a.length - b.length;
}

// ── encoding ──────────────────────────────────

const ESC = { '"': '\\"', "\\": "\\\\", "\b": "\\b", "\f": "\\f", "\n": "\\n", "\r": "\\r", "\t": "\\t" };

function escapeChar(c) {
  return ESC[c] || `\\u${c.charCodeAt(0).toString(16).padStart(4, "0")}`;
}

// A path is a chain of [parent, step] nodes, rendered only on error;
// building strings on every level would cost memory quadratic in depth.
function pathText(node) {
  const steps = [];
  while (node) {
    steps.push(node[1]);
    node = node[0];
  }
  return `$${steps.reverse().join("")}`;
}

class DataError extends Error {}

function fail(msg, path) {
  throw new DataError(`${msg} at ${pathText(path)}`);
}

function encodeString(s, out, path) {
  if (!s.isWellFormed()) fail("string is not valid UTF-8 (a lone surrogate)", path);
  out.push(`"${s.replace(/["\\\u0000-\u001f]/g, escapeChar)}"`);
}

function encodeNumber(n, out, path) {
  if (Number.isNaN(n)) fail("NaN is not allowed", path);
  if (!Number.isFinite(n)) fail("Infinity is not allowed", path);
  if (Object.is(n, -0)) {
    out.push("0");
    return;
  }
  const s = String(n);
  // past the safe range, bare digits would decode as a refused integer
  out.push(Number.isSafeInteger(n) || /[.e]/.test(s) ? s : `${s}.0`);
}

function encodeValue(v, out, onPath, path, depth) {
  if (v === undefined) fail("undefined is not a value (use data.null, which is null)", path);
  if (v === null) {
    out.push("null");
    return;
  }
  const t = typeof v;
  if (t === "boolean") {
    out.push(v ? "true" : "false");
    return;
  }
  if (t === "number") {
    encodeNumber(v, out, path);
    return;
  }
  if (t === "string") {
    encodeString(v, out, path);
    return;
  }
  if (t !== "object") fail(`a ${t} is not data`, path);
  if (onPath.has(v)) fail("cycle", path);
  if (depth >= MAX_DEPTH) fail("nesting too deep", path);
  const proto = Object.getPrototypeOf(v);
  const keys = Reflect.ownKeys(v);
  onPath.add(v);
  if (Array.isArray(v)) {
    if (proto !== Array.prototype) fail("an Array whose prototype is not Array.prototype is not data", path);
    const n = v.length;
    for (let i = 0; i < n; i += 1) {
      if (!hasOwn(v, i)) fail("array has a hole", path);
    }
    if (keys.length !== n + 1) fail("array has keys other than its indices", path);
    out.push("[");
    for (let i = 0; i < n; i += 1) {
      if (i > 0) out.push(",");
      const d = Object.getOwnPropertyDescriptor(v, i);
      if (!("value" in d)) fail("an accessor property is not data", [path, `[${i}]`]);
      encodeValue(d.value, out, onPath, [path, `[${i}]`], depth + 1);
    }
    out.push("]");
  } else {
    if (proto !== Object.prototype && proto !== null) {
      fail("an object that is not plain (its prototype is not Object.prototype or null) is not data", path);
    }
    const names = [];
    for (const k of keys) {
      if (typeof k !== "string") fail("object key is a symbol, not a string", path);
      const d = Object.getOwnPropertyDescriptor(v, k);
      if (!d.enumerable) continue;
      if (!("value" in d)) fail("an accessor property is not data", [path, `.${k}`]);
      names.push([k, d.value]);
    }
    names.sort((a, b) => codePointCompare(a[0], b[0]));
    out.push("{");
    for (let i = 0; i < names.length; i += 1) {
      if (i > 0) out.push(",");
      const [k, x] = names[i];
      encodeString(k, out, [path, ".<key>"]);
      out.push(":");
      encodeValue(x, out, onPath, [path, `.${k}`], depth + 1);
    }
    out.push("}");
  }
  onPath.delete(v);
}

// Arrow functions: no `prototype` object to hand out (frozen exports)
const encode = (v) => {
  const out = [];
  try {
    encodeValue(v, out, new Set(), null, 0);
  } catch (err) {
    if (err instanceof DataError) return [null, `data.encode: ${err.message}`];
    // a Proxy trap or the substrate (e.g. stack exhaustion): still returned
    let m;
    try {
      m = String(err && err.message !== undefined ? err.message : err);
    } catch {
      m = "an error that cannot be shown";
    }
    return [null, `data.encode: ${m}`];
  }
  return [out.join(""), null];
};

// ── decoding ──────────────────────────────────

function dfail(msg, pos) {
  throw new DataError(`${msg} at offset ${pos}`);
}

const UNESC = { '"': '"', "\\": "\\", "/": "/", b: "\b", f: "\f", n: "\n", r: "\r", t: "\t" };
const HEX4 = /^[0-9a-fA-F]{4}$/;
const NUMBER = /-?[0-9]+(\.?[0-9]*)([eE]?[-+]?[0-9]*)/y;

function decoder(s) {
  let i = 0;

  const skipWs = () => {
    while (i < s.length) {
      const c = s.charCodeAt(i);
      if (c !== 0x20 && c !== 0x09 && c !== 0x0a && c !== 0x0d) break;
      i += 1;
    }
  };

  const decodeString = () => {
    // s[i] === '"'
    const start = i;
    let j = i + 1;
    let out = "";
    for (;;) {
      let k = j;
      while (k < s.length) {
        const c = s.charCodeAt(k);
        if (c === 0x22 || c === 0x5c || c < 0x20) break;
        k += 1;
      }
      out += s.slice(j, k);
      j = k;
      const c = s[j];
      if (c === '"') {
        if (!out.isWellFormed()) dfail("string is not valid UTF-8 (a lone surrogate)", start);
        i = j + 1;
        return out;
      }
      if (c === "\\") {
        const e = s[j + 1];
        if (e !== undefined && hasOwn(UNESC, e)) {
          out += UNESC[e];
          j += 2;
        } else if (e === "u") {
          const hex = s.slice(j + 2, j + 6);
          if (!HEX4.test(hex)) dfail("bad \\u escape", j);
          let cp = parseInt(hex, 16);
          j += 6;
          if (cp >= 0xd800 && cp <= 0xdbff) {
            const lo = s.slice(j, j + 6);
            const lov = lo.startsWith("\\u") && HEX4.test(lo.slice(2)) ? parseInt(lo.slice(2), 16) : -1;
            if (lov < 0xdc00 || lov > 0xdfff) dfail("lone high surrogate", j);
            cp = 0x10000 + ((cp - 0xd800) << 10) + (lov - 0xdc00);
            j += 6;
          } else if (cp >= 0xdc00 && cp <= 0xdfff) {
            dfail("lone low surrogate", j);
          }
          out += String.fromCodePoint(cp);
        } else {
          dfail("bad escape", j);
        }
      } else if (c === undefined) {
        dfail("unterminated string", start);
      } else {
        dfail("control character in string", j);
      }
    }
  };

  const decodeNumber = () => {
    NUMBER.lastIndex = i;
    const m = NUMBER.exec(s);
    if (!m) dfail("bad number", i);
    const text = m[0];
    const frac = m[1];
    const exp = m[2];
    const int = text.slice(0, text.length - frac.length - exp.length);
    if (/^-?0[0-9]/.test(int)) dfail("leading zero", i);
    if (frac === ".") dfail("bad fraction", i);
    if (frac !== "" && frac[0] !== ".") dfail("bad number", i);
    if (exp !== "" && !/^[eE][-+]?[0-9]+$/.test(exp)) dfail("bad exponent", i);
    const v = Number(text);
    if (frac === "" && exp === "") {
      if (!Number.isSafeInteger(v)) dfail("integer out of the safe range (±(2^53 - 1))", i);
      i += text.length;
      return v === 0 ? 0 : v; // "-0" is the integer 0
    }
    if (!Number.isFinite(v)) dfail("number out of range", i);
    i += text.length;
    return v;
  };

  const put = (o, k, v) => {
    Object.defineProperty(o, k, { value: v, enumerable: true, writable: true, configurable: true });
  };

  const decodeValue = (depth) => {
    skipWs();
    const c = s[i];
    // containers only, as in the encoder: at most MAX_DEPTH nested levels
    if ((c === "{" || c === "[") && depth >= MAX_DEPTH) dfail("nesting too deep", i);
    if (c === "{") {
      const obj = {};
      i += 1;
      skipWs();
      if (s[i] === "}") {
        i += 1;
        return obj;
      }
      for (;;) {
        if (s[i] !== '"') dfail("expected a string key", i);
        const k = decodeString();
        skipWs();
        if (s[i] !== ":") dfail("expected ':'", i);
        i += 1;
        const v = decodeValue(depth + 1);
        if (hasOwn(obj, k)) dfail(`duplicate key ${JSON.stringify(k)}`, i);
        put(obj, k, v);
        skipWs();
        const d = s[i];
        if (d === "}") {
          i += 1;
          return obj;
        }
        if (d !== ",") dfail("expected ',' or '}'", i);
        i += 1;
        skipWs();
      }
    }
    if (c === "[") {
      const arr = [];
      i += 1;
      skipWs();
      if (s[i] === "]") {
        i += 1;
        return arr;
      }
      for (;;) {
        arr.push(decodeValue(depth + 1));
        skipWs();
        const d = s[i];
        if (d === "]") {
          i += 1;
          return arr;
        }
        if (d !== ",") dfail("expected ',' or ']'", i);
        i += 1;
      }
    }
    if (c === '"') return decodeString();
    if (c === "-" || (c >= "0" && c <= "9")) return decodeNumber();
    if (s.startsWith("true", i)) {
      i += 4;
      return true;
    }
    if (s.startsWith("false", i)) {
      i += 5;
      return false;
    }
    if (s.startsWith("null", i)) {
      i += 4;
      return null;
    }
    if (c === undefined) dfail("unexpected end of input", i);
    return dfail("unexpected character", i);
  };

  return () => {
    const v = decodeValue(0);
    skipWs();
    if (i < s.length) dfail("trailing characters", i);
    return v;
  };
}

const decode = (s) => {
  if (typeof s !== "string") return [undefined, "data.decode: input must be a string"];
  try {
    return [decoder(s)(), null];
  } catch (err) {
    if (err instanceof DataError) return [undefined, `data.decode: ${err.message}`];
    let m;
    try {
      m = String(err && err.message !== undefined ? err.message : err);
    } catch {
      m = "an error that cannot be shown";
    }
    return [undefined, `data.decode: ${m}`];
  }
};

for (const f of [array, isArray, encode, decode]) Object.freeze(f);

export const data = Object.freeze({ null: null, array, isArray, encode, decode });
