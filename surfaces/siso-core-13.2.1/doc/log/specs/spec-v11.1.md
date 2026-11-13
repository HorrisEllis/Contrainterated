# Spec — core v11.1

## Identity

- **Name:** core
- **Label:** SISO Canon — reference implementation
- **Version:** 11.1 (`code/VERSION`)
- **Type:** library / substrate (not a component; no manifest,
  no API actions, no persistence)
- **Language:** JavaScript (ES modules), zero dependencies
- **Spec compliance:** SISO-principles 2.0 DRAFT (core) — see
  `doc/conformance.md` (13 of 15 items verified, 2 not verified;
  no item fails). This implementation's own concessions and
  departures are in *Concessions of this implementation* below.
- **Status:** active
- **Last modified:** 2026-10-02

## Purpose

The minimal reference implementation of the three SISO
primitives — Event, Gate, Stream — plus the observer-side
StreamLog. It exists to demonstrate the rules in
`doc/SISO-principles.md` in executable form: pure gates with
return-based propagation, a stream that holds the present and
enforces acyclicity, residue as the only way out, sampling as
observation, and a log that is downstream of processing, never
inside it.

## Dependencies

- **Platform:** none. Runs on any ES-module-capable runtime
  (developed against Node 22).
- **External:** none. Tests use the local `tests/runner.js`.

## Structure

```
core/
├── doc/
│   ├── SISO-principles.md        the core principles (2.0 DRAFT)
│   ├── SISO-Canon-dev-notes.md   open proposals / canon notes
│   ├── conformance.md            audit vs. principles 2.0 DRAFT
│   ├── specs/
│   │   ├── spec-v9.0.md
│   │   ├── spec-v10.0.md
│   │   ├── spec-v11.0.md
│   │   └── spec-v11.1.md         this file
│   └── changelogs/
│       ├── changelog-v9.0.md
│       ├── changelog-v10.0.md
│       ├── changelog-v11.0.md
│       └── changelog-v11.1.md
├── code/
│   ├── VERSION                   "11.1"
│   └── src/
│       ├── Event.js              Event primitive — {type, data}
│       ├── Gate.js               Gate base class
│       ├── Stream.js             Stream + queue delivery + acyclicity guard + residue
│       ├── StreamLog.js          observer-side log
│       └── index.js              re-exports all four
└── tests/
    ├── runner.js                 minimal assert/report harness
    ├── core.test.js              Event/Gate/Stream (41 tests)
    └── streamlog.test.js         StreamLog + causality (26 tests)
```

Run tests: `node tests/core.test.js` and
`node tests/streamlog.test.js` from the project root. Exit code
0 on pass; final line `{suite}: {passed}/{total} passed`.

## Contracts

### Event

`new Event(type, data = {})` → `{type, data}`. Nothing else;
extra constructor arguments are ignored. Immutable by convention
(not frozen). Never modified or annotated by the substrate —
verified by test.

### Gate

`new Gate(signature)`; subclass and override
`transform(event)`. Contract:

- Receives exactly one argument, the event.
- Returns `Event` (one follow-up), `Event[]` (many), or
  `null`/`undefined` (consumed).
- Must be pure: no state mutation between calls, no side
  effects beyond the return value, no access to anything
  beyond the event. Constructor-fixed configuration (like
  `signature`) is permitted.
- Must not throw. A failure the gate recognises is returned as
  an event of an error type (Rule 1). A throw rejects the whole
  propagation.
- Must never return its own type, and its outputs must never
  lead back to a type already on the current causal chain
  (acyclicity, Rule 3). The stream enforces both.
- Wanting another attempt is not a core concern. A gate that
  needs one returns an event of a different type; it settles as
  residue, and the boundary layer decides whether to emit again.

### Stream

- `new Stream({ log = null, parentStreamId = null } = {})`.
  If a log is given, the stream takes `streamId` from
  `log.nextStreamId()`.
- `register(gate)` — signature collision throws
  `Signature collision: '<sig>'`.
- `emit(event)` — synchronous; returns when the event's entire
  causal tree has settled. Delivery is an iterative FIFO queue
  (breadth-first, causal generations). Each queue entry carries
  the event, its `causeId` and its `depth` (1 for an external
  emit, parent + 1 otherwise). Chain length is bounded by
  acyclicity, not by the call stack — 20k-gate chains are tested.
  - Each delivery increments `eventCount` and is assigned
    `eventId = eventCount`, with `causeId` = the id of the
    producing event (`null` for external emits).
  - **Self-loop:** if a gate returns an event of its own type,
    `emit` throws an `Error` with `code: 'SISO_SELF_LOOP'`,
    `eventId` (the producing delivery) and `eventType`.
  - **Cycle:** if a gate would claim an event whose depth exceeds
    the registered gate count, `emit` throws an `Error` with
    `code: 'SISO_CYCLE'`, `eventId`, `causeId`, `eventType` and
    `depth`. A legal chain cannot exceed that depth with a
    claimed event, so this catches every cycle, at the latest
    after one lap. The deepest legal event is an unclaimed one at
    depth gates + 1.
  - **Gate threw:** if a gate's `transform` throws anything,
    `emit` throws an `Error` with `code: 'SISO_GATE_THREW'`,
    `eventId` (the delivery that threw), `causeId`, `eventType`
    (the event the gate was given) and `cause` (exactly what the
    gate threw, Error or not). The message includes the gate's
    signature and the original message.
  - On any of these throws the propagation commits **no residue**.
    `eventCount` keeps its advance (ids are never reused). The
    stream remains usable.
  - Unclaimed events go to `residue` once the propagation
    settles. Non-Event values in a returned array are silently
    skipped (known issue).
- `sampleHere()` → `{ residue: [...copy], eventCount,
  gateCount }`. Non-destructive as an act. The copy is shallow:
  its events are the residue's own objects (R3).
- `takeResidue()` → the residue array; the stream's residue is
  emptied. The only way an answer leaves.
- **Doors to the outside:** `emit` (entry, and the rejection
  exit), `takeResidue` (take), `sampleHere` (sample), a log given
  at construction (observe), and `register` (building). Nothing
  else crosses during propagation.
- **A gate that throws:** the propagation is rejected through
  the same path as a self-loop or a cycle (`SISO_GATE_THREW`).
  Nothing is committed.
- **Ordering guarantee: none.** Per Rule 3, processing order
  is not part of the semantics. The breadth-first order is an
  implementation choice.

### StreamLog

- `new StreamLog(level = 'EVENTS')`; levels OFF < EVENTS <
  DEEP < DATA, mutable at runtime, unknown level strings
  behave as OFF (known sharp edge).
- `record({...})` — called by Stream; entry fields by level:
  - EVENTS: `seq`, `time`, `type`, `claimed` (signature, or
    `null` for residue), `eventId`, `causeId`
  - DEEP: + `streamId`, `parentStreamId` (omitted when null)
  - DATA: + `data` (the event payload, by reference)
- `sample()` → `{ level, count, entries: [...copy] }`.
- `clear()` resets entries and seq.
- `nextStreamId()` — monotonically increasing, **module-global
  counter** shared across all StreamLog instances (tracked
  canon question).
- Causal reconstruction: entries form a forest under
  `causeId` → `eventId` links; roots have `causeId: null`.
  Entry order means time; causeId means cause. `eventId` is
  per-stream. On `SISO_CYCLE`, walking `causeId` from the
  error's `eventId` names the loop (tested).

## Gates

None shipped. `Gate` is an abstract base; all concrete gates
live in downstream projects. Test files define local gates
(sinks, forwarders, splitters, chain steps, cycle fixtures)
as fixtures only.

## Tests

- **core.test.js — 41/41.** Event construction and shape
  (`{type, data}` only); Gate base; registration and collision;
  emit/residue; single/array/null/undefined returns; chain
  causal order; fan-out; breadth-first generation order;
  20,000-gate legal chain; event non-annotation; self-loop
  throws (direct and inside fan-out); indirect cycles of length
  2 and 3 throw; cycle depth equals gates + 1; same type in
  sibling branches is legal; longest legal chain ends unclaimed;
  throwing propagation commits no residue; stream usable after a
  throw; a gate that throws rejects with SISO_GATE_THREW, ids
  and cause; a gate that throws commits no residue and leaves the
  stream usable; a non-Error throw is wrapped the same way;
  takeResidue returns and empties; residue after a take;
  take on empty; sampling does not take; sampleHere copy
  semantics; eventCount; constructor options.
- **streamlog.test.js — 26/26.** Level gating; seq and
  timestamps; nextStreamId; sample copy; clear; runtime level
  changes; Stream integration; shared log across sub-streams;
  causal ids on chains, fan-out, separate external emits;
  thread reconstruction from a mixed tree; behavioral identity
  with and without a log; no retries field; cycle readable from
  causal chain; self-loop error points at producing entry;
  gate-threw error points at the claiming entry;
  log presence does not change acyclicity outcome.
- **Gaps:** no test for unknown log level strings; no test
  pinning the silent skip of non-Event array members; no
  multi-stream causal-id collision test at EVENTS level.

## Reference names

How the principles' generic names appear in this implementation.

| Principles | core 11.1 |
|---|---|
| Event (type, data) | `new Event(type, data = {})` → `{type, data}` |
| Gate | `Gate` subclass with `signature` and `transform(event)` |
| Entry door | `stream.emit(event)` (synchronous; returns once the propagation has settled) |
| Take door | `stream.takeResidue()` |
| Sample door | `stream.sampleHere()` |
| Observe door | `new Stream({ log })`, with `StreamLog` levels `OFF`/`EVENTS`/`DEEP`/`DATA` |
| Building door | `stream.register(gate)`; a duplicate throws `Signature collision` |
| Rejection exit | an `Error` thrown from `emit` with `code` `'SISO_SELF_LOOP'`, `'SISO_CYCLE'` or `'SISO_GATE_THREW'`, and the delivery's `eventId`, `eventType` and (where there is one) `causeId` |
| Delivery metadata (K4) | queue entries `{event, causeId, depth}` |
| Delivery order (K1) | a first-in-first-out queue, breadth-first, pinned by the test "delivery is breadth-first" |

## Concessions of this implementation

These are the reference core's own entries, on top of the core's K1–K6 (principles, *Concessions of the core*). Each one cites what it bends. Cause is either **Substrate** (JavaScript forces it; with a "vanishes when") or **Instance** (this implementation chose it; to be retired).

| ID | Concession | Bends | Cause | Purpose | Thinnest form |
|---|---|---|---|---|---|
| **R1** | **The event counter.** `eventCount` persists on the stream, advances on every delivery, mints event ids, and appears in `sampleHere()`. It also advances in a rejected propagation. | Drift 8, first sign (a named value that persists and is overwritten); Properties: *Without side effects* | Instance | Ids for causal links (`eventId`, `causeId`). | **Not yet.** Ids are minted with no observer attached, the sample reports a summary of history, and a rejection leaves this one trace in the stream. *Thinner:* mint ids only for an observer, and drop `eventCount` from the sample. |
| **R2** | **The observer's own state, and how it is attached.** `StreamLog` stores entries with wall-clock timestamps (`Date.now()`). It has a runtime-mutable level, `clear()`, and a module-global `streamIdCounter` shared by all logs. Streams may carry `parentStreamId` for sub-stream lineage. An observer is attached only at construction, and is silenced by `level = 'OFF'`. | K3 (the observe door: attach *or detach*) | Instance | Diagnosis across a stream and its sub-streams. | Observer-side state is allowed, and nothing in `Stream` reads it. **Not yet** for the door: there is no detach, and the global counter couples separate log instances. |
| **R3** | **Purity and immutability by convention.** Events are plain mutable objects. A gate is a JavaScript object that can keep fields, import globals, perform I/O, or throw. A sample shares the residue's event objects, so writing to a sampled event changes the present without passing through a door. | Rule 1 (statelessness, no side effects, immutable input); Rule 4 (read-only samples) | Substrate: JavaScript | None. It is recorded so that it is never mistaken for architecture. | *Available now:* freeze events on entry and on return, and freeze or copy the events placed in a sample. *Vanishes when* the gate language cannot express state or effects. |

### Recorded departures

Rules this implementation does not yet meet. Each one is reported in `conformance.md`.

| ID | Departure | Breaks | Fix |
|---|---|---|---|
| **X1** | Non-Event values in a returned array are silently skipped. | Drift 7: the stream polices a gate's output instead of carrying it or rejecting it. | Throw, as for a self-loop. |
| **X2** | An unknown log level string behaves as `OFF`. | Observer-side only. It affects whether anything is recorded, not behaviour. | Throw on construction or on setting the level. |

**Resolved in 11.1:** X3. A gate's throw left `emit` without delivery identities. It is now wrapped as `SISO_GATE_THREW`, with the ids and the original as `cause`.

### Open questions (this implementation)

1. **Freezing (R3).** Freeze events, returns and sample copies? That would make checklist item 3 partly architectural and item 10 verifiable.
2. **Detach (R2).** Give the observe door an explicit detach?
3. **The event counter (R1).** Mint ids only for an observer, and drop `eventCount` from samples?
4. **X1.** Throw on non-Event outputs. This is a one-line change.

### Test gaps

- No test for unknown log level strings (X2).
- No test pinning the silent skip of non-Event array members (X1).
- No test pinning that a rejection advances `eventCount` (R1).
- No multi-stream causal-id collision test at `EVENTS` level.

## Future (noted, not committed)

- `StreamLog.threads()`: a causal-projection view.
- A fixture-based, language-agnostic test harness.
- **Pending events and declared exits** return together with gate growth (self-learning SISO). They retire K6.

## Changelog

- **11.1** (2026-10-02) — a gate's throw is wrapped as
  `SISO_GATE_THREW` with delivery ids and the original as
  `cause` (X3 resolved); +4 tests. See
  `changelogs/changelog-v11.1.md`.
- **11.0 docs revision** (2026-10-02) — principles 2.0 DRAFT
  (core only; the core's ledger K1–K6); this spec gains the
  implementation ledger (R1–R3, X1–X3) and reference names;
  conformance re-audited. No code change.
- **11.0** (2026-10-02) — retry removed from the core (boundary
  concern); self-loops throw; indirect cycles caught by depth
  guard; `pending` → `residue` with `takeResidue()`; principles
  1.2 → 1.3. See `changelogs/changelog-v11.0.md`.
- **10.0** (2026-08-28) — bounded retry via `retries` field
  on Event; stream-enforced same-type re-emission; principles
  1.1 → 1.2; +12 tests. See `changelogs/changelog-v10.0.md`.
- **9.0** (2026-08-03) — queue delivery (BFS, unbounded
  chains), causal tagging, acyclicity canonized, canonical
  file structure, +8 tests.
- **1.0** — recursive depth-first delivery; Event/Gate/Stream/
  StreamLog; 38 tests; flat layout.
