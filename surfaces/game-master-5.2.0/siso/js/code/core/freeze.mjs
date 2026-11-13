/*
core/freeze.mjs — the read-only form of every event and the copies the
doors hand out (core-api EVENT / READ-ONLY / STATELESS THROUGH THE EVENT
/ OWNERSHIP, observer-seam RECORD data and THE REJECTION; core 13).
Port of lua/code/core/freeze.lua.

  import { freeze, copy, plain, isFrozen, isEvent, newEvent } from "./freeze.mjs";
  freeze(v, path)   → v as a core-frozen value: plain data copied into
                      fresh frozen objects; what the core already froze
                      (an event, an event's data) kept by identity;
                      anything that is not plain data refused with a
                      TypeError "Event: data at <path> is not plain data
                      (<what>)". One memo per call.
  copy(v, memo)     → a fresh frozen copy of v at every depth, the
                      receiver's own: events become fresh events (same
                      brand), data fresh frozen data. `memo` (a Map,
                      optional) is shared by the copies made for one
                      owner, so sharing among them is kept and nothing is
                      shared with the original or another owner.
  plain(v, memo)    → observer-seam THE REJECTION: a fresh frozen copy of
                      v's own string-keyed properties at every depth,
                      enumerable or not (an array's `length` is the
                      array's own), as plain objects and Arrays; functions
                      kept by identity (code, not data); primitives as
                      they are.
  isFrozen(v)       → true only for an object the core froze here (an
                      event or core-frozen data, copies included).
  isEvent(v)        → true only for an event built here (Event.is).
  newEvent(t, d)    → an event: a frozen plain object { type, data } with
                      both passed through freeze (core/event.mjs is its
                      public face).

How the Lua mechanisms map (core-api SUBSTRATE):
  - Lua's proxy tables, their private KEY and views vanish: an object is
    read-only by Object.freeze, so reads are the language's own
    (indexing, length, for…of, Object.keys, for…in, spread) and reading a
    field twice gives the same value.
  - "Recognising a frozen table" becomes two private-field brands
    (#event, #frozen) stamped with the return-override idiom before the
    object is frozen and checked with `#x in v`. Nothing outside this
    module can name them, so a Proxy, a look-alike or a structured clone
    is never recognised (the X1 counterpart: recognising without asking
    foreign objects anything).
  - getmetatable answers vanish: an event's prototype is
    Object.prototype; data's are Object.prototype, Array.prototype (an
    Array) or null (an object with a null prototype keeps it).
  - Lua's data marks and "readonly:" sentinels vanish: a JS array is an
    Array and JSON null is the primitive null.
  - Plain data is checked here: a function, a class instance, Map, Set,
    Date, RegExp, a typed array, a boxed primitive, a Promise, a sparse
    array or an array with a named key is refused, since it carries state
    a Lua value of plain data cannot. So is a plain object or array with
    an own symbol key, a non-enumerable own property or an accessor
    (13.1.0): nothing in the caller's data is silently dropped or read
    through code (Drift 7). Each own property is read once, through its
    descriptor, and the value judged is the value copied.

Intrinsics (core-api SUBSTRATE, 13.1.0): every built-in this module
calls on the objects it walks or on its own work lists (Reflect.ownKeys,
Object.getOwnPropertyDescriptor, Object.freeze, Map.prototype.get/set, …)
is captured when the module loads, and work lists are walked with plain
index loops (no iterator, no destructuring), so host code that rewrites
an intrinsic afterwards is not handed this module's private objects.
Writing an intrinsic stays a global write by trusted code (convention).

Every walk here is iterative (an explicit work list, never recursion), so
no depth the host can allocate overflows the call stack; each object is
handled once per call (the memo), so sharing and cycles are preserved and
every walk terminates. Objects are built, filled, branded and only then
frozen, so nothing half-built is ever handed out. The cost of a call
grows with the size of what it copies, never with history. Nothing here
is written after load: the brands are classes whose only state is the
private field each stamps on the object it is given.
*/

// ── intrinsics, captured at load ────────────────
const uncurry = (f) => Function.prototype.call.bind(f);
const ObjectProto = Object.prototype;
const ArrayProto = Array.prototype;
const MapCtor = Map;
const mapGet = uncurry(Map.prototype.get);
const mapSet = uncurry(Map.prototype.set);
const hasOwn = uncurry(Object.prototype.hasOwnProperty);
const getProto = Object.getPrototypeOf;
const getDesc = Object.getOwnPropertyDescriptor;
const defineProp = Object.defineProperty;
const freezeObj = Object.freeze;
const createObj = Object.create;
const ownNames = Object.getOwnPropertyNames;
const ownKeys = Reflect.ownKeys;
const isArray = Array.isArray;
const StringCtor = String;

const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");

// The return-override idiom: `new Brand(o)` runs Brand's field
// initialisers on `o` itself, stamping the private field on it.
class Base {
  constructor(o) {
    return o;
  }
}

class EventBrand extends Base {
  #event = true;
  static stamp(o) {
    new EventBrand(o);
  }
  static is(v) {
    return isObject(v) && #event in v;
  }
}

class FrozenBrand extends Base {
  #frozen = true;
  static stamp(o) {
    new FrozenBrand(o);
  }
  static is(v) {
    return isObject(v) && #frozen in v;
  }
}

freezeObj(Base.prototype);
freezeObj(Base);
freezeObj(EventBrand.prototype);
freezeObj(EventBrand);
freezeObj(FrozenBrand.prototype);
freezeObj(FrozenBrand);

// a null-prototype descriptor, so no inherited field is read from it
function define(o, k, value) {
  defineProp(o, k, { __proto__: null, value, enumerable: true, writable: true, configurable: true });
}

// What a value that is not plain data is, for the refusal's message.
// Never throws.
function whatOf(v) {
  try {
    const proto = getProto(v);
    const ctor = proto === null ? undefined : getDesc(proto, "constructor");
    const name = ctor !== undefined && hasOwn(ctor, "value") && typeof ctor.value === "function" ? ctor.value.name : undefined;
    if (typeof name === "string" && name !== "") return `a ${name}`;
  } catch {
    // a value that answers nothing safely is described generically
  }
  return "an object";
}

const notPlain = (path, what) => new TypeError(`Event: data at ${path} is not plain data (${what})`);

/*
ownData(v, where, array) → the own properties of a plain object or a dense
array as { keys, values } (an array's `length` left out), each read once
through its descriptor; or a TypeError naming what is not plain data: a
symbol key, an accessor, a non-enumerable property, a hole or a named key
on an array.
*/
function ownData(v, where, array) {
  const keys = ownKeys(v);
  const outKeys = [];
  const outValues = [];
  let n = 0;
  let length = 0;
  if (array) {
    const ld = getDesc(v, "length");
    length = ld !== undefined && hasOwn(ld, "value") ? ld.value : 0;
  }
  for (let i = 0; i < keys.length; i += 1) {
    const k = keys[i];
    if (typeof k === "symbol") throw notPlain(where, "an object with a symbol key");
    if (array && k === "length") continue;
    const at = array ? `${where}[${k}]` : `${where}.${k}`;
    const d = getDesc(v, k);
    if (d === undefined) throw notPlain(at, "a property that vanished while it was read");
    if (!hasOwn(d, "value")) throw notPlain(at, "an accessor");
    if (d.enumerable !== true) throw notPlain(at, "a non-enumerable property");
    if (array && k !== StringCtor(n)) throw notPlain(where, n < length && StringCtor(+k) === k ? "a sparse array" : "an array with a named key");
    outKeys[n] = k;
    outValues[n] = d.value;
    n += 1;
  }
  if (array && n !== length) throw notPlain(where, "a sparse array");
  return { keys: outKeys, values: outValues };
}

/*
build(root, path, memo, keep) — the walk freeze and copy share. With
`keep`, an object the core already froze (an event, core-frozen data) is
kept by identity; without it, it is rebuilt fresh. Events are rebuilt as
events, plain objects and dense arrays as fresh objects and Arrays;
anything else is refused.
*/
function build(root, path, memo, keep) {
  const made = []; // objects to brand and freeze, in creation order
  const madeEvent = [];
  let nMade = 0;
  const work = []; // a stack of { src, dst, kind, where, keys, values }
  let nWork = 0;

  const visit = (v, where) => {
    if (!isObject(v)) return v;
    if (keep && (EventBrand.is(v) || FrozenBrand.is(v))) return v;
    const done = mapGet(memo, v);
    if (done !== undefined) return done;
    let out;
    let kind;
    let keys;
    let values;
    if (EventBrand.is(v)) {
      out = {};
      kind = "event";
    } else if (typeof v === "function") {
      throw notPlain(where, "a function");
    } else if (isArray(v)) {
      if (getProto(v) !== ArrayProto) throw notPlain(where, "an array whose prototype is not Array.prototype");
      ({ keys, values } = ownData(v, where, true));
      out = [];
      kind = "array";
    } else {
      const proto = getProto(v);
      if (proto !== ObjectProto && proto !== null) throw notPlain(where, whatOf(v));
      ({ keys, values } = ownData(v, where, false));
      out = proto === null ? createObj(null) : {};
      kind = "object";
    }
    mapSet(memo, v, out);
    made[nMade] = out;
    madeEvent[nMade] = kind === "event";
    nMade += 1;
    work[nWork] = { src: v, dst: out, kind, where, keys, values };
    nWork += 1;
    return out;
  };

  const result = visit(root, path);
  while (nWork > 0) {
    nWork -= 1;
    const item = work[nWork];
    work[nWork] = undefined;
    const { src, dst, kind, where } = item;
    if (kind === "event") {
      define(dst, "type", visit(src.type, `${where}.type`));
      define(dst, "data", visit(src.data, `${where}.data`));
    } else {
      const { keys, values } = item;
      for (let i = 0; i < keys.length; i += 1) {
        const k = keys[i];
        define(dst, k, visit(values[i], kind === "array" ? `${where}[${k}]` : `${where}.${k}`));
      }
    }
  }
  for (let i = 0; i < nMade; i += 1) {
    if (madeEvent[i]) EventBrand.stamp(made[i]);
    else FrozenBrand.stamp(made[i]);
    freezeObj(made[i]);
  }
  return result;
}

/*
plainCopy(root, memo) — THE REJECTION: own string-keyed properties at
every depth, enumerable or not, read through property access (so a value
that cannot be read makes the copy fail, and that observer fails alone);
functions and primitives as they are.
*/
function plainCopy(root, memo) {
  const made = [];
  let nMade = 0;
  const work = [];
  let nWork = 0;
  const visit = (v) => {
    if (v === null || typeof v !== "object") return v;
    const done = mapGet(memo, v);
    if (done !== undefined) return done;
    const array = isArray(v);
    const out = array ? [] : {};
    mapSet(memo, v, out);
    made[nMade] = out;
    nMade += 1;
    work[nWork] = { src: v, dst: out, array };
    nWork += 1;
    return out;
  };
  const result = visit(root);
  while (nWork > 0) {
    nWork -= 1;
    const { src, dst, array } = work[nWork];
    work[nWork] = undefined;
    const names = ownNames(src);
    for (let i = 0; i < names.length; i += 1) {
      const k = names[i];
      if (array && k === "length") continue;
      define(dst, k, visit(src[k]));
    }
    if (array && dst.length !== src.length) dst.length = src.length;
  }
  for (let i = 0; i < nMade; i += 1) freezeObj(made[i]);
  return result;
}

export const freeze = (v, path = "data") => build(v, path, new MapCtor(), true);

export const copy = (v, memo = new MapCtor()) => build(v, "event", memo, false);

export const plain = (v, memo = new MapCtor()) => plainCopy(v, memo);

export const isFrozen = (v) => EventBrand.is(v) || FrozenBrand.is(v);

export const isEvent = (v) => EventBrand.is(v);

export const newEvent = (type, data) => {
  const e = {};
  define(e, "type", freeze(type, "type"));
  define(e, "data", freeze(data === undefined ? {} : data, "data"));
  EventBrand.stamp(e);
  return freezeObj(e);
};

freezeObj(freeze);
freezeObj(copy);
freezeObj(plain);
freezeObj(isFrozen);
freezeObj(isEvent);
freezeObj(newEvent);
