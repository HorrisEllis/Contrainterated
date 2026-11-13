// contract test: kit-api (js/code/contracts/kit_api.mjs).
// Port of lua/sisoTests/contract-kit_api.test.lua. The module check names
// what is missing and what misbehaves, and passes on a module that
// answers as the contract says. (A stand-in defined here takes the place
// of js/code/kit/data.mjs, which a kit phase builds; it answers what the
// check asks and is no reference for the kit.)
import * as H from "../code/harness.mjs";
import * as K from "../code/contracts/kit_api.mjs";

const joined = (list) => list.join("; ");
const has = (list, needle) => list.some((s) => s.includes(needle));

// ── the stand-in ────────────────────────────────────────────────

function byCodePoint(a, b) {
  const A = [...a];
  const B = [...b];
  for (let i = 0; i < Math.min(A.length, B.length); i += 1) {
    const d = A[i].codePointAt(0) - B[i].codePointAt(0);
    if (d !== 0) return d;
  }
  return A.length - B.length;
}

function encodeWith(sortKeys) {
  const enc = (v, onPath, depth) => {
    if (v === null) return "null";
    switch (typeof v) {
      case "boolean":
        return String(v);
      case "number":
        if (!Number.isFinite(v)) throw new Error("a number that is not finite");
        if (Number.isInteger(v) && !Number.isSafeInteger(v) && !/[.e]/.test(String(v))) return `${String(v)}.0`;
        return String(v);
      case "string":
        if (/\p{Cs}/u.test(v)) throw new Error("a lone surrogate");
        return JSON.stringify(v);
      case "object": {
        if (depth >= 512) throw new Error("nested too deep");
        if (onPath.has(v)) throw new Error("a cycle");
        onPath.add(v);
        let out;
        if (Array.isArray(v)) {
          if (Reflect.ownKeys(v).length !== v.length + 1) throw new Error("a sparse array");
          out = `[${v.map((x) => enc(x, onPath, depth + 1)).join(",")}]`;
        } else {
          const proto = Object.getPrototypeOf(v);
          if (proto !== Object.prototype && proto !== null) throw new Error("not a plain object");
          const keys = sortKeys(Object.keys(v));
          out = `{${keys.map((k) => `${enc(k, onPath, depth)}:${enc(v[k], onPath, depth + 1)}`).join(",")}}`;
        }
        onPath.delete(v);
        return out;
      }
      default:
        throw new Error(`not data: ${typeof v}`);
    }
  };
  return (v) => {
    try {
      return [enc(v, new Set(), 0), null];
    } catch (e) {
      return [null, e.message];
    }
  };
}

function decodeWith({ duplicates = false, assign = false } = {}) {
  return (s) => {
    if (typeof s !== "string") return [undefined, "not a string"];
    let i = 0;
    const fail = (m) => { throw new SyntaxError(`${m} at ${i}`); };
    const ws = () => { while (i < s.length && " \t\n\r".includes(s[i])) i += 1; };
    const put = (o, k, v) => {
      if (assign) o[k] = v;
      else Object.defineProperty(o, k, { value: v, enumerable: true, writable: true, configurable: true });
    };
    const string = () => {
      i += 1;
      let out = "";
      for (;;) {
        const ch = s[i];
        if (ch === undefined) fail("an unterminated string");
        if (ch === '"') { i += 1; break; }
        if (ch === "\\") {
          const e = s[i + 1];
          const simple = { '"': '"', "\\": "\\", "/": "/", b: "\b", f: "\f", n: "\n", r: "\r", t: "\t" };
          if (e in simple) { out += simple[e]; i += 2; continue; }
          if (e !== "u") fail("a bad escape");
          const hex = s.slice(i + 2, i + 6);
          if (!/^[0-9a-fA-F]{4}$/.test(hex)) fail("a bad \\u escape");
          out += String.fromCharCode(parseInt(hex, 16));
          i += 6;
          continue;
        }
        if (ch < " ") fail("a control character in a string");
        out += ch;
        i += 1;
      }
      if (/\p{Cs}/u.test(out)) fail("a lone surrogate");
      return out;
    };
    const value = (depth) => {
      ws();
      const c = s[i];
      if (c === "{" || c === "[") {
        if (depth >= 512) fail("nested too deep");
        i += 1;
        const close = c === "{" ? "}" : "]";
        const o = c === "{" ? {} : [];
        const seen = new Set();
        ws();
        if (s[i] === close) { i += 1; return o; }
        for (;;) {
          if (c === "{") {
            ws();
            if (s[i] !== '"') fail("a key");
            const k = string();
            if (seen.has(k) && !duplicates) fail("a duplicate key");
            seen.add(k);
            ws();
            if (s[i] !== ":") fail("a colon");
            i += 1;
            put(o, k, value(depth + 1));
          } else o.push(value(depth + 1));
          ws();
          if (s[i] === ",") { i += 1; continue; }
          if (s[i] === close) { i += 1; return o; }
          fail("a separator");
        }
      }
      if (c === '"') return string();
      for (const [word, v] of [["true", true], ["false", false], ["null", null]]) {
        if (s.startsWith(word, i)) { i += word.length; return v; }
      }
      const m = /-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/y;
      m.lastIndex = i;
      const r = m.exec(s);
      if (!r) fail("a value");
      i = m.lastIndex;
      const n = Number(r[0]);
      if (r[2] === undefined && r[3] === undefined && !Number.isSafeInteger(n)) fail("an unsafe integer");
      return n;
    };
    try {
      const v = value(0);
      ws();
      if (i !== s.length) fail("trailing text");
      return [v, null];
    } catch (e) {
      return [undefined, e.message];
    }
  };
}

function standIn(overrides = {}) {
  const data = {
    null: null,
    array(t) {
      if (!Array.isArray(t)) throw new TypeError("data.array: not an Array");
      return t;
    },
    isArray: Array.isArray,
    encode: encodeWith((keys) => keys.sort(byCodePoint)),
    decode: decodeWith(),
    ...overrides,
  };
  return Object.freeze(data);
}

// a fresh frozen copy of plain data, as the core's freeze gives it
function freeze(v) {
  if (v === null || typeof v !== "object") return v;
  if (Array.isArray(v)) return Object.freeze(v.map(freeze));
  const out = {};
  for (const k of Object.keys(v)) out[k] = freeze(v[k]);
  return Object.freeze(out);
}

// ── the tests ───────────────────────────────────────────────────

H.test("a module that answers as the contract says passes", () => {
  const p = K.check(standIn());
  H.assert(p.length === 0, joined(p));
});

H.test("with freeze given, a module that reads frozen data passes", () => {
  const p = K.check(standIn(), { freeze });
  H.assert(p.length === 0, joined(p));
});

H.test("missing exports are named", () => {
  const m = { ...standIn() };
  delete m.decode;
  delete m.null;
  const p = K.check(m);
  H.assert(has(p, "kit.data.decode is missing"), joined(p));
  H.assert(has(p, "kit.data.null is missing"), joined(p));
  H.assert(has(K.check(undefined), "kit.data is missing"), "no module");
});

H.test("a non-canonical encoder is named", () => {
  const unsorted = standIn({ encode: encodeWith((keys) => keys) });
  H.assert(has(K.check(unsorted), "not canonical"), joined(K.check(unsorted)));
  const utf16 = standIn({ encode: encodeWith((keys) => keys.sort()) });
  H.assert(has(K.check(utf16), "does not sort keys by code point"), joined(K.check(utf16)));
});

H.test("an encoder that throws instead of returning why is named", () => {
  const plainEncode = encodeWith((keys) => keys.sort(byCodePoint));
  const m = standIn({
    encode(v) {
      if (v !== null && typeof v === "object" && typeof v.f === "function") throw new TypeError("a function");
      return plainEncode(v);
    },
  });
  H.assert(has(K.check(m), "data.encode threw on a function"), joined(K.check(m)));
});

H.test("a null that is a sentinel object is named", () => {
  const m = standIn({ null: Object.freeze({}) });
  H.assert(has(K.check(m), "kit.data.null is not a null"), joined(K.check(m)));
});

H.test("13.0.0 counterpart: an array mark that hands out an object is named", () => {
  const ArrayMark = class extends Array {};
  const wrap = standIn({ array: (t) => ArrayMark.from(t) });
  H.assert(has(K.check(wrap), "data.array on an Array is not that Array"), joined(K.check(wrap)));
  const reproto = standIn({ array: (t) => Object.setPrototypeOf(t, ArrayMark.prototype) });
  H.assert(has(K.check(reproto), "data.array changed the Array"), joined(K.check(reproto)));
  const lax = standIn({ array: (t) => t });
  H.assert(has(K.check(lax), "data.array accepted an object"), joined(K.check(lax)));
});

H.test("a lax or assigning decoder is named", () => {
  const dup = standIn({ decode: decodeWith({ duplicates: true }) });
  H.assert(has(K.check(dup), "data.decode accepted a duplicate key"), joined(K.check(dup)));
  const assign = standIn({ decode: decodeWith({ assign: true }) });
  H.assert(has(K.check(assign), '"__proto__"'), joined(K.check(assign)));
  const parse = standIn({
    decode: (s) => {
      try {
        return [JSON.parse(s), null];
      } catch (e) {
        return [undefined, e.message];
      }
    },
  });
  const p = K.check(parse);
  H.assert(has(p, "accepted an unsafe integer") && has(p, "accepted a lone surrogate escape"), joined(p));
});

H.test("with freeze given, an encoder that cannot read frozen data is named", () => {
  const plainEncode = encodeWith((keys) => keys.sort(byCodePoint));
  const m = standIn({
    // refuses a frozen object, as an encoder blind to frozen data would
    encode: (v) => (v !== null && typeof v === "object" && Object.isFrozen(v) ? [null, "frozen"] : plainEncode(v)),
  });
  H.assertEqual(K.check(m).length, 0, "without freeze the check does not ask");
  const p = K.check(m, { freeze });
  H.assert(has(p, "frozen"), joined(p));
});

H.finish("contract kit-api");
