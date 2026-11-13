// contract test: core-api (js/code/contracts/core_api.mjs).
// Port of lua/sisoTests/contract-core_api.test.lua. Shows the contract's
// checks pass on an implementation that honours it and catch each
// departure the reference's history names, plus the JavaScript ones. The
// core itself is checked against the contract by the stream phases'
// tests.
import * as H from "../code/harness.mjs";
import { check, rejectionProblems } from "../code/contracts/core_api.mjs";
import { fake } from "./contract-fake-core.mjs";

const joined = (list) => list.join("; ");
const has = (list, needle) => list.some((s) => s.includes(needle));

// a rejection as the core builds one: an Error named "Rejection" whose
// fields are own and enumerable, rendered by its message
class R extends Error {
  constructor(fields) {
    super(fields.message);
    for (const [k, v] of Object.entries(fields)) {
      Object.defineProperty(this, k, { value: v, enumerable: true, writable: true, configurable: true });
    }
  }
  toString() { return this.message; }
}
Object.defineProperty(R.prototype, "name", { value: "Rejection" });

H.test("doors hold on an implementation that honours the contract", () => {
  const p = check.doors(fake());
  H.assert(p.length === 0, joined(p));
});

H.test("read-only holds on an implementation that honours the contract", () => {
  const p = check.readonly(fake());
  H.assert(p.length === 0, joined(p));
});

H.test("ownership holds on an implementation that honours the contract", () => {
  const p = check.ownership(fake());
  H.assert(p.length === 0, joined(p));
});

H.test("11.1 X1: skipping non-Event outputs is caught", () => {
  const p = check.doors(fake({ skipNonEvents: true }));
  H.assert(has(p, "output not an Event"), joined(p));
});

H.test("11.1: an entry door that takes any value is caught", () => {
  const p = check.doors(fake({ acceptAnyEntry: true }));
  H.assert(has(p, "entry not an Event"), joined(p));
});

H.test("11.1 R1: ids counted across propagations are caught", () => {
  const p = check.doors(fake({ countIds: true }));
  H.assert(has(p, "gate threw, again"), joined(p));
});

H.test("11.1 R1: an event counter in the sample is caught", () => {
  const p = check.doors(fake({ sampleCount: true }));
  H.assert(has(p, "sampleHere carries"), joined(p));
});

H.test("11.1 R3: writable events are caught", () => {
  const p = check.readonly(fake({ noFreeze: true }));
  H.assert(has(p, "was accepted"), joined(p));
});

H.test("12.0: an event writable until it enters the stream is caught", () => {
  const p = check.readonly(fake({ writableAtBirth: true }));
  H.assert(has(p, "a new event"), joined(p));
  H.assert(has(p, "passed to Event reached the event"), joined(p));
});

H.test("12.0.1 and 13.0.0: events that share a writable prototype are caught", () => {
  const p = check.readonly(fake({ tableMeta: true }));
  H.assert(has(p, "prototype is not Object.prototype"), joined(p));
  H.assert(has(p, "carried a count"), joined(p));
});

H.test("13.0.0: an entry that keeps the entering code's own event is caught", () => {
  const p = check.ownership(fake({ entryAlias: true }));
  H.assert(has(p, "the residue holds the entering code's own event"), joined(p));
  H.assert(has(p, "carried a count from one emit to the next"), joined(p));
});

H.test("12.0 X6: samples that share the stream's frozen objects are caught", () => {
  const p = check.ownership(fake({ shareFrozen: true }));
  H.assert(has(p, "reached the stream"), joined(p));
});

H.test("JS X1: an Event.is that answers by shape is caught", () => {
  const p = check.doors(fake({ forgeable: true }));
  H.assert(has(p, "Event.is accepts"), joined(p));
  H.assert(has(p, "entry a Proxy around an Event"), joined(p));
});

H.test("JS: data that is not plain data taken into an event is caught", () => {
  const p = check.readonly(fake({ anyData: true }));
  H.assert(has(p, "Event accepted a function in data"), joined(p));
  H.assert(has(p, "Event accepted a Map in data"), joined(p));
});

H.test("JS: an Event that freezes the caller's objects in place is caught", () => {
  const p = check.readonly(fake({ freezeInPlace: true }));
  H.assert(has(p, "froze the caller's own object in place"), joined(p));
});

H.test("JS R5: a stream whose state is on own properties is caught", () => {
  const p = check.doors(fake({ openStream: true }));
  H.assert(has(p, "a stream's own keys"), joined(p));
});

H.test("JS: core exports left writable are caught", () => {
  const p = check.readonly(fake({ unfrozenExports: true }));
  H.assert(has(p, "Stream.prototype is not frozen"), joined(p));
});

H.test("JS: a writable rejection prototype is caught", () => {
  const p = check.readonly(fake({ openRejection: true }));
  H.assert(has(p, "a rejection's prototype"), joined(p));
});

H.test("JS Drift 7: a gate's Promise taken as consumed is caught", () => {
  const p = check.doors(fake({ acceptPromise: true }));
  H.assert(has(p, "output a Promise"), joined(p));
});

H.test("no check writes a realm intrinsic", () => {
  const intrinsics = [Object.prototype, Array.prototype, Function.prototype, Error.prototype, Object, Array, Error, globalThis];
  const before = intrinsics.map((o) => Reflect.ownKeys(o).map(String).sort().join(","));
  const switches = [{}, { tableMeta: true }, { noFreeze: true }, { writableAtBirth: true }, { openRejection: true },
    { anyData: true }, { shareFrozen: true }, { entryAlias: true }, { forgeable: true }];
  for (const s of switches) {
    check.doors(fake(s));
    check.readonly(fake(s));
    check.ownership(fake(s));
  }
  const after = intrinsics.map((o) => Reflect.ownKeys(o).map(String).sort().join(","));
  for (let i = 0; i < intrinsics.length; i += 1) H.assertEqual(after[i], before[i], `intrinsic ${i}`);
});

H.test("rejectionProblems: a well-formed rejection passes", () => {
  const r = new R({ code: "SISO_CYCLE", message: "m", eventId: 3, causeId: 2, eventType: "a", depth: 3 });
  const p = rejectionProblems(r, { code: "SISO_CYCLE", depth: 3 });
  H.assert(p.length === 0, joined(p));
  const t = new R({ code: "SISO_GATE_THREW", message: "m", eventId: 1, eventType: "a", cause: "a string" });
  const q = rejectionProblems(t, { code: "SISO_GATE_THREW", cause: "a string", entry: true });
  H.assert(q.length === 0, joined(q));
  const n = new R({ code: "SISO_NOT_EVENT", message: "m" });
  const s = rejectionProblems(n, { noDelivery: true });
  H.assert(s.length === 0, joined(s));
});

H.test("rejectionProblems: shape errors are named", () => {
  const plain = { code: "SISO_CYCLE", message: "m", eventId: 1, depth: 1 };
  H.assert(has(rejectionProblems(plain), "String(rejection)"), "a rejection whose String is not its message");
  H.assert(has(rejectionProblems(plain), "not an Error"), "a plain object");
  H.assert(has(rejectionProblems(new R({ code: "X", message: "m" })), "unknown code"), "unknown code");
  H.assert(has(rejectionProblems(new R({ code: "SISO_SELF_LOOP", message: "m", extra: 1 })), "unexpected field"), "extra field");
  H.assert(has(rejectionProblems(new R({ code: "SISO_SELF_LOOP", message: "m", depth: 2 })), "only by SISO_CYCLE"), "depth off a cycle");
  H.assert(has(rejectionProblems(new R({ code: "SISO_NOT_EVENT", message: "m", eventId: 1 }), { noDelivery: true }), "names no delivery"), "refused entry with an id");
  H.assert(has(rejectionProblems(new R({ code: "SISO_SELF_LOOP", message: "m", eventId: 1, causeId: 1 }), { entry: true }), "has no cause"), "entry with a cause");
  H.assert(has(rejectionProblems(new R({ code: "SISO_SELF_LOOP", message: "m", eventId: 1, causeId: undefined })), "present but undefined"), "a present-but-undefined field");
  H.assert(has(rejectionProblems(new R({ code: "SISO_CYCLE", message: "m", eventId: 1.5, depth: "3" })), "integer"), "non-integer ids");
  const hidden = new R({ code: "SISO_SELF_LOOP" });
  Object.defineProperty(hidden, "message", { value: "m", enumerable: false });
  H.assert(has(rejectionProblems(hidden), "message is not an own enumerable field"), "a hidden message");
  const unnamed = new Error("m");
  unnamed.code = "SISO_SELF_LOOP";
  H.assert(has(rejectionProblems(unnamed), "name is"), "an Error not named Rejection");
  H.assert(has(rejectionProblems("boom"), "is an object"), "a string");
  H.assert(has(rejectionProblems(new R({ code: "SISO_CYCLE", message: "m", eventId: 2, depth: 2 }), { eventId: 3 }), "eventId: expected 3, got 2"), "a pinned id");
});

H.finish("contract core-api");
