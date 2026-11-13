# Spec — core v9.0

## Identity

- **Name:** core
- **Label:** SISO Canon — reference implementation
- **Version:** 9.0 (`code/VERSION`)
- **Type:** library / substrate (not a component; no manifest,
  no API actions, no persistence)
- **Language:** JavaScript (ES modules), zero dependencies
- **Spec compliance:** SISO-principles v1.1 — see
  `doc/conformance.md` (compliant, one pragmatic departure)
- **Status:** active
- **Last modified:** 2026-08-03

## Purpose

The minimal reference implementation of the three SISO
primitives — Event, Gate, Stream — plus the observer-side
StreamLog. It exists to demonstrate the rules in
`doc/SISO-principles.md` in executable form: pure gates with
return-based propagation, a stream that holds the present,
sampling as observation, and a log that is downstream of
processing, never inside it.

## Dependencies

- **Platform:** none. Runs on any ES-module-capable runtime
  (developed against Node 22).
- **External:** none. Tests use the local `tests/runner.js`.

## Structure

```
core/
├── doc/
│   ├── SISO-principles.md        the specification (v1.1)
│   ├── SISO-Canon-dev-notes.md   open proposals / canon notes
│   ├── conformance.md            audit vs. principles v1.1
│   ├── specs/spec-v9.0.md        this file
│   └── changelogs/changelog-v9.0.md
├── code/
│   ├── VERSION                   "9.0"
│   └── src/
│       ├── Event.js              Event primitive
│       ├── Gate.js               Gate base class
│       ├── Stream.js             Stream + queue delivery
│       ├── StreamLog.js          observer-side log
│       └── index.js              re-exports all four
└── tests/
    ├── runner.js                 minimal assert/report harness
    ├── core.test.js              Event/Gate/Stream (25 tests)
    └── streamlog.test.js         StreamLog + causality (21 tests)
```

Run tests: `node tests/core.test.js` and
`node tests/streamlog.test.js` from the project root. Exit code
0 on pass; final line `{suite}: {passed}/{total} passed`.

## Contracts

### Event

`new Event(type, data = {})` → `{type, data}`. Immutable by
convention (not frozen). Never modified or annotated by the
substrate — verified by test.

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
- Output types must never lead back to any type on the current
  causal chain (acyclicity, Rule 3 / v1.1). Not enforced at
  runtime; a violating gate makes `emit()` non-terminating.

### Stream

- `new Stream({ log = null, parentStreamId = null } = {})`.
  If a log is given, the stream takes `streamId` from
  `log.nextStreamId()`.
- `register(gate)` — signature collision throws
  `Signature collision: '<sig>'`.
- `emit(event)` — synchronous; returns when the event's entire
  causal tree has settled. Delivery is an iterative FIFO queue
  (breadth-first, causal generations). Chain length is bounded
  by the acyclicity rule, not by the call stack — 20k-gate
  chains are tested. Each delivery increments `eventCount` and
  is assigned `eventId = eventCount`, with `causeId` = the id
  of the producing event (`null` for external emits). Unclaimed
  events go to `pending`. Non-Event values in a returned array
  are silently skipped (known issue; see below).
- `sampleHere()` → `{ pending: [...copy], eventCount,
  gateCount }`. Non-destructive.
- **Ordering guarantee: none.** Per Rule 3, processing order is
  not part of the semantics. The breadth-first order is an
  implementation choice; code must not depend on log entry
  order or pending order beyond what causality implies.

### StreamLog

- `new StreamLog(level = 'EVENTS')`; levels OFF < EVENTS <
  DEEP < DATA, mutable at runtime, unknown level strings
  behave as OFF (known sharp edge).
- `record({...})` — called by Stream; entry fields by level:
  - EVENTS: `seq`, `time`, `type`, `claimed`, `eventId`,
    `causeId`
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
  per-stream — with shared logs across sub-streams, use
  `streamId` (DEEP) to disambiguate.

## Gates

None shipped. `Gate` is an abstract base; all concrete gates
live in downstream projects. Test files define local gates
(sinks, forwarders, splitters, chain steps) as fixtures only.

## Tests

- **core.test.js — 25/25.** Event construction; Gate base;
  registration and collision; emit/pending; single/array/null/
  undefined returns; chain causal order; fan-out; breadth-first
  generation order (pinned as implementation choice); 20,000-gate
  legal chain (capacity — the v1.0 recursive delivery overflows
  on this input); event non-annotation; sampleHere copy
  semantics; eventCount; constructor options.
- **streamlog.test.js — 21/21.** Level gating (OFF/EVENTS/DEEP/
  DATA); seq and timestamps; nextStreamId; sample copy; clear;
  runtime level changes; Stream integration; shared log across
  sub-streams; causal ids on chains, fan-out, and separate
  external emits; thread reconstruction from a mixed tree;
  behavioral identity with and without a log.
- **Gaps:** no test for unknown log level strings; no test
  pinning the silent skip of non-Event array members (behavior
  is deliberate-but-questioned, see known issues); no
  multi-stream causal-id collision test at EVENTS level.

## Known issues

- **Minor / by decision:** acyclicity is a documented invariant
  with no runtime detection. A cyclic gate makes `emit()`
  non-terminating (unbounded queue growth). Decision recorded
  v9.0: invariant only.
- **Minor, pre-existing:** non-Event values inside a returned
  array are silently dropped — no error, no pending, no log
  entry — while `emit()` accepts any `{type, data}`-shaped
  object. Inconsistent with the hard-error stance on signature
  collisions. Candidate for a throw in a future phase.
- **Minor, pre-existing:** `streamIdCounter` is module-global;
  independent StreamLogs share id space and tests are
  order-coupled to process state. Flagged in dev-notes §1 as an
  open canon question; deliberately untouched.
- **Minor, pre-existing:** unknown log level strings (e.g.
  `'events'`) silently behave as OFF.
- **Resolved v9.0:** legal long chains overflowed the engine
  call stack under recursive delivery.

## Future (noted, not committed)

- `StreamLog.threads()` — causal-projection view beside
  `sample()`, now trivial given `causeId`.
- Frozen read-only sampling views (resolves the O(1)-vs-copy
  pragmatic departure in conformance).
- Fixture-based language-agnostic test harness (dev-notes §2).

## Changelog

- **9.0** (2026-08-03) — queue delivery (BFS, unbounded chains),
  causal tagging (`eventId`/`causeId` at EVENTS), acyclicity
  canonized (principles 1.0 → 1.1), canonical file structure,
  +8 tests. See `changelogs/changelog-v9.0.md`.
- **1.0** — recursive depth-first delivery; Event/Gate/Stream/
  StreamLog; 38 tests; flat layout. (Pre-phased-spec; no
  changelog file exists for it.)
