/*
contract: observer-seam — what the stream shows an observer (core 13,
JavaScript instance). Port of lua/code/contracts/observer_seam.lua.

Between: stream -> observer. Only a contracts phase changes this file.

THE DOOR
  stream.attach(observer) and stream.detach(observer) (core-api). An
  observer is a function. Attaching or detaching changes nothing the
  stream does: not the residue, not a rejection, not what a gate
  receives. Attaching a value that is not a function, attaching an
  attached observer, and detaching one that is not attached throw.

ONE CALL PER PROPAGATION, AFTER IT HAS ENDED
  The stream calls each observer attached when the entry arrives once
  per propagation, with a batch, when the propagation is over:
    settled   after its residue is committed;
    rejected  after nothing was committed, just before the rejection is
              thrown.
  No observer runs while a propagation is in progress, so an observer
  that calls a door (emit, register, takeResidue, attach, …) makes the
  next crossing; it cannot touch the propagation it is shown.
  A refused entry (SISO_NOT_EVENT at the entry door) is a propagation
  with no deliveries: its batch has no records. Records are collected
  only when an observer is attached.

THE BATCH
  {
    outcome:   "settled" | "rejected",
    rejection: the observer's own copy of the rejection emit throws
               (rejected only; on a settled batch the key is absent),
    records:   [record, …]   the deliveries, in delivery order,
  }
  and no other field. Each observer receives its own batch object and
  records array (plain, mutable, the observer's), so one observer cannot
  change what another is shown.

THE REJECTION
  A plain, frozen copy of the rejection emit throws, the observer's
  own: a frozen plain object (prototype Object.prototype) holding the
  rejection's own enumerable fields with equal values. A `cause` that is
  an object is copied at every depth as frozen plain objects (arrays as
  frozen Arrays) of its own string-keyed properties, enumerable or not
  (an Error's `message` and `stack` come along; inherited fields and
  methods do not); primitives are kept as they are. The rejection's
  `message` carries its rendering. No object in the copy is one the
  host's rejection or the gate's thrown value holds, and every
  prototype in it is Object.prototype, Array.prototype (for an array)
  or null, so nothing the core or the gate holds is reachable from it.
  Assignment fails at every depth, and nothing done to it — a write
  attempt, a private-field stamp — reaches the rejection the entering
  code receives, what the gate threw, or another observer's copy
  (core-api OWNERSHIP). toString is not promised on the copy; read its
  `message`.
  The copy is taken once per rejection (13.2.1): when the rejection is
  made, whether or not an observer is attached, the stream takes one
  plain snapshot of it, and each observer receives its own fresh copy of
  that snapshot. Code of the gate's that reading the cause runs (a
  getter, a Proxy trap) therefore runs once per rejection, the same for
  any number of observers, and every observer's copy is equal; if the
  snapshot cannot be taken, every observer's batch fails alone and the
  host's rejection is unchanged. (Copying per observer let the gate's
  code count the observers: checklist 12; found in the 13.2.0 audit.)

A RECORD
  { eventId, causeId, eventType, claimed, data } and no other field:
    eventId    1, 2, … in delivery order (core-api IDS), contiguous
    causeId    the eventId of the delivery whose gate returned this event;
               for the entry delivery the key is absent
    eventType  the event's type
    claimed    true when a gate claimed the event, false when it settled
               as residue
    data       a frozen copy of the event's data as the gate received it,
               the observer's own (one memo per observer, so data passed
               on along a chain is copied once for that observer):
               nothing done to it reaches the stream's residue or another
               observer (core-api OWNERSHIP)
  In a rejected propagation the records end with the delivery the
  rejection names: the one whose gate returned its own type or a
  non-Event (SISO_SELF_LOOP, SISO_NOT_EVENT), the one claimed too deep
  (SISO_CYCLE), or the one whose gate threw (SISO_GATE_THREW). A
  returned event that caused the rejection is never delivered, so it has
  no record.

ISOLATION
  An observer that throws is the observer's own failure. The stream
  calls observers in protected mode (try/catch): emit still returns or
  throws exactly as it would with no observer, and every other observer
  still receives its batch. Building an observer's batch (its copies
  included) is part of that observer's call: if it fails, that observer
  fails alone. Every observer's batch is built before any observer is
  called, so nothing an observer does — taking the residue and what it
  does to what it took included — reaches what another observer is
  shown. The order in which observers are called carries no meaning.

What an observer stores, numbers or labels is its own (observer-side
state, R2). The stream holds no stream id, lineage or level.

SUBSTRATE (JavaScript)
  - Lua's "getmetatable answers false or a string" becomes "every
    prototype in the copy is Object.prototype, Array.prototype for an
    array, or null"; Lua's string marks and "readonly:" sentinels vanish
    (arrays are Arrays, JSON null is null).
  - Lua's `rawset` probes become the substrate's own attempts:
    Reflect.set and Reflect.defineProperty (which answer false on a
    frozen object) and a private-field stamp (which a frozen object
    accepts in V8): a copy that is truly the observer's own carries none
    of them to anyone else. Read-only by Object.freeze; no rawset (R3's
    rawset clause vanishes).
  - "Built before any observer is called": with events frozen at every
    depth an observer that takes the residue cannot change it, so the
    probe only bites when an implementation hands out writable or shared
    objects; it is kept as the reference's probe.
  - The batch and its records are mutable plain objects, the observer's
    own (as in Lua); only the data in a record and the rejection copy are
    frozen.
  - A function in a thrown cause is code, not data: the copy may carry
    it by identity (as Lua does); rejectionCopyProblem compares it with
    === and does not count it as an object the gate holds. A gate that
    throws an object holding functions shares code with observers: the
    R3 counterpart (convention).
  - The tamper probe (an observer writing to the prototypes its copy
    hands out) writes only to prototypes that are not frozen and not
    realm intrinsics; it never writes an intrinsic.
  - A throw that is not an Error is carried as it is (`cause` is exactly
    what the gate threw).

batchProblems(batch) judges a batch's shape; rejectionCopyProblem(copy,
raised) judges one observer's rejection against the one raised (any
runner may use them). check.seam(core) and check.ownership(core) run
this contract against `core = { Event, Gate, Stream }` and return an
Array of problems (empty when it holds).
*/

const BATCH_FIELDS = Object.freeze({ outcome: true, rejection: true, records: true });
const RECORD_FIELDS = Object.freeze({ eventId: true, causeId: true, eventType: true, claimed: true, data: true });

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
const isObject = (v) => v !== null && (typeof v === "object" || typeof v === "function");
const isData = (v) => v !== null && typeof v === "object";

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

function typeName(v) {
  if (v === null) return "null";
  if (Array.isArray(v)) return "array";
  return typeof v;
}

// The realm's intrinsics, read once at load (reads only); never written.
const INTRINSICS = (() => {
  const set = new Set();
  const add = (o) => {
    while (isObject(o) && !set.has(o)) {
      set.add(o);
      o = Object.getPrototypeOf(o);
    }
  };
  for (const name of Object.getOwnPropertyNames(globalThis)) {
    const d = Object.getOwnPropertyDescriptor(globalThis, name);
    if (!d || !("value" in d) || !isObject(d.value)) continue;
    add(d.value);
    if (typeof d.value === "function") {
      const p = Object.getOwnPropertyDescriptor(d.value, "prototype");
      if (p && "value" in p) add(p.value);
    }
  }
  add(Object.getPrototypeOf(function* () {}));
  add(Object.getPrototypeOf(async function () {}));
  add(Object.getPrototypeOf(async function* () {}));
  add(Object.getPrototypeOf([][Symbol.iterator]()));
  return set;
})();

/*
batchProblems(batch) → Array of problems: the batch's own shape, and the
records' ids and causes consistent with one propagation.
*/
export function batchProblems(batch) {
  const p = [];
  if (!isData(batch)) return [`a batch is an object, got ${typeName(batch)}`];
  for (const k of Object.keys(batch)) {
    if (!hasOwn(BATCH_FIELDS, k)) p.push(`batch: unexpected field '${k}'`);
  }
  if (batch.outcome !== "settled" && batch.outcome !== "rejected") {
    p.push(`batch: outcome is ${str(batch.outcome)}`);
  }
  if (batch.outcome === "settled" && hasOwn(batch, "rejection")) p.push("batch: a settled batch carries a rejection");
  if (batch.outcome === "rejected" && !isData(batch.rejection)) p.push("batch: a rejected batch carries no rejection");
  const records = batch.records;
  if (!Array.isArray(records)) {
    p.push("batch: records is not a list");
    return p;
  }
  let dense = Reflect.ownKeys(records).length === records.length + 1;
  for (let i = 0; dense && i < records.length; i += 1) dense = hasOwn(records, i);
  if (!dense) p.push("batch: records is not a list (holes or named keys)");
  for (let i = 0; i < records.length; i += 1) {
    const id = i + 1;
    const r = records[i];
    const where = `record ${id}`;
    if (!isData(r)) {
      p.push(`${where}: not an object`);
      continue;
    }
    for (const k of Object.keys(r)) {
      if (!hasOwn(RECORD_FIELDS, k)) p.push(`${where}: unexpected field '${k}'`);
    }
    if (r.eventId !== id) p.push(`${where}: eventId is ${str(r.eventId)}, expected ${id}`);
    if (id === 1) {
      if (hasOwn(r, "causeId")) p.push(`${where}: the entry delivery has a cause`);
    } else if (!Number.isInteger(r.causeId) || r.causeId < 1 || r.causeId >= id) {
      p.push(`${where}: causeId ${str(r.causeId)} is not an earlier delivery`);
    } else if (!isData(records[r.causeId - 1]) || records[r.causeId - 1].claimed !== true) {
      p.push(`${where}: its cause was not claimed by a gate`);
    }
    if (typeof r.claimed !== "boolean") p.push(`${where}: claimed is not a boolean`);
    if (!isData(r.data)) p.push(`${where}: data is not an object`);
  }
  if (batch.outcome === "rejected" && isData(batch.rejection)) {
    const last = records[records.length - 1];
    if (batch.rejection.eventId === undefined) {
      if (records.length !== 0) p.push("batch: a refused entry has records");
    } else if (!isData(last) || last.eventId !== batch.rejection.eventId) {
      p.push("batch: the records do not end with the delivery the rejection names");
    }
  }
  return p;
}

// own string-keyed property names (enumerable or not), as the copy
// carries them
const names = (o) => Object.getOwnPropertyNames(o);

// a and b equal by value: objects through their own string-keyed
// properties, both ways; anything else (functions included) by ===
function same(a, b, depth = 0) {
  if (!isData(a) || !isData(b)) return a === b;
  if (a === b) return true;
  if (depth > 100) return false;
  for (const k of names(a)) {
    if (!hasOwn(b, k) || !same(a[k], b[k], depth + 1)) return false;
  }
  for (const k of names(b)) {
    if (!hasOwn(a, k)) return false;
  }
  return true;
}

// every object reachable from v by own string-keyed property values
function objectsIn(v) {
  const into = new Set();
  const stack = [v];
  while (stack.length > 0) {
    const x = stack.pop();
    if (!isData(x) || into.has(x)) continue;
    into.add(x);
    for (const k of names(x)) {
      const d = Object.getOwnPropertyDescriptor(x, k);
      if (d && "value" in d) stack.push(d.value);
    }
  }
  return into;
}

// the first object in v (v included) that is one of `raised`'s, has a
// prototype other than Object.prototype / Array.prototype (arrays) /
// null, an accessor, or is not frozen
function notPlainCopy(v, raised, path) {
  const seen = new Set();
  const stack = [[v, path]];
  while (stack.length > 0) {
    const [x, where] = stack.pop();
    if (!isData(x) || seen.has(x)) continue;
    seen.add(x);
    if (raised.has(x)) return `${where} is the raised object itself, not a copy`;
    const proto = Object.getPrototypeOf(x);
    if (!(proto === null || proto === Object.prototype || (proto === Array.prototype && Array.isArray(x)))) {
      return `${where} has a prototype that is not Object.prototype, Array.prototype or null`;
    }
    for (const k of Reflect.ownKeys(x)) {
      const d = Object.getOwnPropertyDescriptor(x, k);
      if (!("value" in d)) return `${where}.${String(k)} is an accessor, not a copied value`;
      stack.push([d.value, `${where}.${String(k)}`]);
    }
    if (x !== v && !Object.isFrozen(x)) return `${where} accepts assignment`;
  }
  return null;
}

/*
rejectionCopyProblem(copy, raised) → null when `copy` is an observer's
own copy of the rejection `raised` (THE REJECTION), else a string naming
what is wrong: the same object (at any depth), an object with a
prototype other than Object.prototype / Array.prototype / null, a field
missing, added or unequal (an object `cause` compared by its own
string-keyed properties), or a copy that accepts assignment.
*/
export function rejectionCopyProblem(copy, raised) {
  try {
    if (!isData(copy)) return "the batch's rejection is not an object";
    if (copy === raised) return "the batch's rejection is the very object raised, not the observer's copy";
    const plain = notPlainCopy(copy, objectsIn(raised), "the batch's rejection");
    if (plain) return plain;
    for (const k of Object.keys(raised)) {
      if (!hasOwn(copy, k) || !same(raised[k], copy[k])) {
        return `the batch's rejection differs from the one raised at '${k}'`;
      }
    }
    for (const k of Object.keys(copy)) {
      if (!hasOwn(raised, k) || !Object.prototype.propertyIsEnumerable.call(raised, k)) {
        return `the batch's rejection has a field the raised one lacks: '${k}'`;
      }
    }
    if (!Object.isFrozen(copy)) return "the batch's rejection accepts assignment";
    return null;
  } catch (err) {
    return `judging the batch's rejection threw ${str(err)}`;
  }
}

function seam(core) {
  const p = [];
  const { Event, Gate, Stream } = core;
  const fail = (s) => { p.push(s); };
  const addAll = (label, list) => { for (const s of list) fail(`${label}: ${s}`); };
  const recorder = (into) => (b) => { into.push(b); };
  const types = (b) => b.records.map((r) => `${r.eventType}${r.claimed ? "+" : "-"}<${str(r.causeId)}`).join(" ");
  const throws = (fn) => {
    try {
      fn();
      return false;
    } catch {
      return true;
    }
  };

  try {
    // a settled propagation: one batch, after the residue is committed
    const s = new Stream();
    const got = [];
    let residueSeen;
    s.register(Gate("root", () => [Event("a"), Event("b")]));
    s.register(Gate("a", () => Event("a1")));
    s.attach((b) => { got.push(b); residueSeen = s.sampleHere().residue.length; });
    s.emit(Event("root", { k: 1 }));
    if (got.length !== 1) fail(`a settled propagation gave ${got.length} batches`);
    else {
      const b = got[0];
      addAll("settled", batchProblems(b));
      if (b.outcome !== "settled") fail(`settled: outcome ${str(b.outcome)}`);
      if (b.records.length !== 4) fail(`settled: ${b.records.length} records, expected 4: ${types(b)}`);
      if (b.records[0].data.k !== 1) fail("settled: the entry record does not carry its data");
      if (residueSeen !== 2) fail("settled: the observer ran before the residue was committed");
    }
    // the next propagation on the same stream numbers its own deliveries
    s.emit(Event("root"));
    if (got.length === 2) addAll("second propagation", batchProblems(got[1]));

    // a rejected propagation: the batch carries the observer's own copy
    // of the rejection raised (THE REJECTION)
    const r = new Stream();
    const rb = [];
    r.register(Gate("a", () => Event("b")));
    r.register(Gate("b", () => { throw new Error("boom"); }));
    r.attach(recorder(rb));
    let raised;
    let okE = true;
    try {
      r.emit(Event("a"));
    } catch (e) {
      okE = false;
      raised = e;
    }
    if (okE) fail("rejected: the gate's throw did not reject");
    if (rb.length !== 1) fail(`rejected: ${rb.length} batches`);
    else {
      addAll("rejected", batchProblems(rb[0]));
      const why = rejectionCopyProblem(rb[0].rejection, raised);
      if (why) fail(`rejected: ${why}`);
      if (rb[0].records.length !== 2) fail(`rejected: ${rb[0].records.length} records, expected 2`);
    }

    // a refused entry: a batch with no records
    const e = new Stream();
    const eb = [];
    e.attach(recorder(eb));
    try { e.emit("not an event"); } catch { /* the refusal */ }
    if (eb.length !== 1 || eb[0].records.length !== 0 || eb[0].outcome !== "rejected") {
      fail("refused entry: expected one rejected batch with no records");
    }

    // an observer that throws changes nothing, and others still see the batch
    const t = new Stream();
    const tb = [];
    t.register(Gate("x", () => Event("y")));
    t.attach(() => { throw new Error("observer failure"); });
    t.attach(recorder(tb));
    try {
      t.emit(Event("x"));
    } catch (errT) {
      fail(`a raising observer made emit raise: ${str(errT)}`);
    }
    if (t.sampleHere().residue.length !== 1) fail("a raising observer changed the residue");
    if (tb.length !== 1) fail("a raising observer kept another from its batch");

    // an observer that calls doors makes the next crossing; the
    // propagation it saw is untouched
    const u = new Stream();
    let fired = false;
    u.register(Gate("a", () => Event("b")));
    u.attach(() => {
      if (fired) return;
      fired = true;
      u.register(Gate("b", () => Event("late")));
      u.emit(Event("other"));
    });
    let crossed = null;
    try {
      u.emit(Event("a"));
    } catch (errU) {
      crossed = `it was rejected (${str(isData(errU) ? errU.code : errU)})`;
    }
    const types2 = u.takeResidue().map((ev) => ev.type).sort();
    if (crossed !== null || types2.join(",") !== "b,other") {
      fail(`an observer's crossings reached the propagation it was shown: ${crossed ?? `residue ${types2.join(",")}`}`);
    }

    // each observer gets its own batch
    const v = new Stream();
    let seen2;
    v.attach((b) => { b.records.pop(); b.outcome = "x"; });
    v.attach((b) => { seen2 = b; });
    v.emit(Event("q"));
    if (!seen2 || seen2.outcome !== "settled" || seen2.records.length !== 1) {
      fail("one observer's writes to its batch reached another observer");
    }

    // detach: no more batches; misuse throws
    const w = new Stream();
    const wb = [];
    const f = recorder(wb);
    if (!throws(() => w.attach(42))) fail("attaching a value that is not a function did not raise");
    w.attach(f);
    if (!throws(() => w.attach(f))) fail("attaching an attached observer did not raise");
    w.detach(f);
    w.emit(Event("z"));
    if (wb.length !== 0) fail("a detached observer was called");
    if (!throws(() => w.detach(f))) fail("detaching an observer that is not attached did not raise");

    // attaching changes nothing: same entry, same residue and rejection
    const run = (withObserver) => {
      const x = new Stream();
      x.register(Gate("a", () => [Event("b"), Event("c")]));
      x.register(Gate("b", () => Event("a")));
      if (withObserver) x.attach(() => {});
      try {
        x.emit(Event("a"));
        return "settled";
      } catch (rr) {
        return `${str(rr.code)}@${str(rr.eventId)}/${str(rr.depth)}`;
      }
    };
    if (run(false) !== run(true)) fail("attaching an observer changed a rejection");
  } catch (err) {
    fail(`raised ${str(err)}`);
  }
  return p;
}

function ownership(core) {
  const p = [];
  const { Event, Stream } = core;
  const fail = (s) => { p.push(s); };
  // this call's own private-field stamp and write log: the substrate's
  // attempts at Lua's rawset
  class Base { constructor(o) { return o; } }
  class Stamp extends Base {
    #mark = true;
    static put(o) {
      try {
        if (!(#mark in o)) new Stamp(o);
      } catch {
        // a substrate that refuses the stamp: the write log still holds
      }
    }
    static on(o) { return isObject(o) && #mark in o; }
  }
  const touched = new WeakSet();
  const touch = (o, k, v) => {
    if (!isObject(o)) return;
    Reflect.set(o, k, v);
    Stamp.put(o);
    touched.add(o);
  };
  const reached = (o) => isObject(o) && (touched.has(o) || Stamp.on(o));
  const refusesWrite = (fn) => {
    try {
      fn();
      return false;
    } catch {
      return true;
    }
  };

  try {
    // both attach orders, since the order observers are called in is free
    for (const writerFirst of [true, false]) {
      const s = new Stream();
      let seen;
      const writer = (b) => {
        const d = b.records[0].data;
        if (!refusesWrite(() => { d.v = 5; })) fail("an observer's record data accepts assignment");
        touch(d, "v", 99);
        touch(d.n, 0, 9);
      };
      const reader = (b) => { seen = b.records[0].data; };
      if (writerFirst) { s.attach(writer); s.attach(reader); } else { s.attach(reader); s.attach(writer); }
      s.emit(Event("x", { v: 1, n: [1] }));
      if (seen.v !== 1 || seen.n[0] !== 1 || reached(seen) || reached(seen.n)) {
        fail("an observer's write or stamp reached another observer's record");
      }
      const r = s.takeResidue()[0];
      if (r.data.v !== 1 || r.data.n[0] !== 1 || reached(r.data) || reached(r.data.n)) {
        fail("an observer's write or stamp reached the residue");
      }
    }

    // the rejection (THE REJECTION): each observer's copy is its own;
    // nothing done to it reaches the raised one or another observer
    for (const writerFirst of [true, false]) {
      const s = new Stream();
      const thrown = { first: "the gate's own", nested: [1] };
      s.register(core.Gate("a", () => { throw thrown; }));
      let seen;
      const writer = (b) => {
        const r = b.rejection;
        if (!refusesWrite(() => { r.code = "CHANGED"; })) fail("an observer's rejection accepts assignment");
        touch(r, "code", "CHANGED");
        touch(r, "eventId", 99);
        if (isData(r.cause)) {
          touch(r.cause, "first", "CHANGED");
          if (isData(r.cause.nested)) touch(r.cause.nested, 0, 9);
        }
      };
      const reader = (b) => { seen = b.rejection; };
      if (writerFirst) { s.attach(writer); s.attach(reader); } else { s.attach(reader); s.attach(writer); }
      let raised;
      let okE = true;
      try {
        s.emit(Event("a"));
      } catch (e) {
        okE = false;
        raised = e;
      }
      if (okE) fail("rejection ownership: the gate's throw did not reject");
      else {
        if (!isData(raised) || raised.code !== "SISO_GATE_THREW" || raised.eventId !== 1 || reached(raised)) {
          fail("an observer's write or stamp reached the rejection emit raised");
        } else if (raised.cause !== thrown || thrown.first !== "the gate's own" || thrown.nested[0] !== 1 ||
            reached(thrown) || reached(thrown.nested)) {
          fail("an observer's write or stamp reached what the gate raised");
        }
        if (!isData(seen) || seen.code !== "SISO_GATE_THREW" || seen.eventId !== 1 || reached(seen) ||
            !isData(seen.cause) || seen.cause.first !== "the gate's own" || reached(seen.cause) ||
            !isData(seen.cause.nested) || seen.cause.nested[0] !== 1 || reached(seen.cause.nested)) {
          fail("an observer's write or stamp on its rejection reached another observer's");
        }
      }
    }

    // observers are shown batches built before any is called: one that
    // takes the residue and writes to it reaches no other (both orders)
    for (const takerFirst of [true, false]) {
      const s = new Stream();
      let seenData;
      const taker = () => {
        for (const e of s.takeResidue()) touch(e.data, "v", "TAKER WROTE");
      };
      const reader = (b) => { seenData = b.records[0].data; };
      if (takerFirst) { s.attach(taker); s.attach(reader); } else { s.attach(reader); s.attach(taker); }
      s.emit(Event("x", { v: 1 }));
      if (!isData(seenData) || seenData.v !== 1 || reached(seenData)) {
        fail(`an observer that took the residue and wrote to it reached another observer's records (${takerFirst ? "taker first" : "reader first"})`);
      }
    }

    // nothing the core or the gate holds is reachable from an observer's
    // rejection: an observer that writes to whatever prototypes its copy
    // hands out (never a realm intrinsic, never a frozen one) changes
    // neither the host's rejection nor what the gate threw nor a later
    // stream's rejection
    class IoError {
      constructor() { this.msg = "m"; }
    }
    IoError.prototype.kind = "io";
    const tamper = (b) => {
      const r = b.rejection;
      for (const o of [r, isData(r) ? r.cause : undefined]) {
        if (!isData(o)) continue;
        const proto = Object.getPrototypeOf(o);
        if (!isObject(proto) || INTRINSICS.has(proto) || Object.isFrozen(proto)) continue;
        try {
          Object.defineProperty(proto, "toString", { value: () => "OBSERVER WROTE", configurable: true, writable: true });
          proto.kind = "OBSERVER WROTE";
          proto.missing = "OBSERVER WROTE";
        } catch {
          // refused
        }
      }
    };
    const m = new Stream();
    m.register(core.Gate("a", () => { throw new IoError(); }));
    m.attach(tamper);
    let raisedM;
    try { m.emit(Event("a")); } catch (e) { raisedM = e; }
    let renderedM;
    try { renderedM = String(raisedM); } catch { renderedM = undefined; }
    if (!isData(raisedM) || renderedM !== raisedM.message || raisedM.missing !== undefined) {
      fail("an observer reached the host's rejection through its copy's prototype");
    } else if (!isData(raisedM.cause) || raisedM.cause.kind !== "io" || raisedM.cause.missing !== undefined) {
      fail("an observer reached what the gate raised through its copy's prototype");
    }
    const later = new Stream();
    later.register(core.Gate("a", () => { throw new Error("boom"); }));
    let raisedL;
    try { later.emit(Event("a")); } catch (e) { raisedL = e; }
    let renderedL;
    try { renderedL = String(raisedL); } catch { renderedL = undefined; }
    if (!isData(raisedL) || renderedL !== raisedL.message || raisedL.missing !== undefined) {
      fail("an observer's write through its copy's prototype reached a later stream's rejection");
    }
    const m2 = new Stream();
    m2.register(core.Gate("a", () => { throw new IoError(); }));
    let shown;
    m2.attach((b) => { shown = b.rejection; });
    let raised2;
    try { m2.emit(Event("a")); } catch (e) { raised2 = e; }
    const why = rejectionCopyProblem(shown, raised2);
    if (why) fail(`an error object thrown by a gate: ${why}`);
    if (isData(shown) && isData(shown.cause) && shown.cause.msg !== "m") {
      fail("an observer's copy lost the cause's own field");
    }
    if (isData(shown) && isData(shown.cause) && shown.cause.kind !== undefined) {
      fail("an observer's copy carries the cause's inherited field");
    }

    // the snapshot is taken once per rejection: a getter on what the gate
    // threw runs the same number of times with 0, 1 or 3 observers, and
    // every observer's copy is equal (13.2.1)
    const runs = {};
    for (const count of [0, 1, 3]) {
      let reads = 0;
      const thrown = {};
      Object.defineProperty(thrown, "n", { enumerable: true, get: () => { reads += 1; return reads; } });
      const cs = new Stream();
      cs.register(core.Gate("c", () => { throw thrown; }));
      const copies = [];
      for (let i = 0; i < count; i += 1) cs.attach((b) => { copies.push(b.rejection); });
      try { cs.emit(Event("c")); } catch { /* the rejection */ }
      runs[count] = reads;
      for (const c of copies) {
        if (!isData(c) || !isData(c.cause) || !isData(copies[0].cause) || c.cause.n !== copies[0].cause.n) {
          fail("observers of one rejection were shown unequal copies of its cause");
          break;
        }
      }
    }
    if (new Set(Object.values(runs)).size !== 1) {
      fail(`the gate's code ran a different number of times with 0, 1 and 3 observers attached (${
        Object.entries(runs).map(([k, v]) => `${k}: ${v}`).join(", ")}): attaching observers changed what the propagation did`);
    }
  } catch (err) {
    fail(`raised ${str(err)}`);
  }
  return p;
}

export const check = Object.freeze({ seam, ownership });
