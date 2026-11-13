# Settlement v13.0.0

- date: 2026-10-20
- version: 13.0.0-rc1 → 13.0.0

**Numbering.** This release was first settled and zipped as 13.0.1, and
the build it replaces as 13.0.0. That build was never released, so at
the user's request the release is numbered 13.0.0 (core 13) and the
unreleased build 13.0.0-rc1, throughout the code comments, test names,
docs and these logs. Nothing else changed: the code and its behaviour
are those of the 13.0.1 zip.

## What changed

13.0.0-rc1 was settled but never released: an independent review and a run of
jää's suite on it found regressions and an older statelessness hole.
13.0.0 replaces it. Breaking for consumers (see *For consumers*).

**The headline: a state channel through the event (12.0.1 and 13.0.0-rc1).**
`getmetatable(event)` handed every gate the core's own event metatable, one
writable table behind every event in every stream; the kit's `null` and
array mark did the same. A gate with no upvalues and no globals counted
1, 2 across two separate streams with plain assignment
(`getmetatable(e).n = (getmetatable(e).n or 0) + 1`). The lint allows
`getmetatable` and cannot see writes through what a call returns, so this
went past the tool that holds statelessness for gate authors. Rule 1 /
Stateless, Parallel-safe, causality by events; an unnamed concession
(Drift 8). Neither audit had caught it.

**Contracts (contracts phases, in place).**
- core-api: STATELESS THROUGH THE EVENT (events answer getmetatable with
  "siso.event"; nothing reachable from plain-data events holds state);
  IMMUTABLE SENTINELS (a table answering a "readonly:" string passes
  freezing with its identity, and must refuse writes itself; the core 12
  `__readonly = true` form still recognised, spec R4); OWNERSHIP (entry
  takes the stream's own copy); SISO_NOT_EVENT also refuses an entry too
  deep to copy. Checks: `check.readonly` (string answers, counting probe
  across streams, string sentinels), `check.ownership` (entry copy incl.
  re-entered residue; deep entries at 1,000–61,000 levels never raise
  raw). 12 → 14 contract tests.
- observer-seam: THE REJECTION is a plain read-only copy (own fields at
  every depth, getmetatable false or a string mark, never a table; an
  error object's methods and inherited fields not carried); ISOLATION
  builds every observer's batch before any is called.
  `rejection_copy_problem` rejects any table answer and any table shared
  with the raised one. `check.ownership` gained the metatable-path tamper
  test (host's tostring and a later stream untouched) and the
  taker/reader order test. 11 → 12.
- kit-api: marks answer "kit.data.array" and "readonly:kit.data.null";
  `data.array` on a marked array returns it. 5 → 6.
- Fake core (D2): string marks, entry copy, plain rejection copy, and
  switches `tableMeta`, `entryAlias`, `richRejection` (13.0.0-rc1's flaws),
  each caught.

**stream (stateless-doors, observers-and-entry).**
- `event.lua`, `rejection.lua`: the module metatables carry
  `__metatable` = "siso.event" / "siso.rejection"; `Event.is` and
  `Rejection.is` compare the strings.
- `freeze.lua`: recognises its proxies by a module-private key that is a
  function (cannot hold a field); the views' shared metatable answers
  false; nothing module-level is written after load; only a table with a
  metatable is asked for the key, so plain data never runs foreign code;
  "readonly:" sentinels pass; `freeze.plain` (own fields, string marks
  kept, no metatable tables). The phase agent first built a weak
  registry of proxies instead; the user chose the key (option A): a
  registry is core state written on every freeze.
- `stream.lua`: entry copies with `freeze.copy` under pcall (too deep →
  SISO_NOT_EVENT, no delivery: 13.0.0-rc1 raised a raw stack overflow at
  ~59,000 levels, which 12.0.1 settled); `notify` builds every
  observer's batch (each under pcall) before calling any; the
  rejection's copy is plain data of its own fields.
- Tests: new `stream-stateless` (12), `stream-observers-entry` (9);
  born-frozen, freeze (benchmark "without" arm now loads an `Event` that
  does not freeze, and a test checks it), ownership, rejection-copy,
  doors updated.
- stateless-doors' agent stalled after finishing its code and was
  stopped by the user; observers-and-entry was done by the settling
  instance. Both RESULT files say so.

**kit (stateless-marks).** `data.lua`: string marks; `data.null` refuses
assignment. New `kit-data-stateless` (15); `kit-data` null case updated.

**fixtures (fixtures-plain-copy).** `run.lua`'s `copy` keeps marks through
`data.is_array`/`data.array` and `data.null` itself, never copying a
metatable. New `fixtures-marks` (10); `fixtures-rejection-copy`'s wrapper
shows a plain copy.

**Found and fixed this cycle** (13.0.0-rc1 regressions first): the host's
handle aliasing residue after `emit` (13.0.0-rc1); an error object's
inherited fields lost in the observer's copy, now contracted as own
fields only (13.0.0-rc1); `rejection_copy_problem` blind to both (13.0.0-rc1);
observers reaching the host's rejection through its metatable (C-2 only
half fixed in 13.0.0-rc1, present in 12.0.1); observers seeing each other's
writes by call order; V-6 reopened at the entry door (13.0.0-rc1); the freeze
benchmark measuring nothing (13.0.0-rc1); the event-metatable state channel
(12.0.1).

**Not fixed, named.** Spec ledger: R3 reworded (entry copy; coroutine
yield is an effect like a throw); R4 (core 12 `__readonly` sentinels hand
out their metatable); R5 (code holding a stream reaches its fields: the
doors are a convention for trusted host code); X1 extended (freeze's key
can be captured by a crafted `__index`, including through `pairs`'
iterator, giving writes past the refusal the lint cannot see; no state).
The kit helper no longer detects `rawset` at run time; the lint flags
`rawset` (GLOBAL_READ).

**Corrections to the 13.0.0-rc1 log.** "The helper's own mutation detector
remains for `rawset`" was false (the helper reads through the read
surface). jää's test impact was undercounted: 9 suites, not 7 sites, and
the instrumented run could not see writes after an event's first use.

**For consumers (jää first).** On top of 13.0.0-rc1's list:
(1) code that reads a mark with getmetatable and puts it back with
`setmetatable(t, mt)` fails ("table expected, got string"): use
`kit.data.is_array` / `data.array`, and pass "readonly:" sentinels
through. jää's live `code/lib/util.lua` `deep_copy` (line 233, 20 call
sites) is the one live-code site; a three-line change there made every
query answer again in a trial run (jää 2.16.0 on this core and kit:
`run: 195 suites, 11179/11188 passed`, the remaining failures being the
layer-guard pins and test files writing to built events, plus
`substrate/rel.test.lua:356,374`, the same setmetatable pattern);
(2) `Event.is` and comparisons against the event metatable: compare
getmetatable to "siso.event", or use `Event.is`; (3) an observer's
rejection is plain data: no `tostring`, no methods on `cause`; read
`message`; (4) jää's own `json.null` (`__readonly = true`) still works
but hands out its metatable: move it to the string form or to
`kit.data.null`; (5) an entry too deep to copy is now SISO_NOT_EVENT.

## Settled phases

### stateless-doors
- in: stream
- uses: core-api, observer-seam
- produces: code/core/event.lua, code/core/rejection.lua, code/core/stream.lua, code/core/freeze.lua, sisoTests/stream-stateless.test.lua, sisoTests/stream-born-frozen.test.lua, sisoTests/stream-rejection-copy.test.lua, sisoTests/stream-doors.test.lua, sisoTests/stream-freeze.test.lua, sisoTests/stream-ownership.test.lua, sisoTests/core.test.lua
- check: lua5.4 sisoTests/stream-stateless.test.lua && lua5.4 sisoTests/stream-born-frozen.test.lua && lua5.4 sisoTests/stream-rejection-copy.test.lua && lua5.4 sisoTests/stream-doors.test.lua && lua5.4 sisoTests/stream-freeze.test.lua && lua5.4 sisoTests/stream-ownership.test.lua && lua5.4 sisoTests/core.test.lua
- does: closes the state channels at the doors (core-api STATELESS THROUGH THE EVENT, IMMUTABLE SENTINELS, OWNERSHIP; observer-seam THE REJECTION)

What the contracts now ask of the stream: events and rejections answer
getmetatable with the strings "siso.event" and "siso.rejection" (the
module metatables are never handed out; `Event.is` and `Rejection.is`
compare the strings); freezing passes through a table whose getmetatable
answers a string beginning "readonly:" (the core 12 `__readonly = true`
form stays recognised); entry takes the stream's own copy, so the host's
handle never reaches the residue (taken residue entered again included);
each observer's rejection is a plain copy, own fields at every depth, no
metatables (sentinels pass), built inside that observer's protected
call. Tests that pin the old answers are in produces: the stream suites
that apply `rejection_problems` (which asks `tostring`) to an observer's
copy should not, since tostring is not promised there. `stream-freeze`'s
cost benchmark compares freezing against a stream that does not freeze,
but `core.event` now freezes too, so its "without" arm must load an
`Event` that does not freeze either, or it measures nothing.

### stateless-marks
- in: kit
- uses: kit-api, core-api
- produces: code/kit/data.lua, sisoTests/kit-data-stateless.test.lua, sisoTests/kit-data.test.lua, sisoTests/kit-data-frozen.test.lua, sisoTests/kit-helper.test.lua
- check: lua5.4 sisoTests/kit-data-stateless.test.lua && lua5.4 sisoTests/kit-data.test.lua
- does: the kit's marks hand out strings, not tables (kit-api: "kit.data.array", "readonly:kit.data.null")

A marked array answers "kit.data.array" (its metatable carries
`__metatable`); `data.array` on a marked array returns it; `is_array`
and encode's metatable rule compare the string. `data.null` answers
"readonly:kit.data.null" and still refuses assignment. Freezing passes
the null through only once the stream recognises "readonly:" sentinels
(stateless-doors), so `kit-data-frozen` and the kit-api module check
with `freeze` given are left to the final conditions, not this check.

### fixtures-plain-copy
- in: fixtures
- uses: observer-seam, kit-api, fixture-format
- produces: code/fixtures/run.lua, sisoTests/fixtures-rejection-copy.test.lua, sisoTests/fixtures-marks.test.lua
- check: lua5.4 sisoTests/fixtures-marks.test.lua && lua5.4 sisoTests/fixtures-rejection-copy.test.lua
- does: the runner copies fixture data through kit.data's API, and its rejection-copy test shows observers a plain copy

`copy` in `run.lua` does `setmetatable(t, getmetatable(v))`, which
breaks once marks answer strings; copy marks with `data.is_array` /
`data.array` and keep `data.null` itself, so it holds with the kit before
and after stateless-marks. The wrapper in `fixtures-rejection-copy`
copies the rejection with `freeze.copy`, which keeps the rejection's
metatable; under THE REJECTION it must show a plain copy.

### observers-and-entry
- in: stream
- uses: core-api, observer-seam
- produces: code/core/stream.lua, code/core/freeze.lua, sisoTests/stream-observers-entry.test.lua, sisoTests/stream-rejection-copy.test.lua, sisoTests/stream-stateless.test.lua, sisoTests/stream-doors.test.lua, sisoTests/stream-ownership.test.lua
- check: lua5.4 sisoTests/stream-observers-entry.test.lua && lua5.4 sisoTests/stream-rejection-copy.test.lua && lua5.4 sisoTests/stream-stateless.test.lua && lua5.4 sisoTests/stream-doors.test.lua && lua5.4 sisoTests/stream-ownership.test.lua && lua5.4 sisoTests/stream-freeze.test.lua && lua5.4 sisoTests/stream-born-frozen.test.lua && lua5.4 sisoTests/core.test.lua
- does: observers' batches built before any is called; an entry too deep to copy is a rejection; plain copies keep string marks

Three gaps a review of the landed stateless-doors found, each in the
contracts now: (1) observer-seam ISOLATION: build every observer's batch
(records and rejection copies, each inside that observer's protection)
before calling any observer, so an observer that takes the residue and
writes to it reaches no other observer's records; (2) core-api
SISO_NOT_EVENT: the entry copy (`freeze.copy`) runs outside protection
and overflows the stack for events `Event` accepts (about 59,000 levels
on this substrate); make it under protection and refuse what cannot be
copied as SISO_NOT_EVENT with no delivery, so emit raises nothing else;
(3) observer-seam THE REJECTION: `freeze.plain` keeps a string answer
(a mark such as "kit.data.array") instead of `false`; only tables are
never handed out.

## Results

Pasted from this settlement's run.

- phase stateless-doors: core: 41/41 passed
- phase stateless-marks: kit-data: 84/84 passed
- phase fixtures-plain-copy: fixtures-rejection-copy: 6/6 passed
- phase observers-and-entry: core: 41/41 passed
- census: clean
- contract core-api: contract core-api: 14/14 passed
- contract observer-seam: contract observer-seam: 12/12 passed
- contract fixture-format: contract fixture-format: 5/5 passed
- contract kit-api: contract kit-api: 6/6 passed
- lua5.4 code/kit/run.lua: run: 24 suites, 510/510 passed
- lua5.4 code/kit/lint.lua: lint: 20 files, 0 findings, 0 excused, 0 stale exceptions
- lua5.4 code/kit/census.lua: census: 0 gates, 0 tested, 0 untested
- lua5.4 sisoTests/fixtures.test.lua: fixtures: 22/22 passed

## Carried forward

- no waiting phases

