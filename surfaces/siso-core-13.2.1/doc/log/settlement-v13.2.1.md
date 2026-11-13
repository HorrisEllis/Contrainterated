# Settlement v13.2.1

- date: 2026-10-20
- version: 13.2.0 → 13.2.1

## What changed

13.2.1 replaces 13.2.0, which was settled but not released. It is the
release that carries the Python port (settlement log v13.2.0).

**js-observer-seam (contracts phase).**
- THE REJECTION now requires one plain snapshot of the rejection per
  rejection, taken whether or not an observer is attached, with each
  observer receiving its own fresh copy of the snapshot.
- New probe at the end of `check.ownership`: a thrown object whose getter
  counts its reads must be read the same number of times with 0, 1 and 3
  observers, and every observer's copy must be equal.
- Fake-core switch `copyPerObserver`, with its case in
  `contract-observer_seam.test.mjs`.
- Why: the 13.2.0 audit found that the JavaScript port built each
  observer's copy separately. A getter or Proxy trap on what a gate threw
  ran once per observer (0, 1, 3 runs), so the gate could count the
  observers and observers could see unequal copies. That is checklist 12
  (architecture required), which the 13.1.0 audit had marked met. PHP was
  probed the same way and runs no gate code (0 runs).

**js (phase js-cause-snapshot).**
- `js/code/core/stream.mjs` takes the snapshot once per rejection. If it
  cannot be taken, no observer is called and the host's rejection is
  unchanged.
- `stream-rejection-copy.test.mjs` adds the getter and trap cases with
  0/1/3 observers, equal and separate copies, and a failing snapshot.

**Numbering (all instances, docs and logs).** The archive's `core13.zip`
(Lua only) numbers the release first settled as 13.0.1 as 13.0.0, and the
unreleased build before it as 13.0.0-rc1. This project now uses the same
numbers throughout:
- comments, test names and docs in all four instances;
- the logs `settlement-v13.0.0-rc1.md` and `settlement-v13.0.0.md`
  (renamed);
- the 13.1.0 log's "13.0.0 → 13.1.0".

The Lua reference here is byte-identical to `core13.zip`'s code except the
three fixture-path lines the move to `lua/` changed. One Python contract
line already used the new number; it was set to the old one before the
renumbering, so it reads correctly now.

**Issues.**
- Deleted (answered at 13.2.0): the Python ledger, the conformance
  column, M6's Python rules, and the rawset note covered by Y1.
- New: the Lua reference has the per-observer copy flaw through a thrown
  table's `__pairs` (ISSUES, X1's family). The Lua float-layout header
  comment is wrong (ISSUES, *Lua (comment only)*).

## Settled phases

### js-cause-snapshot
- in: js
- uses: js-observer-seam, js-core-api
- produces: js/code/core/stream.mjs, js/code/core/freeze.mjs, js/sisoTests/stream-rejection-copy.test.mjs
- check: node js/sisoTests/stream-rejection-copy.test.mjs && node js/sisoTests/stream-doors.test.mjs && node js/sisoTests/stream-ownership.test.mjs && node js/sisoTests/stream-observers-entry.test.mjs && node js/sisoTests/contract-observer_seam.test.mjs
- does: takes the rejection's plain snapshot once per rejection, whatever the number of observers (js-observer-seam THE REJECTION, 13.2.1)

## Results

Pasted from this settlement's run.

- phase js-cause-snapshot: contract observer-seam: 14/14 passed
- census: clean
- contract core-api: contract core-api: 14/14 passed
- contract observer-seam: contract observer-seam: 12/12 passed
- contract fixture-format: contract fixture-format: 5/5 passed
- contract kit-api: contract kit-api: 6/6 passed
- contract js-core-api: contract core-api: 22/22 passed
- contract js-observer-seam: contract observer-seam: 14/14 passed
- contract js-fixture-format: contract fixture-format: 6/6 passed
- contract js-kit-api: contract kit-api: 9/9 passed
- contract php-core-api: contract core-api: 18/18 passed
- contract php-observer-seam: contract observer-seam: 14/14 passed
- contract php-fixture-format: contract fixture-format: 7/7 passed
- contract php-kit-api: contract kit-api: 8/8 passed
- contract py-core-api: contract core-api: 32/32 passed
- contract py-observer-seam: contract observer-seam: 18/18 passed
- contract py-fixture-format: contract fixture-format: 6/6 passed
- contract py-kit-api: contract kit-api: 7/7 passed
- cd lua && lua5.4 code/kit/run.lua: run: 24 suites, 510/510 passed
- cd lua && lua5.4 code/kit/lint.lua: lint: 20 files, 0 findings, 0 excused, 0 stale exceptions
- cd lua && lua5.4 code/kit/census.lua: census: 0 gates, 0 tested, 0 untested
- cd lua && lua5.4 sisoTests/fixtures.test.lua: fixtures: 22/22 passed
- node js/code/kit/run.mjs: run: 21 suites, 380/380 passed
- node js/code/fixtures/run.mjs: fixtures: 15 files, 92/92 steps passed
- php php/code/kit/run.php: run: 21 suites, 371/371 passed
- php php/code/fixtures/run.php: fixtures: 15 files, 92/92 steps passed
- python3 -B py/code/siso/kit/run.py: run: 21 suites, 394/394 passed
- python3 -B py/code/siso/fixtures/run.py: fixtures: 15 files, 92/92 steps passed

## Carried forward

- no waiting phases

