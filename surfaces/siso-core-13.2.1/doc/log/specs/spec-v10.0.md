# Spec — core v10.0

## Identity

- **Name:** core
- **Label:** SISO Canon — reference implementation
- **Version:** 10.0 (`code/VERSION`)
- **Type:** library / substrate (not a component; no manifest,
  no API actions, no persistence)
- **Language:** JavaScript (ES modules), zero dependencies
- **Spec compliance:** SISO-principles v1.2 — see
  `doc/conformance.md` (compliant, one pragmatic departure)
- **Status:** active
- **Last modified:** 2026-08-28

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
│   ├── SISO-principles.md        the specification (v1.2)
│   ├── SISO-Canon-dev-notes.md   open proposals / canon notes
│   ├── conformance.md            audit vs. principles v1.2
│   ├── specs/
│   │   ├── spec-v9.0.md
│   │   └── spec-v10.0.md         this file
│   └── changelogs/
│       ├── changelog-v9.0.md
│       └── changelog-v10.0.md
├── code/
│   ├── VERSION                   "10.0"
│   └── src/
│       ├── Event.js              Event primitive (+ retries)
│       ├── Gate.js               Gate base class
│       ├── Stream.js             Stream + queue delivery + retry enforcement
│       ├── StreamLog.js          observer-side log (+ retries in entries)
│       └── index.js              re-exports all four
└── tests/
    ├── runner.js                 minimal assert/report harness
    ├── core.test.js              Event/Gate/Stream (33 tests)
    └── streamlog.test.js         StreamLog + causality (25 tests)
```

Run tests: `node tests/core.test.js` and
`node tests/streamlog.test.js` from the project root. Exit code
0 on pass; final line `{suite}: {passed}/{total} passed`.

## Contracts

### Event

`new Event(type, data = {}, retries)` → `{type, data}` or
`{type, data, retries}`. Immutable by convention (not frozen).
Never modified or annotated by the substrate — verified by
test.

The optional `retries` parameter is `{ attempt, max }` where
both are positive integers. When omitted, the event has no
retries field (backward-compatible). When provided,
`this.retries` is set on the event. Retries is gate-authored
metadata: the gate constructs it when it wants bounded same-
type re-emission. The stream reads it for enforcement but does
not write it.

The default (no retries) is 1:1 — one pass through a matching
gate, no same-type re-emission allowed.

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
  causal chain (acyclicity, Rule 3 / v1.2), **except** same-
  type re-emission with an explicit retry bound. A gate may
  return an event of its own type if the event carries
  `retries: { attempt, max }` with `attempt ≤ max`. The stream
  enforces the bound.
- The canonical retry pattern: the gate reads retries from the
  incoming event (or defaults to attempt 1), increments
  attempt, and returns the same type with the updated retries.
  On the final attempt the gate emits a different-type result
  or error event.

### Stream

- `new Stream({ log = null, parentStreamId = null } = {})`.
  If a log is given, the stream takes `streamId` from
  `log.nextStreamId()`.
- `register(gate)` — signature collision throws
  `Signature collision: '<sig>'`.
- `emit(event)` — synchronous; returns when the event's entire
  causal tree has settled. Delivery is an iterative FIFO queue
  (breadth-first, causal generations). Chain length is bounded
  by the acyclicity rule (or the retry bound for same-type),
  not by the call stack — 20k-gate chains are tested.
  - **Retry enforcement:** when a gate returns an event whose
    type matches its own signature, the stream checks the
    event's retries field. If present and `attempt ≤ max`, the
    event is enqueued. Otherwise the event goes to pending
    (treated as unclaimed) and is logged with `gateClaimed:
    null`. An eventId is assigned. The check is stateless —
    the stream reads the event, not its own history.
  - Each delivery increments `eventCount` and is assigned
    `eventId = eventCount`, with `causeId` = the id of the
    producing event (`null` for external emits).
  - Unclaimed events go to `pending`. Non-Event values in a
    returned array are silently skipped (known issue).
- `sampleHere()` → `{ pending: [...copy], eventCount,
  gateCount }`. Non-destructive.
- **Ordering guarantee: none.** Per Rule 3, processing order
  is not part of the semantics. The breadth-first order is an
  implementation choice.

### StreamLog

- `new StreamLog(level = 'EVENTS')`; levels OFF < EVENTS <
  DEEP < DATA, mutable at runtime, unknown level strings
  behave as OFF (known sharp edge).
- `record({...})` — called by Stream; entry fields by level:
  - EVENTS: `seq`, `time`, `type`, `claimed`, `eventId`,
    `causeId`, `retries` (when present)
  - DEEP: + `streamId`, `parentStreamId` (omitted when null)
  - DATA: + `data` (the event payload, by reference)
- `sample()` → `{ level, count, entries: [...copy] }`.
- `clear()` resets entries and seq.
- `nextStreamId()` — monotonically increasing, **module-global
  counter** shared across all StreamLog instances (tracked
  canon question).
- Causal reconstruction: entries form a forest under
  `causeId` → `eventId` links; roots have `causeId: null`.
  Retry chains appear as same-type sequences linked by
  causeId; the retries field on each entry tracks the
  attempt/max for that pass. Entry order means time;
  causeId means cause. `eventId` is per-stream.

## Gates

None shipped. `Gate` is an abstract base; all concrete gates
live in downstream projects. Test files define local gates
(sinks, forwarders, splitters, chain steps, retry fixtures)
as fixtures only.

## Tests

- **core.test.js — 33/33.** Event construction; Event retries
  field (absent by default, present when provided); Gate base;
  registration and collision; emit/pending; single/array/null/
  undefined returns; chain causal order; fan-out; breadth-first
  generation order; 20,000-gate legal chain; event non-
  annotation; same-type without retries → pending; valid retry
  loop with multiple passes; retry exhaustion with error event;
  retry bound exceeded → pending; different-type emission from
  retry gate; retry event non-annotation; sampleHere copy
  semantics; eventCount; constructor options.
- **streamlog.test.js — 25/25.** Level gating; seq and
  timestamps; nextStreamId; sample copy; clear; runtime level
  changes; Stream integration; shared log across sub-streams;
  causal ids on chains, fan-out, separate external emits;
  thread reconstruction from a mixed tree; behavioral identity
  with and without a log; retry entries carry retries field;
  non-retry entries clean; blocked re-emission logged as
  unclaimed; retry causal chain reconstruction.
- **Gaps:** no test for unknown log level strings; no test
  pinning the silent skip of non-Event array members; no
  multi-stream causal-id collision test at EVENTS level; no
  test for indirect cycle detection (not enforced by design).

## Known issues

- **Minor / by decision:** indirect cycle detection (A → B → A)
  is a documented invariant with no runtime detection. Same-type
  re-emission without retries is now blocked (goes to pending)
  rather than causing infinite queue growth — the failure mode
  improved from v9.0. Indirect cycles remain non-terminating.
- **Minor, pre-existing:** non-Event values inside a returned
  array are silently dropped. Candidate for a throw.
- **Minor, pre-existing:** `streamIdCounter` is module-global.
  Flagged in dev-notes §1.
- **Minor, pre-existing:** unknown log level strings silently
  behave as OFF.

## Future (noted, not committed)

- `StreamLog.threads()` — causal-projection view.
- Frozen read-only sampling views.
- Fixture-based language-agnostic test harness.
- Runtime detection of indirect cycles (would require tracking
  visited types per causal chain in the queue — adds state,
  not committed).

## Changelog

- **10.0** (2026-08-28) — bounded retry via `retries` field
  on Event; stream-enforced same-type re-emission; principles
  1.1 → 1.2; +12 tests. See `changelogs/changelog-v10.0.md`.
- **9.0** (2026-08-03) — queue delivery (BFS, unbounded
  chains), causal tagging, acyclicity canonized, canonical
  file structure, +8 tests.
- **1.0** — recursive depth-first delivery; Event/Gate/Stream/
  StreamLog; 38 tests; flat layout.
