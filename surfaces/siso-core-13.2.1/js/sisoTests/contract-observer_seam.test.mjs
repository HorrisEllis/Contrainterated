// contract test: observer-seam (js/code/contracts/observer_seam.mjs).
// Port of lua/sisoTests/contract-observer_seam.test.lua. Shows the
// contract's checks pass on an implementation that honours it and catch
// 11.1's observer (called during the propagation) and the other
// departures the contract rules out.
import * as H from "../code/harness.mjs";
import { check, batchProblems, rejectionCopyProblem } from "../code/contracts/observer_seam.mjs";
import { fake } from "./contract-fake-core.mjs";

const joined = (list) => list.join("; ");
const has = (list, needle) => list.some((s) => s.includes(needle));
const includes = (s, needle) => typeof s === "string" && s.includes(needle);

H.test("the seam holds on an implementation that honours it", () => {
  const p = check.seam(fake());
  H.assert(p.length === 0, joined(p));
});

H.test("ownership holds on an implementation that honours it", () => {
  const p = check.ownership(fake());
  H.assert(p.length === 0, joined(p));
});

H.test("11.1: an observer called during the propagation is caught", () => {
  const p = check.seam(fake({ observeDuring: true }));
  H.assert(has(p, "crossings reached the propagation"), joined(p));
  H.assert(has(p, "batches"), joined(p));
});

H.test("11.1: an observer's throw escaping emit is caught", () => {
  const p = check.seam(fake({ unprotected: true }));
  H.assert(has(p, "raising observer"), joined(p));
});

H.test("one batch shared by every observer is caught", () => {
  const p = check.seam(fake({ sharedBatch: true }));
  H.assert(has(p, "reached another observer"), joined(p));
});

H.test("11.1 R1: ids counted across propagations break the batch", () => {
  const p = check.seam(fake({ countIds: true }));
  H.assert(has(p, "eventId is"), joined(p));
});

H.test("12.0 X6: records that share the residue's frozen data are caught", () => {
  const p = check.ownership(fake({ shareFrozen: true }));
  H.assert(has(p, "reached the residue"), joined(p));
  H.assert(has(p, "another observer"), joined(p));
});

H.test("11.1 R3: writable record data is caught", () => {
  const p = check.ownership(fake({ noFreeze: true }));
  H.assert(has(p, "record data accepts assignment"), joined(p));
});

H.test("12.0 C-2: observers shown the very rejection raised are caught", () => {
  const f = fake({ sharedRejection: true });
  let p = check.seam(f);
  H.assert(has(p, "very object raised"), joined(p));
  p = check.ownership(f);
  H.assert(has(p, "reached the rejection emit raised"), joined(p));
});

H.test("13.0.0-rc1: a rejection copy that keeps the host's prototype is caught", () => {
  const f = fake({ richRejection: true });
  let p = check.seam(f);
  H.assert(has(p, "prototype"), joined(p));
  p = check.ownership(f);
  H.assert(has(p, "through its copy's prototype"), joined(p));
});

H.test("13.1.0: a cause copied once per observer (gate code counting observers) is caught", () => {
  const p = check.ownership(fake({ copyPerObserver: true }));
  H.assert(has(p, "different number of times with 0, 1 and 3 observers"), joined(p));
  H.assert(check.seam(fake({ copyPerObserver: true })).length === 0, "the seam check does not target it");
});

H.test("rejectionCopyProblem: a copy, the same object, a changed field", () => {
  const raised = new Error("m");
  Object.assign(raised, { code: "SISO_GATE_THREW", eventId: 1, cause: { first: "x", n: [1] } });
  Object.defineProperty(raised, "message", { value: "m", enumerable: true });
  const ro = (t) => Object.freeze(t);
  const good = ro({ message: "m", code: "SISO_GATE_THREW", eventId: 1, cause: ro({ first: "x", n: ro([1]) }) });
  H.assertEqual(rejectionCopyProblem(good, raised), null);
  H.assert(includes(rejectionCopyProblem(raised, raised), "very object"), "the same object");
  const off = ro({ message: "m", code: "SISO_GATE_THREW", eventId: 2, cause: ro({ first: "x", n: ro([1]) }) });
  H.assert(includes(rejectionCopyProblem(off, raised), "eventId"), "an unequal field");
  const deep = ro({ message: "m", code: "SISO_GATE_THREW", eventId: 1, cause: ro({ first: "x", n: ro([2]) }) });
  H.assert(includes(rejectionCopyProblem(deep, raised), "cause"), "an unequal cause");
  const extra = ro({ message: "m", code: "SISO_GATE_THREW", eventId: 1, cause: ro({ first: "x", n: ro([1]) }), depth: 3 });
  H.assert(includes(rejectionCopyProblem(extra, raised), "lacks"), "an added field");
  const missing = ro({ message: "m", code: "SISO_GATE_THREW", cause: ro({ first: "x", n: ro([1]) }) });
  H.assert(includes(rejectionCopyProblem(missing, raised), "eventId"), "a missing field");
  const writable = { message: "m", code: "SISO_GATE_THREW", eventId: 1, cause: { first: "x", n: [1] } };
  H.assert(rejectionCopyProblem(writable, raised) !== null, "a writable copy");
  const writableTop = { message: "m", code: "SISO_GATE_THREW", eventId: 1, cause: ro({ first: "x", n: ro([1]) }) };
  H.assert(includes(rejectionCopyProblem(writableTop, raised), "accepts assignment"), "a writable top level");
  const withProto = ro(Object.assign(Object.create(Object.getPrototypeOf(raised)), good));
  H.assert(includes(rejectionCopyProblem(withProto, raised), "prototype"), "a copy that hands out the host's prototype");
  const sharedCause = ro({ message: "m", code: "SISO_GATE_THREW", eventId: 1, cause: raised.cause });
  H.assert(includes(rejectionCopyProblem(sharedCause, raised), "raised object itself"), "a copy sharing the raised cause");
  const getter = ro(Object.defineProperty({ message: "m", code: "SISO_GATE_THREW", eventId: 1 }, "cause", {
    get: () => raised.cause, enumerable: true,
  }));
  H.assert(includes(rejectionCopyProblem(getter, raised), "accessor"), "a copy that reads through to the raised cause");
  // an Error cause: its own message and stack come along, enumerable or not
  const err = new Error("boom");
  const raisedE = new Error("e");
  Object.assign(raisedE, { code: "SISO_GATE_THREW", cause: err });
  const copyE = ro({ code: "SISO_GATE_THREW", cause: ro({ message: "boom", stack: err.stack }) });
  H.assertEqual(rejectionCopyProblem(copyE, raisedE), null, "an Error cause copied as its own fields");
  const noStack = ro({ code: "SISO_GATE_THREW", cause: ro({ message: "boom" }) });
  H.assert(includes(rejectionCopyProblem(noStack, raisedE), "cause"), "an Error cause that lost its stack");
});

H.test("batchProblems: a consistent batch passes", () => {
  const b = { outcome: "settled", records: [
    { eventId: 1, eventType: "a", claimed: true, data: {} },
    { eventId: 2, causeId: 1, eventType: "b", claimed: false, data: {} },
  ] };
  const p = batchProblems(b);
  H.assertEqual(p.length, 0, joined(p));
  const r = { outcome: "rejected", rejection: { code: "SISO_CYCLE", eventId: 2 }, records: b.records };
  const q = batchProblems(r);
  H.assertEqual(q.length, 0, joined(q));
});

H.test("batchProblems: inconsistencies are named", () => {
  const rec = (id, cause, claimed) => {
    const r = { eventId: id, eventType: "t", claimed, data: {} };
    if (cause !== undefined) r.causeId = cause;
    return r;
  };
  H.assert(has(batchProblems({ outcome: "settled", records: [rec(1, undefined, true), rec(3, 1, false)] }), "eventId is 3"), "gap in ids");
  H.assert(has(batchProblems({ outcome: "settled", records: [rec(1, undefined, false), rec(2, 1, false)] }), "not claimed"), "cause unclaimed");
  H.assert(has(batchProblems({ outcome: "settled", records: [rec(1, 1, true)] }), "has a cause"), "entry with cause");
  H.assert(has(batchProblems({ outcome: "settled", records: [{ ...rec(1, undefined, true), causeId: undefined }] }), "has a cause"), "entry with a causeId key");
  H.assert(has(batchProblems({ outcome: "rejected", records: [] }), "carries no rejection"), "rejected without rejection");
  H.assert(has(batchProblems({ outcome: "settled", rejection: undefined, records: [] }), "settled batch carries a rejection"), "settled with a rejection key");
  H.assert(has(batchProblems({ outcome: "rejected", rejection: { eventId: 2 }, records: [rec(1, undefined, true)] }), "do not end"), "records past the rejection");
  H.assert(has(batchProblems({ outcome: "rejected", rejection: { code: "SISO_NOT_EVENT" }, records: [rec(1, undefined, true)] }), "refused entry has records"), "refused entry with records");
  H.assert(has(batchProblems({ outcome: "settled", records: [], extra: 1 }), "unexpected field"), "extra field");
  // eslint-disable-next-line no-sparse-arrays
  H.assert(has(batchProblems({ outcome: "settled", records: [rec(1, undefined, true), , rec(3, 1, false)] }), "holes or named keys"), "a hole");
  H.assert(has(batchProblems({ outcome: "settled", records: [{ ...rec(1, undefined, true), data: "d" }] }), "data is not an object"), "data not an object");
  H.assert(has(batchProblems({ outcome: "done", records: [] }), "outcome is done"), "unknown outcome");
  H.assert(has(batchProblems(null), "a batch is an object"), "not an object");
});

H.finish("contract observer-seam");
