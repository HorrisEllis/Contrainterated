/*
contract-fake-core — a throwaway implementation that exists only so the
contract tests can show each contract check passing on something that
honours the contract, and failing on each departure it names. Port of
lua/sisoTests/contract-fake-core.lua.

It is NOT the core and NOT a reference for it: no attention to cost (it
copies recursively), not checked against anything. The stream phases
build js/code/core/ from the contracts and the Lua reference, never from
this.

  import { fake } from "./contract-fake-core.mjs";
  const core = fake();                         // honours core-api and observer-seam
  const core = fake({ skipNonEvents: true });  // 11.1's X1
Each call builds a fresh set of classes, so switches never leak from
one core to another.

Switches from the reference's history (the Lua fake's), each
reproducing one departure:
  skipNonEvents   non-Event array members are skipped (11.1 X1)
  acceptAnyEntry  the entry door takes any value (11.1)
  countIds        ids continue across propagations (11.1 R1)
  sampleCount     sampleHere carries eventCount (11.1 R1)
  noFreeze        events are not read-only and nothing is copied (11.1 R3)
  observeDuring   observers are called per delivery, during the
                  propagation (11.1's observer)
  unprotected     an observer's throw escapes emit
  sharedBatch     every observer gets the same batch object
  shareFrozen     samples and observer records hand out the stream's own
                  frozen objects instead of copies (12.0's X6)
  writableAtBirth Event builds a plain object holding the caller's data;
                  only the stream copies and freezes it (12.0)
  sharedRejection every observer is shown the very rejection emit throws
                  (12.0's C-2)
  tableMeta       events share a writable prototype object, a channel for
                  state (12.0.1 and 13.0.0's shared metatable)
  entryAlias      entry keeps the entering code's own event (13.0.0)
  richRejection   an observer's rejection copy keeps the rejection's
                  prototype (the host's own, left writable) and the
                  cause's (13.0.0)
Switches for JavaScript departures a check exists to catch:
  forgeable       Event.is answers by shape (type and data present), so a
                  look-alike or a Proxy passes (X1's JS counterpart)
  anyData         Event takes functions, class instances, Maps and sparse
                  arrays into data as they are (Lua's pass-through)
  freezeInPlace   Event freezes the caller's own objects in place instead
                  of copying them
  openStream      the stream's state is on public own properties (Lua's
                  R5)
  unfrozenExports Stream.prototype and its doors are left writable
  openRejection   the rejection class and prototype are left writable, so
                  what a holder writes there reaches every rejection
  acceptPromise   a gate's Promise (an async gate) is taken as "consumed"
                  (Drift 7: dropped instead of rejected)
*/

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

function typeName(v) {
  if (v === null) return "null";
  if (Array.isArray(v)) return "array";
  return typeof v;
}

function describe(v) {
  if (v === undefined) return "undefined";
  if (v === null) return "null";
  const t = typeof v;
  if (t === "boolean" || t === "number" || t === "bigint") return `the ${t} ${String(v)}`;
  if (t === "string") return "a string";
  if (t === "function") return "a function";
  if (t === "symbol") return "a symbol";
  if (Array.isArray(v)) return "an array that is not an Event";
  return "an object that is not an Event";
}

function whatOf(v) {
  if (typeof v === "function") return "a function";
  if (Array.isArray(v)) return "a sparse array";
  let name;
  try {
    name = Object.getPrototypeOf(v)?.constructor?.name;
  } catch {
    name = undefined;
  }
  return typeof name === "string" && name !== "" ? `a ${name}` : "an object";
}

function isDense(a) {
  if (Reflect.ownKeys(a).length !== a.length + 1) return false;
  for (let i = 0; i < a.length; i += 1) if (!hasOwn(a, i)) return false;
  return true;
}

function define(o, k, value) {
  Object.defineProperty(o, k, { value, enumerable: true, writable: true, configurable: true });
}

export function fake(opts = {}) {
  class Base { constructor(o) { return o; } }
  class EventBrand extends Base {
    #event = true;
    static stamp(o) { new EventBrand(o); return o; }
    static is(v) { return v !== null && typeof v === "object" && #event in v; }
  }
  class FrozenBrand extends Base {
    #frozen = true;
    static stamp(o) { new FrozenBrand(o); return o; }
    static is(v) { return v !== null && typeof v === "object" && #frozen in v; }
  }

  const EventProto = opts.tableMeta ? {} : Object.prototype;
  const seal = (o) => (opts.noFreeze ? o : Object.freeze(o));

  // copy(v, memo, keepCore, path): fresh frozen branded objects at every
  // depth; with keepCore, values the core already froze keep identity
  function copy(v, memo, keepCore, path) {
    if (v === null || (typeof v !== "object" && typeof v !== "function")) return v;
    if (keepCore && (EventBrand.is(v) || FrozenBrand.is(v))) return v;
    if (memo.has(v)) return memo.get(v);
    if (EventBrand.is(v)) {
      const e = Object.create(EventProto);
      memo.set(v, e);
      define(e, "type", v.type);
      define(e, "data", copy(v.data, memo, keepCore, `${path}.data`));
      EventBrand.stamp(e);
      return seal(e);
    }
    if (Array.isArray(v) && isDense(v)) {
      const out = [];
      memo.set(v, out);
      for (let i = 0; i < v.length; i += 1) out.push(copy(v[i], memo, keepCore, `${path}[${i}]`));
      FrozenBrand.stamp(out);
      return seal(out);
    }
    const proto = typeof v === "object" ? Object.getPrototypeOf(v) : undefined;
    if (!Array.isArray(v) && (proto === Object.prototype || proto === null)) {
      if (!opts.anyData) {
        // every own property an enumerable, string-keyed data property:
        // nothing in the caller's data is dropped or read through code
        for (const k of Reflect.ownKeys(v)) {
          const d = Object.getOwnPropertyDescriptor(v, k);
          const why = typeof k !== "string" ? "a symbol key" : !d.enumerable ? "a non-enumerable property"
            : !("value" in d) ? "an accessor" : null;
          if (why) throw new TypeError(`Event: data at ${path} is not plain data (${why})`);
        }
      }
      const out = proto === null ? Object.create(null) : {};
      memo.set(v, out);
      for (const k of Object.keys(v)) define(out, k, copy(v[k], memo, keepCore, `${path}.${k}`));
      FrozenBrand.stamp(out);
      return seal(out);
    }
    if (opts.anyData) return v;
    throw new TypeError(`Event: data at ${path} is not plain data (${whatOf(v)})`);
  }

  function freezeInPlace(v, seen = new Set()) {
    if (v === null || typeof v !== "object" || seen.has(v)) return v;
    seen.add(v);
    for (const k of Object.keys(v)) freezeInPlace(v[k], seen);
    if (!EventBrand.is(v) && !FrozenBrand.is(v)) FrozenBrand.stamp(v);
    return Object.freeze(v);
  }

  function Event(type, data) {
    if (data === undefined) data = {};
    const e = Object.create(EventProto);
    if (opts.writableAtBirth || opts.noFreeze) {
      define(e, "type", type);
      define(e, "data", data);
      return EventBrand.stamp(e);
    }
    define(e, "type", type);
    define(e, "data", opts.freezeInPlace ? freezeInPlace(data) : copy(data, new Map(), true, "data"));
    EventBrand.stamp(e);
    return Object.freeze(e);
  }
  Event.is = opts.forgeable
    ? (v) => v !== null && typeof v === "object" && typeof v.type === "string" && "data" in v
    : (v) => EventBrand.is(v);
  Object.freeze(Event.is);
  Object.freeze(Event.prototype);
  Object.freeze(Event);

  const noTransform = Object.freeze(() => undefined);
  function Gate(signature, transform, shape) {
    return Object.freeze({ signature, transform: transform === undefined ? noTransform : transform, shape });
  }
  Object.freeze(Gate.prototype);
  Object.freeze(Gate);

  // the receiver's own frozen copy
  const own = (v, memo = new Map()) => (opts.shareFrozen || opts.noFreeze ? v : copy(v, memo, false, "event"));
  // an event a gate returned, frozen if the stream must freeze it
  const freezeOut = (o) => (opts.noFreeze || Object.isFrozen(o) ? o : copy(o, new Map(), false, "event"));

  class Rejection extends Error {
    #rejection = true;
    constructor(code, message, delivery, extra = {}) {
      super(message);
      define(this, "code", code);
      define(this, "message", message);
      if (delivery) {
        define(this, "eventId", delivery.id);
        if (delivery.causeId !== undefined) define(this, "causeId", delivery.causeId);
        define(this, "eventType", delivery.type);
      }
      for (const k of Object.keys(extra)) define(this, k, extra[k]);
    }
    toString() { return this.message; }
    static is(v) { return v !== null && typeof v === "object" && #rejection in v; }
  }
  Object.defineProperty(Rejection.prototype, "name", { value: "Rejection" });
  if (!opts.richRejection && !opts.openRejection) {
    Object.freeze(Rejection.prototype.toString);
    Object.freeze(Rejection.is);
    Object.freeze(Rejection.prototype);
    Object.freeze(Rejection);
  }

  // a value's own string-keyed properties at every depth, frozen plain
  // objects and arrays (THE REJECTION); functions as themselves
  function plain(v, memo) {
    if (v === null || typeof v !== "object") return v;
    if (memo.has(v)) return memo.get(v);
    const out = Array.isArray(v) ? [] : {};
    memo.set(v, out);
    for (const k of Object.getOwnPropertyNames(v)) {
      if (Array.isArray(v) && k === "length") continue;
      let x;
      try {
        x = v[k];
      } catch {
        continue;
      }
      define(out, k, plain(x, memo));
    }
    return Object.freeze(out);
  }
  // richRejection: a copy that keeps every prototype
  function rich(v, memo) {
    if (v === null || typeof v !== "object") return v;
    if (memo.has(v)) return memo.get(v);
    const out = Object.create(Object.getPrototypeOf(v));
    memo.set(v, out);
    for (const k of Object.getOwnPropertyNames(v)) define(out, k, rich(v[k], memo));
    return Object.freeze(out);
  }
  function rejectionFor(rej) {
    if (opts.sharedRejection) return rej;
    const memo = new Map();
    const out = opts.richRejection ? Object.create(Object.getPrototypeOf(rej)) : {};
    for (const k of Object.keys(rej)) define(out, k, opts.richRejection ? rich(rej[k], memo) : plain(rej[k], memo));
    return Object.freeze(out);
  }

  function batchFor(outcome, rej, recs) {
    const memo = new Map();
    const records = recs.map((r) => {
      const o = { eventId: r.eventId };
      if (r.causeId !== undefined) o.causeId = r.causeId;
      o.eventType = r.eventType;
      o.claimed = r.claimed;
      o.data = own(r.data, memo);
      return o;
    });
    const b = { outcome };
    if (rej !== undefined) b.rejection = rejectionFor(rej);
    b.records = records;
    return b;
  }

  function notify(observers, outcome, rej, recs) {
    const shared = opts.sharedBatch ? batchFor(outcome, rej, recs) : undefined;
    const batches = observers.map(() => {
      if (shared) return shared;
      try {
        return batchFor(outcome, rej, recs);
      } catch {
        return undefined;
      }
    });
    observers.forEach((o, i) => {
      if (batches[i] === undefined) return;
      if (opts.unprotected) o(batches[i]);
      else {
        try {
          o(batches[i]);
        } catch {
          // the observer's own failure
        }
      }
    });
  }

  class Stream {
    #st = { gates: new Map(), n: 0, residue: [], observers: [], count: 0 };

    constructor() {
      if (opts.openStream) Object.assign(this, this.#st);
      else Object.freeze(this);
    }

    register(g) {
      const st = this.#st;
      if (st.gates.has(g.signature)) throw new Error(`Signature collision: '${String(g.signature)}'`);
      st.gates.set(g.signature, g);
      st.n += 1;
    }

    emit(event) {
      const st = this.#st;
      const observers = [...st.observers];
      const recs = [];
      const fail = (rej) => {
        if (!opts.observeDuring) notify(observers, "rejected", rej, recs);
        throw rej;
      };
      if (!opts.acceptAnyEntry && !Event.is(event)) {
        fail(new Rejection("SISO_NOT_EVENT", `Not an Event: the entry door was given ${describe(event)}`));
      }
      const base = opts.countIds ? st.count : 0;
      let entered;
      if (opts.entryAlias || opts.noFreeze) entered = event;
      else {
        try {
          entered = copy(event, new Map(), false, "event");
        } catch {
          fail(new Rejection("SISO_NOT_EVENT", "Not an Event: the entry door was given an Event too deep to copy"));
        }
      }
      const gateCount = st.n;
      const queue = [{ event: entered, causeId: undefined, depth: 1 }];
      const settled = [];
      let id = 0;
      for (let head = 0; head < queue.length; head += 1) {
        const item = queue[head];
        const cur = item.event;
        id += 1;
        const eventId = base + id;
        if (opts.countIds) st.count = eventId;
        const g = st.gates.get(cur.type);
        const rec = { eventId, causeId: item.causeId, eventType: cur.type, claimed: g !== undefined, data: cur.data };
        recs.push(rec);
        if (opts.observeDuring) notify(observers, "settled", undefined, [rec]);
        if (g === undefined) {
          settled.push(cur);
          continue;
        }
        const delivery = { id: eventId, causeId: item.causeId, type: cur.type, depth: item.depth };
        if (item.depth > gateCount) {
          fail(new Rejection("SISO_CYCLE", `Cycle: '${String(cur.type)}' claimed at depth ${item.depth}`, delivery, { depth: item.depth }));
        }
        let res;
        let threw = false;
        let thrown;
        try {
          res = g.transform(cur);
        } catch (e) {
          threw = true;
          thrown = e;
        }
        if (threw) fail(new Rejection("SISO_GATE_THREW", `Gate '${String(g.signature)}' threw`, delivery, { cause: thrown }));
        const notEvent = (what) => new Rejection("SISO_NOT_EVENT", `Not an Event: gate '${String(g.signature)}' returned ${what}`, delivery);
        let outs = [];
        if (res === undefined || res === null) outs = [];
        else if (Event.is(res)) outs = [res];
        else if (opts.acceptPromise && typeof res.then === "function") outs = [];
        else if (Array.isArray(res)) {
          for (let i = 0; i < res.length; i += 1) {
            const m = res[i];
            if (!Event.is(m)) {
              if (opts.skipNonEvents) continue;
              fail(notEvent(`an array whose member ${i + 1} is ${describe(m)}`));
            }
            if (m.type === g.signature) fail(new Rejection("SISO_SELF_LOOP", `Self-loop: gate '${String(g.signature)}' returned its own type`, delivery));
            outs.push(m);
          }
          if (!opts.skipNonEvents && !isDense(res)) fail(notEvent("an array with a hole or a named key"));
        } else if (opts.skipNonEvents && typeof res === "object") outs = [];
        else fail(notEvent(describe(res)));
        for (const o of outs) {
          if (o.type === g.signature) fail(new Rejection("SISO_SELF_LOOP", `Self-loop: gate '${String(g.signature)}' returned its own type`, delivery));
          queue.push({ event: freezeOut(o), causeId: eventId, depth: item.depth + 1 });
        }
      }
      st.residue.push(...settled);
      if (!opts.observeDuring) notify(observers, "settled", undefined, recs);
      return undefined;
    }

    sampleHere() {
      const st = this.#st;
      const memo = new Map();
      const s = { residue: st.residue.map((e) => own(e, memo)), gateCount: st.n };
      if (opts.sampleCount) s.eventCount = st.count;
      return s;
    }

    takeResidue() {
      const st = this.#st;
      const t = st.residue;
      st.residue = [];
      return t;
    }

    attach(o) {
      const st = this.#st;
      if (typeof o !== "function") throw new TypeError(`attach: an observer is a function, got ${typeName(o)}`);
      if (st.observers.includes(o)) throw new Error("attach: this observer is already attached");
      st.observers.push(o);
    }

    detach(o) {
      const st = this.#st;
      const i = st.observers.indexOf(o);
      if (i < 0) throw new Error("detach: this observer is not attached");
      st.observers.splice(i, 1);
    }
  }
  if (!opts.unfrozenExports) {
    for (const k of Object.getOwnPropertyNames(Stream.prototype)) Object.freeze(Stream.prototype[k]);
    Object.freeze(Stream.prototype);
  }
  Object.freeze(Stream);

  return Object.freeze({ Event, Gate, Stream });
}
