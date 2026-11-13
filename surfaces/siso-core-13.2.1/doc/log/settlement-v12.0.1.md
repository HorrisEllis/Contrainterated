# Settlement v12.0.1

- date: 2026-10-05
- version: 12.0 → 12.0.1

## What changed

Patch release after 12.0's audit. The user decided to close departure X6
rather than widen R3, and to clear the kit's open hardening items.

**Contracts (settling instance, no workspace open).**
- core-api: a new OWNERSHIP section: the stream never hands out an object
  it still holds; a take hands the residue over; a sample's residue holds
  the sampler's own frozen copies. `check.ownership` added (rawset on a
  sample's event, data and nested data must not reach the stream or a
  later take). The `rawset` sentence moved out of READ-ONLY: only a gate's
  own input, inside a propagation, is still R3.
- observer-seam: a record's `data` is the observer's own frozen copy;
  `check.ownership` (both attach orders; an observer's rawset reaches
  neither the residue nor another observer; assignment checked before
  rawset).
- kit-api: `helper.done()` prints "register: <module> <path>".
- Contract fake core: copies by default; switch `shareFrozen` reproduces
  12.0 and both ownership checks catch it. Contract tests: core-api 11/11,
  observer-seam 9/9.

**stream (`code/core/`).**
- stream-ownership: `freeze.copy` builds a fresh frozen copy sharing no
  table with the stream or another copy at any depth (marks kept,
  `__readonly` passed through); `sampleHere` copies each residue event;
  each observer's records carry their own copy, made only when an
  observer is attached. Retires X6. Four assertions in stream-doors and
  stream-freeze tests pinned identity (a sample or record being the
  stream's own object); they now compare values, each marked "12.0.1:
  OWNERSHIP". The phase's produces were widened to those two test files
  in a contracts step after its first run surfaced them: the workspace was
  discarded, the declaration changed with none open, the workspace
  reopened, the phase's own files restored into it, and it landed through
  the tool. Cost (from its test): sampleHere on 2,000 events × 10-entry
  data 0.0001 s → 0.0083 s; a 20,000-gate chain with one observer 0.1382
  s → 0.1663 s.

**kit (`code/kit/`).**
- kit-lint-require: the lint flags a `require` used as a value
  (`pcall(require, …)`, an alias, passed to a function) as
  DYNAMIC_REQUIRE; the header's blind spot is now only `_G.require` and
  `rawget(_ENV, "require")`. lint.lua runs as a command with any number of
  arguments (module only when its second vararg is its own file).
- kit-register-check: `helper.done()` prints the register line; the census
  refuses a per-gate test that shadows `register` on package.path, re-runs
  itself under another KIT_REGISTER, or prints no register line (the
  source check stays). run.lua is a module only when required as
  `kit.run`; before, two or more arguments made it exit 0 without
  running. kit-helper.test's pinned third line changed from digest to
  register.

**Audit.** Conformance re-audited against principles 2.0: compliant, 15 of
15 (items 10 and 12 now by architecture). Probe P9 added (an observer's
rawset). Note 1 records the cost of ownership: 1,000 samples of a 2,000-
event residue 7.1584 s.

**Issues fixed and deleted from doc/ISSUES.md:** X6; the lint's
require-as-value blind spot; the census's source-only register check; the
run.lua/lint.lua command detection. Three minor follow-ups from
kit-register-check opened in doc/ISSUES.md: a deliberately forged
register line, the census reading only the first digest run, and
kit-helper.test's dependence on the working directory.

## Settled phases

### stream-ownership
- in: stream
- uses: core-api, observer-seam
- needs: code/core/freeze.lua
- produces: code/core/stream.lua, code/core/freeze.lua, sisoTests/stream-doors.test.lua, sisoTests/stream-freeze.test.lua, sisoTests/stream-ownership.test.lua
- check: lua5.4 sisoTests/core.test.lua && lua5.4 sisoTests/stream-doors.test.lua && lua5.4 sisoTests/stream-freeze.test.lua && lua5.4 sisoTests/stream-ownership.test.lua
- does: hands samples and observer records their own frozen copies, so nothing the outside does to them reaches the stream (core-api OWNERSHIP; retires X6)

The test runs `core_api.check.ownership` and `observer_seam.check.ownership`
(and the existing doors, read-only and seam checks) on the real core with
no problems. Assertions in stream-doors and stream-freeze tests that pin a
sample or an observer record being the stream's own object are what
OWNERSHIP forbids; the phase changes exactly those to compare values, and measures the added cost of a sample and of an observer
batch on the existing timing workloads, printed, not asserted.

### kit-lint-require
- in: kit
- uses: kit-api
- produces: code/kit/lint.lua, sisoTests/kit-lint.test.lua, sisoTests/kit-lint-require.test.lua, sisoTests/kit-fixtures/lint-require/
- check: lua5.4 sisoTests/kit-lint.test.lua && lua5.4 sisoTests/kit-lint-require.test.lua
- does: the lint flags a `require` used as a value, and decides "run as a command" without depending on the argument count

### kit-register-check
- in: kit
- uses: kit-api, core-api
- produces: code/kit/helper.lua, code/kit/census.lua, code/kit/run.lua, sisoTests/kit-helper.test.lua, sisoTests/kit-census.test.lua, sisoTests/kit-register-check.test.lua, sisoTests/kit-fixtures/register-check/
- check: lua5.4 sisoTests/kit-helper.test.lua && lua5.4 sisoTests/kit-census.test.lua && lua5.4 sisoTests/kit-register-check.test.lua
- does: helper.done prints the register it read and the census checks it at run time; run.lua decides "run as a command" without depending on the argument count

## Results

Pasted from this settlement's run.

- phase stream-ownership: stream-ownership: 14/14 passed
- phase kit-lint-require: kit-lint-require: 15/15 passed
- phase kit-register-check: kit-register-check: 20/20 passed
- census: clean
- contract core-api: contract core-api: 11/11 passed
- contract observer-seam: contract observer-seam: 9/9 passed
- contract fixture-format: contract fixture-format: 5/5 passed
- contract kit-api: contract kit-api: 5/5 passed
- lua5.4 code/kit/run.lua: run: 17 suites, 431/431 passed
- lua5.4 code/kit/lint.lua: lint: 20 files, 0 findings, 0 excused, 0 stale exceptions
- lua5.4 code/kit/census.lua: census: 0 gates, 0 tested, 0 untested
- lua5.4 sisoTests/fixtures.test.lua: fixtures: 22/22 passed

## Carried forward

- no waiting phases

