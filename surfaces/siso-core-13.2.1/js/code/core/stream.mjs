/*
core/stream.mjs — Stream, the place where events propagate.

Contracts: js-core-api (the doors, ids, rejections, ownership) and
js-observer-seam (what an observer is shown). Core 13. Port of
lua/code/core/stream.lua.

  import { Stream } from "./stream.mjs";
  const s = new Stream()   no gates, no residue, no observers; takes no
                           options (any argument is ignored)
  s.register(gate)         building; a duplicate signature throws an Error
                           "Signature collision: '<sig>'"
  s.emit(event)            entry; one propagation until it settles,
                           returns undefined, or throws a rejection
  s.takeResidue()          take; → the residue as an Array, stream emptied
  s.sampleHere()           sample; → { residue: <the sampler's own frozen
                           copies of the events>, gateCount }
  s.attach(fn) / s.detach(fn)
                           observe; attaching a value that is not a
                           function, an attached observer, or detaching
                           one that is not attached throws

The stream's state (gates, residue, observers) is in private fields: a
stream has no own properties and the instance is frozen at construction
(none can be added, no door shadowed; 13.1.0), and Stream, its prototype
and every door are frozen, so the doors are the only way in (Lua's R5
thins to the intrinsics convention below).

Intrinsics (core-api SUBSTRATE, 13.1.0): host code that rewrites an
intrinsic a door calls would be handed the stream's private objects as
`this`. That is a global write by trusted code and is not defended, but
where it costs nothing the stream does not offer it: the built-ins it
calls (Map.prototype.get/set/has, Reflect.apply, Object.freeze, …) are
captured when the module loads, and its private lists are walked and
grown with plain index loops (no slice, map, push, includes, splice or
iterator on private state).

Registration reads the gate's signature and transform once and keeps
them; the gate object itself is never handed to anything. A gate's
transform is called with the event as its only argument and no receiver
(`this` undefined).

Delivery is an iterative FIFO queue (breadth-first): a gate's outputs
join the back of the queue, so no call-stack depth is spent and chain
length is bounded by acyclicity alone. The order is an implementation
choice (K1), not semantics. Each queue entry carries its cause id and
depth beside the event (K4); none of them is ever put on the event.

Ids are per propagation: each emit numbers its deliveries 1, 2, … in a
local, so nothing is counted across propagations and no id outlives the
propagation except in a rejection or an observer's batch.

A gate's return is judged before anything it returned is queued:
  undefined, null   consumed
  an Event          one follow-up
  an array          read once, member by member in index order: the
                    first member that is not an Event (SISO_NOT_EVENT)
                    or is the gate's own type (SISO_SELF_LOOP) decides;
                    then its keys: anything but the indices and `length`
                    (a hole, a named key) is SISO_NOT_EVENT
  anything else     SISO_NOT_EVENT (a Promise from an async gate included)
Nothing a gate returns is dropped or repaired (Drift 7). X1 (SPEC.md)
applied: the stream queues exactly the members the judgement read, never
a second reading of the array (a Proxy that lies is recorded, not
defended: core-api SUBSTRATE).

Read-only events: every event is frozen at every depth from construction
(core/event.mjs), and an Event cannot be forged, so an event a gate
returns is queued as it is, keeping its identity (inside a propagation a
gate shares its input with the stream).

Ownership (core-api OWNERSHIP, observer-seam RECORD data and THE
REJECTION): entry takes the stream's own copy of the event (freeze.copy:
fresh identities at every depth), so nothing the entering code holds —
the event it built, residue it took and enters again, a private-field
stamp it put on either — reaches the residue or a later propagation. A
take hands the residue over. A sample's residue holds freeze.copy of each
event (one memo per sample), each observer's records carry freeze.copy of
the data (one memo per observer), and each observer's rejection is its
own fresh copy of one snapshot (below). No record copy is made for
observers when none is attached. Every copy's cost grows with what it
copies, never with history.

Rejections (K5, core/rejection.mjs) commit nothing: residue is gathered
in a local list and joins the stream's only when the propagation
settles. An entry the stream cannot copy is refused as SISO_NOT_EVENT
(never thrown raw); the copies are iterative, so on this substrate that
is a guard, not an expected path.

The rejection's snapshot (observer-seam THE REJECTION, 13.2.1): when a
rejection is made, whether or not an observer is attached, the stream
takes one plain snapshot of its fields (freeze.plain, one memo: the
cause's own string-keyed properties at every depth, frozen). Reading the
cause is the only place the gate's code (a getter, a Proxy trap) can run
after the gate returned, so it runs once per rejection, the same for any
number of observers, and the gate cannot count them. Each observer then
receives freeze.plain of that snapshot, a fresh copy of the core's own
frozen plain data, which runs no foreign code, so every observer's copy
is equal. If the snapshot cannot be taken, every observer's batch fails
alone (no observer is called) and the host's rejection is unchanged.

Observers (observer-seam): the observers attached when the entry arrives
are each called once, after the propagation ended — settled: after the
residue is committed; rejected: just before the rejection is thrown —
with no receiver and with a batch of their own. Every observer's batch
is built, each under its own protection, before any observer is called:
a batch that cannot be built fails that observer alone, an observer that
throws fails alone, and emit returns or throws exactly as with no
observer. Records are collected only when an observer is attached.
*/

import { isEvent, copy, plain } from "./freeze.mjs";
import { Rejection, describe } from "./rejection.mjs";

// ── intrinsics, captured at load ────────────────
const uncurry = (f) => Function.prototype.call.bind(f);
const MapCtor = Map;
const mapGet = uncurry(Map.prototype.get);
const mapSet = uncurry(Map.prototype.set);
const mapHas = uncurry(Map.prototype.has);
const mapSize = uncurry(Object.getOwnPropertyDescriptor(Map.prototype, "size").get);
const hasOwn = uncurry(Object.prototype.hasOwnProperty);
const apply = Reflect.apply;
const ownKeys = Reflect.ownKeys;
const objectKeys = Object.keys;
const defineProp = Object.defineProperty;
const freezeObj = Object.freeze;
const isArray = Array.isArray;
const StringCtor = String;
const ErrorCtor = Error;
const TypeErrorCtor = TypeError;

function str(v) {
  try {
    return StringCtor(v);
  } catch {
    return typeof v;
  }
}

/*
judge(result, signature, delivery) → { outputs } (an Array of Events,
possibly empty, exactly the members read), or { rejection }.
*/
function judge(result, signature, delivery) {
  if (result === undefined || result === null) return { outputs: [] };
  if (isEvent(result)) {
    if (result.type === signature) return { rejection: Rejection.selfLoop(delivery, signature) };
    return { outputs: [result] };
  }
  let array = false;
  try {
    array = isArray(result);
  } catch {
    array = false; // a revoked Proxy
  }
  if (!array) return { rejection: Rejection.notEvent(delivery, signature, describe(result)) };
  try {
    const n = result.length;
    const outputs = [];
    for (let i = 0; i < n; i += 1) {
      const member = result[i];
      if (!isEvent(member)) {
        return { rejection: Rejection.notEvent(delivery, signature, `an array whose member at index ${i} is ${describe(member)}`) };
      }
      if (member.type === signature) return { rejection: Rejection.selfLoop(delivery, signature) };
      outputs[i] = member;
    }
    const keys = ownKeys(result);
    let exact = keys.length === n + 1 && hasOwn(result, "length");
    for (let i = 0; exact && i < n; i += 1) exact = hasOwn(result, i);
    if (!exact) {
      return { rejection: Rejection.notEvent(delivery, signature, "an array with a hole or a named key") };
    }
    return { outputs };
  } catch {
    // reading the array threw (a Proxy's trap): it is not an array of Events
    return { rejection: Rejection.notEvent(delivery, signature, "an array that cannot be read") };
  }
}

// The rejection's one plain snapshot (13.2.1): a frozen plain object of
// its own enumerable fields, `cause` copied at every depth with one memo.
// → the snapshot, or FAILED when reading the cause threw.
const FAILED = freezeObj({});
function snapshotOf(rejection) {
  try {
    const memo = new MapCtor();
    const fields = {};
    const keys = objectKeys(rejection);
    for (let i = 0; i < keys.length; i += 1) {
      const k = keys[i];
      defineProp(fields, k, { __proto__: null, value: plain(rejection[k], memo), enumerable: true, writable: true, configurable: true });
    }
    return freezeObj(fields);
  } catch {
    return FAILED;
  }
}

// One observer's batch: its own batch and records objects, its own
// frozen copy of each record's data (one memo per observer) and, when
// rejected, its own fresh copy of the rejection's snapshot (core-frozen
// plain data: copying it runs no foreign code).
function batchFor(outcome, snapshot, records, nRecords) {
  const memo = new MapCtor();
  const own = [];
  for (let i = 0; i < nRecords; i += 1) {
    const r = records[i];
    const rec = { eventId: r.eventId };
    if (r.causeId !== undefined) rec.causeId = r.causeId;
    rec.eventType = r.eventType;
    rec.claimed = r.claimed;
    rec.data = copy(r.data, memo);
    own[i] = rec;
  }
  const batch = { outcome };
  if (snapshot !== undefined) {
    if (snapshot === FAILED) throw new ErrorCtor("the rejection's snapshot could not be taken");
    batch.rejection = plain(snapshot, new MapCtor());
  }
  batch.records = own;
  return batch;
}

// Every observer's batch is built (each under protection) before any
// observer is called; each observer is called under protection.
function notify(observers, outcome, snapshot, records, nRecords) {
  const n = observers.length;
  if (n === 0) return;
  const batches = [];
  for (let i = 0; i < n; i += 1) {
    try {
      batches[i] = batchFor(outcome, snapshot, records, nRecords);
    } catch {
      batches[i] = undefined;
    }
  }
  for (let i = 0; i < n; i += 1) {
    if (batches[i] === undefined) continue;
    try {
      apply(observers[i], undefined, [batches[i]]);
    } catch {
      // the observer's own failure
    }
  }
}

export class Stream {
  #gates = new MapCtor();
  #residue = [];
  #observers = [];

  constructor() {
    freezeObj(this);
  }

  register(gate) {
    const signature = gate.signature;
    const transform = gate.transform;
    if (mapHas(this.#gates, signature)) throw new ErrorCtor(`Signature collision: '${str(signature)}'`);
    mapSet(this.#gates, signature, { signature, transform });
  }

  emit(event) {
    const attached = this.#observers;
    const observers = [];
    for (let i = 0; i < attached.length; i += 1) observers[i] = attached[i];
    const keep = observers.length > 0;
    const records = [];
    let nRecords = 0;
    const reject = (rejection) => {
      // one snapshot per rejection, taken whether or not an observer is
      // attached (13.2.1)
      const snapshot = snapshotOf(rejection);
      notify(observers, "rejected", snapshot, records, nRecords);
      throw rejection;
    };

    if (!isEvent(event)) reject(Rejection.notEvent(undefined, undefined, describe(event)));

    // the stream's own copy: nothing the entering code holds reaches it
    let entered;
    try {
      entered = copy(event);
    } catch {
      reject(Rejection.notEvent(undefined, undefined, "an Event too deep to copy"));
    }

    const gates = this.#gates;
    const gateCount = mapSize(gates);
    const queue = [{ event: entered, causeId: undefined, depth: 1 }];
    let tail = 1;
    const settled = [];
    let nSettled = 0;
    let lastId = 0;

    for (let head = 0; head < tail; head += 1) {
      const item = queue[head];
      queue[head] = undefined;
      const current = item.event;
      lastId += 1;
      const delivery = { id: lastId, causeId: item.causeId, type: current.type, depth: item.depth };
      const gate = mapGet(gates, current.type);

      if (keep) {
        records[nRecords] = {
          eventId: lastId,
          causeId: item.causeId,
          eventType: current.type,
          claimed: gate !== undefined,
          data: current.data,
        };
        nRecords += 1;
      }

      if (gate === undefined) {
        settled[nSettled] = current;
        nSettled += 1;
        continue;
      }
      if (item.depth > gateCount) reject(Rejection.cycle(delivery, gateCount));
      let result;
      try {
        result = apply(gate.transform, undefined, [current]);
      } catch (thrown) {
        reject(Rejection.gateThrew(delivery, gate.signature, thrown));
      }
      const judged = judge(result, gate.signature, delivery);
      if (judged.rejection !== undefined) reject(judged.rejection);
      const outputs = judged.outputs;
      for (let i = 0; i < outputs.length; i += 1) {
        queue[tail] = { event: outputs[i], causeId: lastId, depth: item.depth + 1 };
        tail += 1;
      }
    }

    const residue = this.#residue;
    let n = residue.length;
    for (let i = 0; i < nSettled; i += 1) {
      residue[n] = settled[i];
      n += 1;
    }
    notify(observers, "settled", undefined, records, nRecords);
    return undefined;
  }

  takeResidue() {
    const taken = this.#residue;
    this.#residue = [];
    return taken;
  }

  sampleHere() {
    const memo = new MapCtor();
    const held = this.#residue;
    const residue = [];
    for (let i = 0; i < held.length; i += 1) residue[i] = copy(held[i], memo);
    return { residue, gateCount: mapSize(this.#gates) };
  }

  attach(observer) {
    if (typeof observer !== "function") {
      throw new TypeErrorCtor(`attach: an observer is a function, got ${observer === null ? "null" : typeof observer}`);
    }
    const list = this.#observers;
    for (let i = 0; i < list.length; i += 1) {
      if (list[i] === observer) throw new ErrorCtor("attach: this observer is already attached");
    }
    list[list.length] = observer;
  }

  detach(observer) {
    const list = this.#observers;
    for (let i = 0; i < list.length; i += 1) {
      if (list[i] === observer) {
        for (let j = i + 1; j < list.length; j += 1) list[j - 1] = list[j];
        list.length -= 1;
        return;
      }
    }
    throw new ErrorCtor("detach: this observer is not attached");
  }
}

for (const k of Object.getOwnPropertyNames(Stream.prototype)) Object.freeze(Stream.prototype[k]);
Object.freeze(Stream.prototype);
Object.freeze(Stream);

export default Stream;
