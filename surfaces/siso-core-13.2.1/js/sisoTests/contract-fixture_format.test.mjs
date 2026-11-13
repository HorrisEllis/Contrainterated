// contract test: fixture-format (js/code/contracts/fixture_format.mjs).
// Port of lua/sisoTests/contract-fixture_format.test.lua. The shape
// check accepts well-formed fixtures and names malformed ones; the
// matching rules are order-free (K1). The shared files themselves are
// the fixtures boundary's: js/sisoTests/fixtures.test.mjs checks each
// one's shape, as the Lua fixtures.test.lua does.
import * as H from "../code/harness.mjs";
import * as F from "../code/contracts/fixture_format.mjs";

const joined = (list) => list.join("; ");
const has = (list, needle) => list.some((s) => s.includes(needle));

// a decoded fixture, as a JSON decoder would give it
const cycle = {
  format: 1,
  name: "an indirect cycle is rejected at the first claimed delivery deeper than the gate count",
  covers: ["Rule 3", "K5"],
  gates: [
    { signature: "a", does: { emitOne: { type: "b" } } },
    { signature: "b", does: { emit: [{ type: "a", data: { n: 1 } }, { raw: 42 }] } },
  ],
  steps: [
    { emit: { type: "a" }, expect: { rejected: { code: "SISO_CYCLE", eventType: "a", depth: 3, at: ["a", "b", "a"] },
      trace: [{ type: "a", claimed: true, then: [{ type: "b", claimed: true, then: [{ type: "a", claimed: true }] }] }] } },
    { sample: { residue: [], gateCount: 2 } },
    { register: { signature: "a", does: { nothing: true } }, refused: true },
    { emitRaw: "x", expect: { rejected: { code: "SISO_NOT_EVENT" } } },
    { emitRaw: null, expect: { rejected: { code: "SISO_NOT_EVENT" } } },
    { take: [] },
  ],
};

const base = (steps, gates = []) => ({ format: 1, name: "x", covers: ["K1"], gates, steps });

H.test("a well-formed fixture passes", () => {
  const p = F.problems(cycle);
  H.assert(p.length === 0, joined(p));
});

H.test("malformed fixtures are named", () => {
  H.assert(has(F.problems({ ...base([{ take: [] }]), format: 2 }), "format"), "format");
  H.assert(has(F.problems({ ...base([{ take: [] }]), covers: [] }), "covers"), "covers");
  H.assert(has(F.problems(base([{ take: [] }], [{ signature: "a", does: { emit: [], throw: "x" } }])), "exactly one"), "two behaviours");
  H.assert(has(F.problems(base([{ emit: { type: "a", data: { f: 1.5 } }, expect: "settled" }])), "float"), "float data");
  H.assert(has(F.problems(base([{ emit: { type: "a" }, expect: { rejected: { code: "NOPE" } } }])), "known code"), "unknown code");
  H.assert(has(F.problems(base([{ emit: { type: "a" }, expect: { settled: true, rejected: { code: "SISO_CYCLE" } } }])), "exactly one of"), "both outcomes");
  H.assert(has(F.problems(base([{ wait: 1 }])), "a step is one of"), "unknown step");
  H.assert(has(F.problems(base([])), "non-empty"), "no steps");
  H.assert(has(F.problems(base([{ emit: { type: "a", data: { l: [] } }, expect: "settled" }])), "empty array"), "an empty array in data");
  H.assert(has(F.problems(base([{ emit: { type: "a", data: null }, expect: "settled" }])), "data is not an object"), "null data");
  H.assert(has(F.problems(base([{ emit: { type: "a", extra: 1 }, expect: "settled" }])), "unexpected field"), "an extra event field");
  H.assert(has(F.problems(base([{ take: [] }], [{ signature: "a", does: { raw: null } }])), "raw null"), "a raw null behaviour");
  H.assert(has(F.problems(base([{ register: { signature: "a", does: { nothing: true } } }])), "refused"), "register without refused");
  H.assert(has(F.problems(base([{ sample: { residue: [] } }])), "sample is"), "sample without gateCount");
  H.assert(has(F.problems(base([{ emit: { type: "a" }, expect: { settled: true, trace: [{ type: "a" }] } }])), "a node is"), "a node without claimed");
  H.assert(has(F.problems("x"), "a fixture is an object"), "not an object");
});

H.test("residue matches as a multiset", () => {
  const a = [{ type: "x", data: { v: 1 } }, { type: "y" }, { type: "x", data: { v: 1 } }];
  const b = [{ type: "y", data: {} }, { type: "x", data: { v: 1 } }, { type: "x", data: { v: 1 } }];
  H.assert(F.residueMatch(a, b)[0], "same multiset in another order");
  H.assert(!F.residueMatch(a, [b[0], b[1]])[0], "a missing copy");
  H.assert(typeof F.residueMatch(a, [b[0], b[1]])[1] === "string", "a mismatch says why");
  const frozen = [Object.freeze({ type: "x", data: Object.freeze({ v: 1, l: Object.freeze([1, 2]) }) })];
  H.assert(F.residueMatch([{ type: "x", data: { l: [1, 2], v: 1 } }], frozen)[0], "frozen events, keys in another order");
  H.assert(!F.residueMatch([{ type: "x", data: { l: { 0: 1, 1: 2 } } }], [{ type: "x", data: { l: [1, 2] } }])[0], "an array is not an object");
});

H.test("canon renders plain data canonically and refuses what is not", () => {
  H.assertEqual(F.canon({ b: [1, "x"], a: null, c: true }), '{"a":null,"b":[1,"x"],"c":true}');
  H.assertThrows(() => F.canon(1.5), "float");
  H.assertThrows(() => F.canon(() => 1), "not plain data");
  const c = {};
  c.self = c;
  H.assertThrows(() => F.canon(c), "cycle");
});

H.test("a trace matches whatever the delivery order", () => {
  const expected = [{ type: "root", claimed: true, then: [
    { type: "a", claimed: true, then: [{ type: "a1", claimed: false }] },
    { type: "b", claimed: false }] }];
  const bfs = [
    { eventId: 1, eventType: "root", claimed: true },
    { eventId: 2, causeId: 1, eventType: "a", claimed: true },
    { eventId: 3, causeId: 1, eventType: "b", claimed: false },
    { eventId: 4, causeId: 2, eventType: "a1", claimed: false },
  ];
  const dfs = [
    { eventId: 1, eventType: "root", claimed: true },
    { eventId: 2, causeId: 1, eventType: "a", claimed: true },
    { eventId: 3, causeId: 2, eventType: "a1", claimed: false },
    { eventId: 4, causeId: 1, eventType: "b", claimed: false },
  ];
  H.assert(F.traceMatch(expected, bfs)[0], "breadth-first");
  H.assert(F.traceMatch(expected, dfs)[0], "depth-first");
  const wrong = [bfs[0], bfs[1], bfs[2], { eventId: 4, causeId: 3, eventType: "a1", claimed: false }];
  H.assert(!F.traceMatch(expected, wrong)[0], "a1 caused by the wrong delivery");
  H.assertEqual(F.forest(bfs).length, 1, "one root");
  H.assertThrows(() => F.forest([{ eventId: 1, causeId: 7, eventType: "x", claimed: false }]), "names no earlier delivery");
});

H.test("recordAt finds a delivery by its path of types, in any order", () => {
  const recs = [
    { eventId: 1, eventType: "a", claimed: true },
    { eventId: 2, causeId: 1, eventType: "c", claimed: false },
    { eventId: 3, causeId: 1, eventType: "b", claimed: true },
    { eventId: 4, causeId: 3, eventType: "a", claimed: true },
  ];
  const [r, why] = F.recordAt(recs, ["a", "b", "a"]);
  H.assert(r && r.eventId === 4 && why === null, "the a caused by b");
  const [none, whyNone] = F.recordAt(recs, ["a", "x"]);
  H.assert(none === null && typeof whyNone === "string", "no such path");
  const twice = [...recs, { eventId: 5, causeId: 1, eventType: "c", claimed: false }];
  H.assert(F.recordAt(twice, ["a", "c"])[0] === null, "an ambiguous path");
});

H.finish("contract fixture-format");
