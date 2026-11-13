# Settlement v12.0

- date: 2026-10-05
- version: 11.1 → 12.0

## What changed

Core 11.1 (JavaScript) → 12.0 (Lua 5.4 reference). The project was
retrofitted to the phased-spec format in this cycle: `doc/SPEC.md` replaces
`doc/specs/spec-v<N>.md`; the old specs and changelogs moved to
`doc/log/specs/` and `doc/log/changelogs/`; the frozen JavaScript 11.1
core moved from `legacy/js/` to `doc/log/legacy-js/` at this settlement
(its 41 + 26 tests still pass there with node). Principles read in full
this cycle: SISO-principles 2.0 DRAFT, made final here.

**Skeleton (contracts phase).** Boundaries stream (`code/core/`), observer
(`code/observer/`), kit (`code/kit/`), fixtures (`fixtures/`,
`code/fixtures/`), principles (`doc/SISO-principles.md`), and legacy for
the cycle. Contracts, each a Lua module with a check and a contract test:
core-api, observer-seam, fixture-format, kit-api, and oracle (the frozen
JS, for the cycle). Shared: `code/harness.lua` (port of runner.js). The
contract tests run each check against a throwaway fake core
(`sisoTests/contract-fake-core.lua`) with switches reproducing 11.1's
departures. Decisions taken with the user at the checkpoint: rejections
are raised tables; ids per propagation; the observer is shown each
propagation once, after it ends, in protected mode; Gate takes its shape;
kit.data owns the null and array marks; legacy JS to the log at
settlement.

**stream (`code/core/`).**
- stream-port: Event, Gate, Stream ported to Lua from 11.1 with its 41
  tests, behaviour taken from the JS oracle, known issues kept; then:
- stream-doors: `core.rejection` builds every rejection (fields exactly
  core-api REJECTION; SISO_SELF_LOOP now carries causeId). SISO_NOT_EVENT
  at the entry door (no delivery) and for any return that is not nil, an
  Event or a list with keys exactly 1..n (retires X1, X4). Ids numbered
  per propagation; `eventCount` gone from the stream and the sample
  (retires R1). `Stream()` takes no options; `attach`/`detach`; each
  observer called once per propagation after commit (settled) or just
  before the raise (rejected), in `pcall`, with its own batch (retires
  the door half of X5; R2's door). Five ported tests rewritten, each
  marked "12.0:".
- stream-freeze: `core.freeze` makes every entering and returned event
  read-only with all its data (proxies built once; a module-private key
  recognises them; `__readonly` tables pass through; getmetatable
  answers the original's metatable or false). Retires the data half of
  X5; R3 thinned to statelessness, effects and `rawset`. A frozen list
  returned by a gate is judged through its read surface (not dropped).

**observer (`code/observer/`).**
- observer-12: `observer.streamlog` on the 12 seam: `StreamLog(level)`,
  `log:observer(label, parentLabel)` for `stream:attach`, `setLevel`,
  `sample`, `clear`. Labels and lineage observer-side; each log numbers
  its own propagations; no module-global counter (retires R2's counter);
  an unknown level raises (retires X2). 34 tests port the intent of
  11.1's 26.

**kit (`code/kit/`).**
- kit-data: `kit.data` (jää's lib.json with util's key order inlined;
  `null` declares `__readonly`) and `kit.sha256`.
- kit-lint: `kit.lint`, jää's lint with every rule kept, driven by a
  project's `lint_config`; fixes jää's false positives on `require` and
  `math.random` inside strings. Reopened twice by the settling instance to
  correct this project's config: the observer is not a pure layer (it
  keeps state by design, R2), and the kit may require the shared harness.
- kit-helper: `kit.helper` (pure gates only; StateGates, retries,
  ReadSets and MutationBatches left in jää), `kit.canon`, `kit.digest`,
  `code/kit/run.lua` (a gate test is a file that requires kit.helper and
  calls done; run twice with agreeing digests).
- kit-census: `kit.census` with run.lua's gate-test rule; no register →
  0 gates, exit 0.
- kit-data-frozen: `data.encode` reads frozen data as the plain data it
  presents (registered after stream-freeze, with the kit-api amendment).

**fixtures.** 15 JSON fixtures covering Rules 1–4, K1–K6 and the outside;
`code/fixtures/run.lua` (also a module) with the contract's order-free
matching plus one-batch-per-entry and rejection-shape checks.

**principles.** 2.0 final, approved by the user: DRAFT dropped; the Lua
reference named in Scope, the reference-core line (spec `doc/SPEC.md`)
and Instances; new rule under Instances: an instance in a language the
reference is not written in passes the reference's fixture suite.

**Contract amendments during the cycle (settling instance, no workspace
open):** core-api READ-ONLY gained the `__readonly` pass-through and
same-value reads; core-api's plain data includes floats and null; kit-api
names `code/harness.lua` as vendored and requires `encode` to read frozen
data (check takes an optional `freeze`); fixture-format allows empty
lists in steps (only event data is restricted). The shared harness now
prints its failure list before the summary line.

**Found by the settlement audit:** X6 — frozen data is shared, so an
observer's `rawset` can change residue after the fact; checklist item 12
is not met. Recorded in the spec's Ledger and `doc/ISSUES.md` for the
user's decision.

**Process notes.** `kit-lint`'s config reopening was rejected twice at
landing before it landed (a second pinned assertion; a preview step that
deleted workspace files), both correctly. kit-data-frozen read
observer_seam's header, outside its declared contracts, to learn a
batch's shape for its test (reading by convention, method M3).

**Issues fixed and deleted from doc/ISSUES.md:** X1, X2, X4, X5 (data and
door), R1; the principles' spec path; core.test's 11.1 pins; the harness's
summary line; kit.data on frozen data; fixture-format empty lists; the
plain-data mismatch between core-api and kit-api; census/run gate-test
agreement; the census as a final condition; conformance re-audited.

## Settled phases

### stream-port
- in: stream
- uses: core-api, oracle
- produces: code/core/event.lua, code/core/gate.lua, code/core/stream.lua, sisoTests/core.test.lua
- check: lua5.4 sisoTests/core.test.lua
- does: ports 11.1's Event, Gate and Stream and their tests to Lua, behaving as the JS oracle does, known issues included

Expected values come from `legacy/js/` (run it), never from running the
Lua code. Gate takes the Lua form `Gate(signature, transform, shape)`.

### stream-doors
- in: stream
- uses: core-api, observer-seam
- needs: code/core/stream.lua
- produces: code/core/stream.lua, code/core/gate.lua, code/core/rejection.lua, sisoTests/core.test.lua, sisoTests/stream-doors.test.lua
- check: lua5.4 sisoTests/core.test.lua && lua5.4 sisoTests/stream-doors.test.lua
- does: brings the doors to core-api and observer-seam: rejection tables, per-propagation ids, SISO_NOT_EVENT, attach and detach with one batch per propagation

`stream-doors.test.lua` runs `check.doors` and `check.seam` on the real
core and requires no problems. The 11.1 tests that pinned what changes
(`eventCount`, the log option) are rewritten to the new contract, each
change named in a comment.

### stream-freeze
- in: stream
- uses: core-api
- needs: code/core/rejection.lua
- produces: code/core/event.lua, code/core/stream.lua, code/core/freeze.lua, sisoTests/stream-freeze.test.lua
- check: lua5.4 sisoTests/core.test.lua && lua5.4 sisoTests/stream-doors.test.lua && lua5.4 sisoTests/stream-freeze.test.lua
- does: makes every event that enters or is returned read-only, as core-api's READ-ONLY says (R3)

`stream-freeze.test.lua` runs `check.readonly` and requires no problems,
and measures the cost of freezing on the 20,000-gate chain.

### observer-12
- in: observer
- uses: observer-seam, core-api, oracle
- produces: code/observer/streamlog.lua, sisoTests/streamlog.test.lua
- check: lua5.4 sisoTests/streamlog.test.lua
- does: StreamLog on the 12 seam, keeping entries, levels and lineage observer-side, with no module-global counter and an unknown level refused (X2)

Ports the intent of 11.1's StreamLog suite (levels, seq, time, clear,
sample copies, sub-stream lineage, causal reconstruction). Its tests may
drive it with `sisoTests/contract-fake-core.lua`, so it does not wait for
the stream phases.

### kit-data
- in: kit
- uses: kit-api
- produces: code/kit/data.lua, code/kit/sha256.lua, sisoTests/kit-data.test.lua
- check: lua5.4 sisoTests/kit-data.test.lua
- does: canonical JSON with the null and array marks, and SHA-256, generalised from jää's lib.json and lib.sha256

The test runs `kit_api.check` on the real modules and requires no problems.

### kit-lint
- in: kit
- uses: kit-api
- produces: code/kit/lint.lua, sisoTests/lint_config.lua, sisoTests/kit-lint.test.lua, sisoTests/kit-fixtures/lint/
- check: lua5.4 sisoTests/kit-lint.test.lua
- does: the purity and layering lint from jää's, driven by a project's lint_config, with this project's own config

This project's config: `core` and `observer` are pure layers; `core`
requires only `core.*`; `observer` requires nothing from `core`; the
observer's clock is its one excused global. The layer rules are what
holds boundary reach in Lua here (see Ledger, M4).

### kit-helper
- in: kit
- uses: kit-api, core-api
- needs: code/kit/data.lua
- produces: code/kit/helper.lua, code/kit/canon.lua, code/kit/digest.lua, code/kit/run.lua, sisoTests/kit-helper.test.lua, sisoTests/kit-fixtures/helper/
- check: lua5.4 sisoTests/kit-helper.test.lua
- does: the per-gate helper (pure gates only), canonical rendering, the cross-process digest and the suite runner, generalised from jää's sisoTests

StateGates, `retries`, ReadSets and MutationBatches stay in jää. The
helper reads the project's register through `register` or
`KIT_REGISTER`.

### kit-census
- in: kit
- uses: kit-api, core-api
- needs: code/kit/helper.lua
- produces: code/kit/census.lua, sisoTests/kit-census.test.lua, sisoTests/kit-fixtures/census/
- check: lua5.4 sisoTests/kit-census.test.lua
- does: the census: every registered gate has a passing test, shapes hold, the type graph is acyclic, terminal types listed

### kit-data-frozen
- in: kit
- uses: kit-api, core-api
- produces: code/kit/data.lua, sisoTests/kit-data-frozen.test.lua
- check: lua5.4 sisoTests/kit-data.test.lua && lua5.4 sisoTests/kit-data-frozen.test.lua
- does: data.encode reads frozen data (core-api READ-ONLY) as the plain data it presents, so residue can be serialised as handed out

The test runs `kit_api.check` with `freeze = require("core.freeze")` and
requires no problems, and encodes residue, samples and observer records
taken from a real stream. `kit.data` still requires nothing: it reads
frozen tables through `pairs`, indexing, `#` and `getmetatable` only.

### fixtures
- in: fixtures
- uses: fixture-format, core-api, observer-seam, kit-api
- produces: fixtures/, code/fixtures/run.lua, sisoTests/fixtures.test.lua
- check: lua5.4 sisoTests/fixtures.test.lua
- does: the language-agnostic suite, one fixture per rule and concession the core pins, and the Lua runner

Lands once `stream-doors` and `kit-data` have (its check runs the real
core and decodes with `kit.data`). The test runs every fixture through
`code/fixtures/run.lua` and checks each file against `fixture_format`.

### principles-final
- in: principles
- uses: none
- produces: doc/SISO-principles.md
- check: ! grep -q "2.0 DRAFT" doc/SISO-principles.md && grep -q "Lua" doc/SISO-principles.md && grep -q "doc/SPEC.md" doc/SISO-principles.md
- does: principles 2.0 final, drafted with the user: DRAFT dropped, the Lua reference named under Instances, the instance spec at doc/SPEC.md

## Results

Pasted from this settlement's run.

- phase stream-port: core: 41/41 passed
- phase stream-doors: stream-doors: 18/18 passed
- phase stream-freeze: stream-freeze: 15/15 passed
- phase observer-12: streamlog: 34/34 passed
- phase kit-data: kit-data: 84/84 passed
- phase kit-lint: kit-lint: 24/24 passed
- phase kit-helper: kit-helper: 60/60 passed
- phase kit-census: kit-census: 33/33 passed
- phase kit-data-frozen: kit-data-frozen: 21/21 passed
- phase fixtures: fixtures: 22/22 passed
- phase principles-final: 
- census: clean
- contract core-api: contract core-api: 9/9 passed
- contract observer-seam: contract observer-seam: 7/7 passed
- contract fixture-format: contract fixture-format: 5/5 passed
- contract oracle: streamlog: 26/26 passed
- contract kit-api: contract kit-api: 5/5 passed
- lua5.4 code/kit/run.lua: run: 14 suites, 378/378 passed
- lua5.4 code/kit/lint.lua: lint: 20 files, 0 findings, 0 excused, 0 stale exceptions
- lua5.4 code/kit/census.lua: census: 0 gates, 0 tested, 0 untested
- lua5.4 sisoTests/fixtures.test.lua: fixtures: 22/22 passed

## Carried forward

- no waiting phases

