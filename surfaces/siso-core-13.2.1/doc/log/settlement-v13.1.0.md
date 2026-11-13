# Settlement v13.1.0

- date: 2026-10-20
- version: 13.0.0 → 13.1.0

## What changed

13.1.0 adds two faithful ports of the reference, JavaScript and PHP,
in the same project, and moves the Lua reference into `lua/`. The Lua
API and behaviour are unchanged. The user chose to port the instance
plus the data kit (not the Lua lint, census, per-gate helper or digest),
with native mechanisms: the same doors, codes, ids, delivery order,
observer seam, contract checks and fixtures, with immutability and
ownership held by each language's own tools.

**Layout (contracts phase).**
- `code/` → `lua/code/`, `sisoTests/` → `lua/sisoTests/`.
- `code/VERSION` → `VERSION` at the project root: one version for
  the three instances.
- `fixtures/*.json` stays at the root, shared by every instance.
- The Lua suites resolve their paths from `arg[0]`, so only three
  fixture paths changed: `lua/code/fixtures/run.lua`'s default directory
  and two suites (`fixtures.test.lua`, `fixtures-rejection-copy.test.lua`),
  from `root .. "fixtures"` to `root .. "../fixtures"`.
- The Lua final conditions now run as `cd lua && …`.
- Consumers that vendor `code/core/`, `code/kit/` and `code/harness.lua`
  re-point to `lua/…` (module names unchanged).

**Spec (contracts phase, then one reconciliation).**
- New boundaries `js` and `php` (one per port), and their contracts:
  js-/php-core-api, -observer-seam, -fixture-format, -kit-api.
- The ports' harnesses are shared platform.
- `fixture-format` now names `js` and `php` as parties, because their
  runners read the shared files.
- The first draft gave each port four boundaries mirroring the Lua ones.
  The census showed that wrong: the kit's tests freeze real events and
  the fixture runner drives the real core and decodes with the real kit,
  and the census cannot see the same coupling in Lua (M4). So each port
  became one boundary.
- Phase order moved into `needs`.
- New ledger: M5 (port phases read the reference, read-only) and M6 (each
  port's internal layering, held by a `layers` suite instead of the
  census).
- The JS and PHP ledgers (J1–J6, P1–P8) are written from the code and
  the reviews.

**js-core-api, js-observer-seam, js-fixture-format, js-kit-api
(`js/code/contracts/*.mjs`, contracts phase).**
- Full ports of the four Lua contracts, each with a SUBSTRATE section:
  - frozen events with a private-field brand;
  - data deep-copied as it stands, plain data only;
  - private stream fields;
  - `Rejection extends Error`;
  - frozen plain copies for observers.
- Every Lua check has a counterpart:
  - `rawset` probes become `Reflect.set`/`defineProperty` attempts plus a
    private-field stamp;
  - the counting probe becomes a structural check that only intrinsics
    are reachable;
  - depth probes run at 1,000 to 100,000 levels.
- A fake core (D2) with 12 switches, 5 of them from the reference's
  history.
- The contract tests are 22, 13, 6 and 9 cases.

**php-core-api, php-observer-seam, php-fixture-format, php-kit-api
(`php/code/contracts/*.php`).**
- The same for PHP:
  - readonly final classes;
  - arrays as values, with reference slots dropped at construction;
  - plain data checked at construction;
  - enum cases as the only sentinels;
  - private stream properties;
  - `Rejection extends \RuntimeException` with its seven public fields;
  - observer copies as arrays.
- A fake core with 19 switches, each its own subclass.
- The contract tests are 18, 14, 7 and 8 cases.

**js (`js/code/core/`, `observer/`, `kit/`, `fixtures/`, phases
js-stream-port, js-observer-port, js-kit-port, js-fixtures-port,
js-layers).**
- `Event`, `Gate`, `Rejection`, `Stream`, `freeze`; `StreamLog`;
  `data`/`run`; the fixture runner. The fixture-format contract test no
  longer reads the shared files; each fixtures suite checks their shape,
  as Lua's does.
- Every Lua suite is ported case by case. Each header maps the cases;
  `stream-doors` adds two cases for X1's fixes.
- X1's fixes are applied:
  - the stream queues exactly the members `judge` read;
  - a thrown value's `message` is read only as an own data property;
  - `Event.is` is a brand nothing else can forge.
- All copies are iterative.
- `layers.test.mjs` holds M6.

**php (phases php-stream-port, php-observer-port, php-kit-port,
php-fixtures-port, php-layers).**
- The same in PHP.
- Validation and copies run on explicit stacks, never recursion.
- Floats are rendered without depending on `serialize_precision`.
- `layers.test.php` tokenises every file and also checks that the pure
  layer has no static state, no `global` and no I/O or random calls.

**Independent review (a separate agent; 53 differential scenarios on
all three instances, 0 divergences, then principle probes).** These
were fixed through a contracts phase plus the three phases it reopened:
- PHP `emit` raised a raw exception when a gate's return threw on
  release, from a `__destruct` or a started generator's `finally`
  (checklist 8). The return is now released inside the stream's
  protection, and the judged rejection stands. The probe is in
  `CoreApi::checkDoors`.
- PHP observers could, through `debug_backtrace()` arguments, rewrite
  the host's rejection and call other observers. `emit` now keeps the
  rejection, the observer list and the records in locals and calls
  observers from its own frame. What `debug_backtrace`, Reflection and
  `Closure::bind` still reach (the Stream, then its private state) is
  recorded as P2, the counterpart of Lua's debug library. The contracts'
  claim that R5 "vanishes" was false and is corrected.
- JS: `Event` silently dropped symbol keys, and read accessors and
  non-enumerable properties through code (Drift 7). All three are now
  refused as not plain data.
- JS: a stream instance was extensible, so a door could be shadowed.
  The instance is now frozen at construction.
- JS: host code that patched `Array.prototype.map` received the private
  `#residue`. The doors now call built-ins captured at load and use plain
  loops. Index accessors on `Array.prototype` still reach internal arrays;
  that is recorded as J2, the intrinsics convention.
- JS kit: an integral number from 2^53 up to 1e21 encoded as digits
  that decode then refused. It now renders in the reference's float form
  (`9007199254740992.0`) and round-trips.

The second review pass confirmed each of these fixed or recorded, with
0 divergences.

**Landing notes.** The census reads import syntax as text, so it
rejected landings four times for false positives:
- usage examples in two doc comments;
- the import syntax in the two `layers` suites' inline samples;
- a working-directory `new URL("../../", import.meta.url)`.

Each was fixed in the phase. None needed a territory or Shared change.

**Issues.** Fixed and deleted: none of the earlier open items. New open
items are in `doc/ISSUES.md` under *JavaScript and PHP ports*. The Lua
streamlog header item (core 12 wording) was reported again by a port
phase; it stays one item.

## Settled phases

### js-stream-port
- in: js
- uses: js-core-api, js-observer-seam
- produces: js/code/core/event.mjs, js/code/core/gate.mjs, js/code/core/rejection.mjs, js/code/core/freeze.mjs, js/code/core/stream.mjs, js/code/core/index.mjs, js/sisoTests/core.test.mjs, js/sisoTests/stream-doors.test.mjs, js/sisoTests/stream-born-frozen.test.mjs, js/sisoTests/stream-freeze.test.mjs, js/sisoTests/stream-ownership.test.mjs, js/sisoTests/stream-rejection-copy.test.mjs, js/sisoTests/stream-stateless.test.mjs, js/sisoTests/stream-observers-entry.test.mjs
- check: node js/sisoTests/core.test.mjs && node js/sisoTests/stream-doors.test.mjs && node js/sisoTests/stream-born-frozen.test.mjs && node js/sisoTests/stream-freeze.test.mjs && node js/sisoTests/stream-ownership.test.mjs && node js/sisoTests/stream-rejection-copy.test.mjs && node js/sisoTests/stream-stateless.test.mjs && node js/sisoTests/stream-observers-entry.test.mjs
- does: ports lua/code/core/ and its suites (core, stream-*) to JavaScript under js-core-api and js-observer-seam

### js-observer-port
- in: js
- uses: js-observer-seam, js-core-api
- produces: js/code/observer/streamlog.mjs, js/sisoTests/streamlog.test.mjs
- check: node js/sisoTests/streamlog.test.mjs
- does: ports lua/code/observer/streamlog.lua and streamlog.test.lua to JavaScript, driving the fake core

### js-kit-port
- in: js
- needs: js/code/core/event.mjs, js/code/core/stream.mjs
- uses: js-kit-api, js-core-api
- produces: js/code/kit/data.mjs, js/code/kit/run.mjs, js/sisoTests/kit-data.test.mjs, js/sisoTests/kit-data-frozen.test.mjs, js/sisoTests/kit-data-stateless.test.mjs, js/sisoTests/kit-run.test.mjs, js/sisoTests/kit-fixtures/
- check: node js/sisoTests/kit-data.test.mjs && node js/sisoTests/kit-data-frozen.test.mjs && node js/sisoTests/kit-data-stateless.test.mjs && node js/sisoTests/kit-run.test.mjs
- does: ports lua/code/kit/data.lua (canonical JSON) and the suite half of run.lua to JavaScript, with their suites

### js-fixtures-port
- in: js
- needs: js/code/core/stream.mjs, js/code/kit/data.mjs
- uses: fixture-format, js-fixture-format, js-core-api, js-observer-seam, js-kit-api
- produces: js/code/fixtures/run.mjs, js/sisoTests/fixtures.test.mjs, js/sisoTests/fixtures-marks.test.mjs, js/sisoTests/fixtures-rejection-copy.test.mjs
- check: node js/sisoTests/fixtures.test.mjs && node js/sisoTests/fixtures-marks.test.mjs && node js/sisoTests/fixtures-rejection-copy.test.mjs && node js/code/fixtures/run.mjs
- does: ports lua/code/fixtures/run.lua and the fixtures suites to JavaScript; the shared fixtures pass on the JavaScript core

### php-stream-port
- in: php
- uses: php-core-api, php-observer-seam
- produces: php/code/core/event.php, php/code/core/gate.php, php/code/core/rejection.php, php/code/core/freeze.php, php/code/core/stream.php, php/sisoTests/core.test.php, php/sisoTests/stream-doors.test.php, php/sisoTests/stream-born-frozen.test.php, php/sisoTests/stream-freeze.test.php, php/sisoTests/stream-ownership.test.php, php/sisoTests/stream-rejection-copy.test.php, php/sisoTests/stream-stateless.test.php, php/sisoTests/stream-observers-entry.test.php
- check: php php/sisoTests/core.test.php && php php/sisoTests/stream-doors.test.php && php php/sisoTests/stream-born-frozen.test.php && php php/sisoTests/stream-freeze.test.php && php php/sisoTests/stream-ownership.test.php && php php/sisoTests/stream-rejection-copy.test.php && php php/sisoTests/stream-stateless.test.php && php php/sisoTests/stream-observers-entry.test.php
- does: ports lua/code/core/ and its suites (core, stream-*) to PHP under php-core-api and php-observer-seam

### php-observer-port
- in: php
- uses: php-observer-seam, php-core-api
- produces: php/code/observer/streamlog.php, php/sisoTests/streamlog.test.php
- check: php php/sisoTests/streamlog.test.php
- does: ports lua/code/observer/streamlog.lua and streamlog.test.lua to PHP, driving the fake core

### php-kit-port
- in: php
- needs: php/code/core/event.php, php/code/core/stream.php
- uses: php-kit-api, php-core-api
- produces: php/code/kit/data.php, php/code/kit/run.php, php/sisoTests/kit-data.test.php, php/sisoTests/kit-data-frozen.test.php, php/sisoTests/kit-data-stateless.test.php, php/sisoTests/kit-run.test.php, php/sisoTests/kit-fixtures/
- check: php php/sisoTests/kit-data.test.php && php php/sisoTests/kit-data-frozen.test.php && php php/sisoTests/kit-data-stateless.test.php && php php/sisoTests/kit-run.test.php
- does: ports lua/code/kit/data.lua (canonical JSON) and the suite half of run.lua to PHP, with their suites

### php-fixtures-port
- in: php
- needs: php/code/core/stream.php, php/code/kit/data.php
- uses: fixture-format, php-fixture-format, php-core-api, php-observer-seam, php-kit-api
- produces: php/code/fixtures/run.php, php/sisoTests/fixtures.test.php, php/sisoTests/fixtures-marks.test.php, php/sisoTests/fixtures-rejection-copy.test.php
- check: php php/sisoTests/fixtures.test.php && php php/sisoTests/fixtures-marks.test.php && php php/sisoTests/fixtures-rejection-copy.test.php && php php/code/fixtures/run.php
- does: ports lua/code/fixtures/run.lua and the fixtures suites to PHP; the shared fixtures pass on the PHP core

### js-layers
- in: js
- uses: none
- needs: js/code/core/stream.mjs, js/code/observer/streamlog.mjs, js/code/kit/data.mjs, js/code/fixtures/run.mjs
- produces: js/sisoTests/layers.test.mjs
- check: node js/sisoTests/layers.test.mjs
- does: holds the JavaScript instance's layering (the port of the Lua lint's LAYER and EXTERNAL_REQUIRE rules) as a suite

### php-layers
- in: php
- uses: none
- needs: php/code/core/stream.php, php/code/observer/streamlog.php, php/code/kit/data.php, php/code/fixtures/run.php
- produces: php/sisoTests/layers.test.php
- check: php php/sisoTests/layers.test.php
- does: holds the PHP instance's layering (the port of the Lua lint's LAYER and EXTERNAL_REQUIRE rules) as a suite

## Results

Pasted from this settlement's run.

- phase js-stream-port: stream-observers-entry: 9/9 passed
- phase js-observer-port: streamlog: 34/34 passed
- phase js-kit-port: kit-run: 11/11 passed
- phase js-fixtures-port: fixtures: 15 files, 92/92 steps passed
- phase php-stream-port: stream-observers-entry: 9/9 passed
- phase php-observer-port: streamlog: 34/34 passed
- phase php-kit-port: kit-run: 12/12 passed
- phase php-fixtures-port: fixtures: 15 files, 92/92 steps passed
- phase js-layers: layers: 12/12 passed
- phase php-layers: layers: 10/10 passed
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
- cd lua && lua5.4 code/kit/run.lua: run: 24 suites, 510/510 passed
- cd lua && lua5.4 code/kit/lint.lua: lint: 20 files, 0 findings, 0 excused, 0 stale exceptions
- cd lua && lua5.4 code/kit/census.lua: census: 0 gates, 0 tested, 0 untested
- cd lua && lua5.4 sisoTests/fixtures.test.lua: fixtures: 22/22 passed
- node js/code/kit/run.mjs: run: 21 suites, 376/376 passed
- node js/code/fixtures/run.mjs: fixtures: 15 files, 92/92 steps passed
- php php/code/kit/run.php: run: 21 suites, 371/371 passed
- php php/code/fixtures/run.php: fixtures: 15 files, 92/92 steps passed

## Carried forward

- no waiting phases

