# Settlement v13.2.0

- date: 2026-10-20
- version: 13.1.0 → 13.2.0

## What changed

13.2.0 adds a fourth instance, a faithful Python port, beside the Lua
reference and the JavaScript and PHP ports. The user's choices were the
same as for 13.1.0: the instance plus the data kit, with native
mechanisms. It was settled but not released: the audit that followed
found a seam flaw in the JavaScript port, which 13.2.1 fixes (settlement
log v13.2.1).

**Spec (contracts phase).**
- New boundary `py`. Its territory is `py/code/siso/{core,observer,kit,fixtures}/`,
  `siso/__init__.py` and `py/sisoTests/`.
- `py/code/` is added to `import-roots`, so the census resolves
  `from siso.… import`.
- Contracts py-core-api, py-observer-seam, py-fixture-format and py-kit-api.
  `fixture-format` names `py` as a party.
- Shared `py-harness`.
- Five phases, ordered by `needs`.
- Final conditions `python3 -B py/code/siso/kit/run.py` and
  `python3 -B py/code/siso/fixtures/run.py`.
- Ledger Y1–Y8; M6 now names Python's layering rules.

**py-core-api, py-observer-seam, py-fixture-format, py-kit-api
(`py/code/siso/contracts/*.py`).**
- Full ports with SUBSTRATE sections:
  - a final slotted `Event`;
  - data copied iteratively into `FrozenDict`, tuples and primitives;
  - plain data of exact types only;
  - core classes locked by a metaclass;
  - stream state in closures;
  - `Rejection(Exception)`;
  - the reference's names for doors and fields;
  - `Event.isEvent` and `Rejection.isRejection`, because `is` is a Python
    keyword.
- A fake core with 33 switches.
- The contract tests are 32, 18, 6 and 7 cases.
- They are written against the traps the 13.1.0 review found in the other
  ports, from the start.

**py (phases py-stream-port, py-observer-port, py-kit-port,
py-fixtures-port, py-layers).**
- `siso.core`, `siso.observer.streamlog`, `siso.kit.data` and `run`, and
  `siso.fixtures.run`. Every Lua suite is ported case by case.
- The fixture runner's output is byte-identical to the Lua runner's.
- `siso.kit.data` matches the Lua kit byte for byte: 64,539 floats,
  3,000 random nested values, 79 decode edge cases.
- `layers.test.py` reads imports with `ast`. It also checks that the
  pure layer has no module state and calls no I/O functions.

**Bytecode.** The first Python landings were rejected because
`__pycache__` appeared outside their territory. The fix:
- every Python command in the spec runs as `python3 -B`;
- every suite sets `sys.dont_write_bytecode` before importing `siso`;
- the runner starts its children with `-B`;
- the project tree holds no `__pycache__`.

**Independent review (a fresh agent; 72 differential scenarios on all
four instances).** One Python divergence, explained by its SUBSTRATE
mapping (a list subclass is refused before its members are judged).
Fixed through a contracts phase plus a reopened py-stream-port:
- gate code ran once per observer while the rejection's cause was copied,
  so observer count leaked, copies were unequal and checklist 12 broke.
  The cause is now snapshotted once per rejection, with or without
  observers. The probe is in `checkOwnership` (observer-seam);
- `FrozenDict` was an ABC, and `super(...).register` from a gate carried
  a counter across streams. It is no longer an ABC.

Recorded rather than fixed, with the false claims corrected:
- a generator's `gi_frame.f_back.f_locals` reaches and writes `emit`'s
  locals (Y2, the counterpart of Lua's `debug` global and PHP's P2);
- the unlockable metaclass (Y3);
- process-level exits (Y4);
- the recursion limit (Y5).

The second pass confirmed each of these.

**Found while auditing:**
- the JavaScript port has the same per-observer copy flaw, through a
  getter on the thrown object (fixed in 13.2.1);
- the Lua reference has it through a thrown table's `__pairs`, which is
  X1's family (ISSUES);
- `lua/code/kit/data.lua`'s header says decimal floats start at 1e-7;
  the code, ECMAScript and all four kits use 1e-6 (ISSUES, comment only).

**Issues.** No earlier open item was fixed. The Python follow-ups about
the ledger, M6 and the audit are answered by this settlement and deleted.

## Settled phases

### py-stream-port
- in: py
- uses: py-core-api, py-observer-seam
- produces: py/code/siso/__init__.py, py/code/siso/core/__init__.py, py/code/siso/core/event.py, py/code/siso/core/gate.py, py/code/siso/core/rejection.py, py/code/siso/core/freeze.py, py/code/siso/core/stream.py, py/sisoTests/core.test.py, py/sisoTests/stream-doors.test.py, py/sisoTests/stream-born-frozen.test.py, py/sisoTests/stream-freeze.test.py, py/sisoTests/stream-ownership.test.py, py/sisoTests/stream-rejection-copy.test.py, py/sisoTests/stream-stateless.test.py, py/sisoTests/stream-observers-entry.test.py
- check: python3 -B py/sisoTests/core.test.py && python3 -B py/sisoTests/stream-doors.test.py && python3 -B py/sisoTests/stream-born-frozen.test.py && python3 -B py/sisoTests/stream-freeze.test.py && python3 -B py/sisoTests/stream-ownership.test.py && python3 -B py/sisoTests/stream-rejection-copy.test.py && python3 -B py/sisoTests/stream-stateless.test.py && python3 -B py/sisoTests/stream-observers-entry.test.py
- does: ports lua/code/core/ and its suites (core, stream-*) to Python under py-core-api and py-observer-seam

### py-observer-port
- in: py
- uses: py-observer-seam, py-core-api
- produces: py/code/siso/observer/__init__.py, py/code/siso/observer/streamlog.py, py/sisoTests/streamlog.test.py
- check: python3 -B py/sisoTests/streamlog.test.py
- does: ports lua/code/observer/streamlog.lua and streamlog.test.lua to Python, driving the fake core

### py-kit-port
- in: py
- needs: py/code/siso/core/event.py, py/code/siso/core/stream.py
- uses: py-kit-api, py-core-api
- produces: py/code/siso/kit/__init__.py, py/code/siso/kit/data.py, py/code/siso/kit/run.py, py/sisoTests/kit-data.test.py, py/sisoTests/kit-data-frozen.test.py, py/sisoTests/kit-data-stateless.test.py, py/sisoTests/kit-run.test.py, py/sisoTests/kit-fixtures/
- check: python3 -B py/sisoTests/kit-data.test.py && python3 -B py/sisoTests/kit-data-frozen.test.py && python3 -B py/sisoTests/kit-data-stateless.test.py && python3 -B py/sisoTests/kit-run.test.py
- does: ports lua/code/kit/data.lua (canonical JSON) and the suite half of run.lua to Python, with their suites

### py-fixtures-port
- in: py
- needs: py/code/siso/core/stream.py, py/code/siso/kit/data.py
- uses: fixture-format, py-fixture-format, py-core-api, py-observer-seam, py-kit-api
- produces: py/code/siso/fixtures/__init__.py, py/code/siso/fixtures/run.py, py/sisoTests/fixtures.test.py, py/sisoTests/fixtures-marks.test.py, py/sisoTests/fixtures-rejection-copy.test.py
- check: python3 -B py/sisoTests/fixtures.test.py && python3 -B py/sisoTests/fixtures-marks.test.py && python3 -B py/sisoTests/fixtures-rejection-copy.test.py && python3 -B py/code/siso/fixtures/run.py
- does: ports lua/code/fixtures/run.lua and the fixtures suites to Python; the shared fixtures pass on the Python core

### py-layers
- in: py
- uses: none
- needs: py/code/siso/core/stream.py, py/code/siso/observer/streamlog.py, py/code/siso/kit/data.py, py/code/siso/fixtures/run.py
- produces: py/sisoTests/layers.test.py
- check: python3 -B py/sisoTests/layers.test.py
- does: holds the Python instance's layering (the port of the Lua lint's LAYER and EXTERNAL_REQUIRE rules, read with ast) as a suite

## Results

Pasted from this settlement's run.

- phase py-stream-port: stream-observers-entry: 9/9 passed
- phase py-observer-port: streamlog: 39/39 passed
- phase py-kit-port: kit-run: 12/12 passed
- phase py-fixtures-port: fixtures: 15 files, 92/92 steps passed
- phase py-layers: layers: 17/17 passed
- census: clean
- contract core-api: contract core-api: 14/14 passed
- contract observer-seam: contract observer-seam: 12/12 passed
- contract fixture-format: contract fixture-format: 5/5 passed
- contract kit-api: contract kit-api: 6/6 passed
- contract js-core-api: contract core-api: 22/22 passed
- contract js-observer-seam: contract observer-seam: 13/13 passed
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
- node js/code/kit/run.mjs: run: 21 suites, 376/376 passed
- node js/code/fixtures/run.mjs: fixtures: 15 files, 92/92 steps passed
- php php/code/kit/run.php: run: 21 suites, 371/371 passed
- php php/code/fixtures/run.php: fixtures: 15 files, 92/92 steps passed
- python3 -B py/code/siso/kit/run.py: run: 21 suites, 394/394 passed
- python3 -B py/code/siso/fixtures/run.py: fixtures: 15 files, 92/92 steps passed

## Carried forward

- no waiting phases

