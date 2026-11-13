# Settlement v13.0.0-rc1

- date: 2026-10-20
- version: 12.0.1 → 13.0.0-rc1

## What changed

Major version: events are read-only from construction, which breaks code
that writes to an event after building it (see *For consumers*).

**Why.** A review of 12.0.1 found that `Event()` returned a plain table: a
gate or a boundary layer could add a field (`o.cause = 7`) or write into
`data` before the event reached the stream, and the stream froze the extra
field in. Checklist item 5 (shape, architecture required) was claimed met
and was not. jää's audit (2026-10-08) had also filed two items back to
core 12 that this project's ISSUES did not carry: V-6 (a deeply nested
event returned by a gate made `emit` raise a raw "stack overflow", item 8)
and C-2 (every observer was handed the very rejection `emit` raises,
unfrozen, so an observer's write changed what the host received, item 12).

**Contracts (contracts phase, run in place).**
- core-api EVENT: `Event(type, data)` returns an event read-only from the
  moment it is built, wherever it is built; the data is taken as it stands
  at the call (later writes to the caller's table do not reach it); data
  already read-only keeps its identity. READ-ONLY reworded to match.
  `check.readonly` gained construction checks (assignment to a new
  event's fields, an added field, data and nested data, default data;
  writes to the passed table not reaching the event; identity of a carried
  event, frozen data and a `__readonly` sentinel). `check.doors`' default-
  data check now counts with `pairs`: its `next` saw nothing on a frozen
  table and had become vacuous.
- observer-seam: new THE REJECTION section: each observer's batch carries
  its own read-only copy of the rejection (same fields and values, a table
  `cause` copied by value; `tostring` not promised on the copy). ISOLATION:
  building an observer's batch, copies included, is part of that
  observer's protected call. New `rejection_copy_problem(copy, raised)`;
  `check.seam` uses it instead of identity; `check.ownership` gained the
  rejection's isolation (both attach orders).
- `sisoTests/contract-fake-core.lua` (D2) freezes at construction and
  copies the rejection; new switches `writableAtBirth` (12.0's events) and
  `sharedRejection` (12.0's C-2), each caught by a new contract test.
  Contract tests: core-api 11 → 12, observer-seam 9 → 11.

**stream (doors-13).**
- `code/core/event.lua`: `Event` returns `freeze(…)` of what it builds.
  The stream still freezes on entry and on return, a no-op for a frozen
  event.
- `code/core/stream.lua`: `notify` calls a new `show` per observer under
  `pcall`; `show` builds the record copies and a `freeze.copy` of the
  rejection inside that call. `emit` still raises the very table, `cause`
  included.
- `code/core/freeze.lua`: header only.
- Tests: new `stream-born-frozen` (11) and `stream-rejection-copy` (7);
  `stream-freeze`'s "never annotated" test now expects a new event to
  refuse writes; `stream-doors`' two observer tests judge the copy with
  `rejection_copy_problem`; `kit-helper`'s three mutation tests expect
  SISO_GATE_THREW / "read-only" (a gate writing its input now raises in
  the helper as it does in the stream; the helper's own mutation detector
  remains for `rawset`).
- First attempt rejected by its own check: the registration missed
  `stream-doors.test.lua`. The workspace was discarded, the declaration
  fixed, and the phase re-run.

**fixtures (fixtures-rejection-copy).** `code/fixtures/run.lua` judges each
rejected batch with `observer_seam.rejection_copy_problem` instead of
`rawequal`; `run(fixture [, Stream])` takes an optional Stream constructor.
New `fixtures-rejection-copy.test.lua` (6) runs every fixture file on a
test-local copying stream and shows the raised table, a changed copy and a
writable copy each failed.

**Fixed.** Item 5's shape hole (post-construction writes); jää V-6 for a
gate's outputs (now SISO_GATE_THREW; a host building a too-deep event now
fails in its own `Event()` call, before the stream); jää C-2.

**Not fixed, recorded.** Spec Ledger: R3 reworded (frozen from
construction; rejection copies). A deceptive metatable (a lying `__len`,
a `__metatable` impostor event, a thrown table whose `__index` raises)
still reaches past the stream's judgement: recorded departure X1 with its
fix, deferred by the user. Probes this session: `__len` 4 on a 2-list →
raw error; `__len` 1 → one event dropped; impostor → residue
`data,extra,type`; hostile throw → raw raise.

**For consumers (jää first).** Breaks code that (1) writes to an event or
its data after `Event()`; (2) fills the passed data table after building
the event (silent: the event keeps the data as it was); (3) relies on
`Event(t, d).data == d`; (4) reads its own events with `next`, `rawget`,
`rawlen`; (5) marks or `setmetatable`s data after construction;
(6) tweaks `helper.event(...)` inputs in tests; (7) relies on an observer's
rejection being the raised table, or `tostring` on it. jää 2.16.0 checked
this session: its suite run under an instrumented `Event` found no
post-construction change in live code; 34 hits from 7 sites in 5 test files
(`jaa-update_commit_read:239`, `jaa-update_read:176`, `jaa-insert_read:365`,
`jaa-delete_read:170`, `jaa-delete_commit_read:219`,
`jaa-drop_table_commit_read:391,398`), all direct assignments that now
raise; its layer guard's hash pins need the new core files.

## Settled phases

### doors-13
- in: stream
- uses: core-api, observer-seam
- produces: code/core/event.lua, code/core/stream.lua, code/core/freeze.lua, sisoTests/stream-born-frozen.test.lua, sisoTests/stream-rejection-copy.test.lua, sisoTests/stream-freeze.test.lua, sisoTests/stream-doors.test.lua, sisoTests/kit-helper.test.lua
- check: lua5.4 sisoTests/stream-born-frozen.test.lua && lua5.4 sisoTests/stream-rejection-copy.test.lua && lua5.4 sisoTests/stream-doors.test.lua && lua5.4 sisoTests/stream-freeze.test.lua && lua5.4 sisoTests/stream-ownership.test.lua && lua5.4 sisoTests/core.test.lua && lua5.4 sisoTests/kit-helper.test.lua
- does: brings the doors to core 13 (core-api EVENT, observer-seam THE REJECTION)

One phase, not two: the existing stream suites run core-api's and
observer-seam's checks together, so neither change passes them alone.
`Event` freezes what it builds (the stream keeps freezing on entry and on
return; a frozen event passes through untouched). Each observer is shown
its own read-only copy of the rejection, built inside that observer's
protected call, as its record data already is. The host still receives
the very table raised, `cause` included. `stream-freeze.test.lua`'s
"the emitter's original … is never annotated" assumed an event writable
after construction; `kit-helper.test.lua`'s three mutation tests expect
"mutated its input", where a gate writing its read-only input now raises
inside the helper as it does in the stream. `stream-doors.test.lua`'s
two observer tests assert `rawequal(b.rejection, r)`; under THE
REJECTION they judge the copy with `rejection_copy_problem`.

### fixtures-rejection-copy
- in: fixtures
- uses: observer-seam, core-api, fixture-format
- produces: code/fixtures/run.lua, sisoTests/fixtures-rejection-copy.test.lua
- check: lua5.4 sisoTests/fixtures-rejection-copy.test.lua
- does: judges each batch's rejection with observer-seam's rejection_copy_problem instead of by identity

The runner checks `rawequal(b.rejection, err)` today. Its test must hold
whatever the core in the workspace does: run the fixture files on a
stream whose observers are shown a copy of the rejection (a test-local
wrapper over `core.stream`, say) and show the runner accepting it, and
rejecting a batch that carries the raised table itself.

## Results

Pasted from this settlement's run.

- phase doors-13: kit-helper: 60/60 passed
- phase fixtures-rejection-copy: fixtures-rejection-copy: 6/6 passed
- census: clean
- contract core-api: contract core-api: 12/12 passed
- contract observer-seam: contract observer-seam: 11/11 passed
- contract fixture-format: contract fixture-format: 5/5 passed
- contract kit-api: contract kit-api: 5/5 passed
- lua5.4 code/kit/run.lua: run: 20 suites, 458/458 passed
- lua5.4 code/kit/lint.lua: lint: 20 files, 0 findings, 0 excused, 0 stale exceptions
- lua5.4 code/kit/census.lua: census: 0 gates, 0 tested, 0 untested
- lua5.4 sisoTests/fixtures.test.lua: fixtures: 22/22 passed

## Carried forward

- no waiting phases

