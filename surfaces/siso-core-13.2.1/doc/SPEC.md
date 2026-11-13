# core

## Identity
- version-file: VERSION
- tests-dir: lua/sisoTests/
- import-roots: lua/code/, py/code/

SISO Canon, the reference core and its ports. It complies with
`doc/SISO-principles.md` (2.0); how each item is held, per instance, is
in `doc/conformance.md`.

Three instances share one version, one principles text and one
language-agnostic fixture suite (`fixtures/*.json`):

| Instance | Where | Substrate | Run from |
|---|---|---|---|
| Lua, the reference | `lua/code/`, `lua/sisoTests/` | Lua 5.4, standard library only | `lua/` |
| JavaScript | `js/code/`, `js/sisoTests/` | ESM (`.mjs`), Node 18 or later, no dependencies; the core imports nothing from `node:`. The copy-count and cost cases of `stream-freeze` and `stream-ownership` need Node 22.15 or later (`module.registerHooks`) and print "skipped" below it | the project root (one suite: anywhere) |
| PHP | `php/code/`, `php/sisoTests/` | PHP 8.1 or later (tested on 8.3), no dependencies, no Composer | the project root (one suite: anywhere) |
| Python | `py/code/` (package `siso`), `py/sisoTests/` | Python 3.10 or later (tested on 3.13), standard library only | the project root (one suite: anywhere) |

Every other language is ported from the Lua reference and checked
against the fixture suite (principles, *Instances*). A Lua test is
`lua5.4 sisoTests/<name>.test.lua` run from `lua/`; a JS test is
`node js/sisoTests/<name>.test.mjs`; a PHP test is
`php php/sisoTests/<name>.test.php`; a Python test is
`python3 -B py/sisoTests/<name>.test.py`. Each test's last line is
`{suite}: {passed}/{total} passed`.

## Purpose

The thinnest layer that supplies SISO's three primitives (Event, Gate,
Stream) and its doors, in each instance's language, plus what a project
in that language needs to hold the rules in its own code: an observer,
the data kit, the fixture runner and, for Lua, the gate-test kit. It has
no state, no I/O and no persistence: those belong to boundary layers
above it (jää, spine), each with its own ledger.

The JavaScript, PHP and Python ports carry the instance and the data kit: the
stream, the observer, the four contracts with their checks, `kit.data`
(canonical JSON), a suite runner, the harness and the fixture runner.
The Lua lint, census, per-gate helper and digest stay Lua tooling.

## Boundaries

### stream
- owns: lua/code/core/
- responsibility: the three primitives and the doors, in Lua

Event, Gate and Stream: registration, entry and rejection, take, sample,
attach and detach. Modules `core.event`, `core.gate`, `core.stream`.

### observer
- owns: lua/code/observer/
- responsibility: recording what the Lua stream shows an observer

`observer.streamlog`. Everything an observer keeps (entries, levels,
timestamps, stream labels and lineage) is observer-side and nothing in
the stream reads it.

### kit
- owns: lua/code/kit/
- responsibility: checking the gates any Lua SISO project writes

The per-gate helper, canonical data and JSON, the cross-process digest,
census, lint and suite runner. Lua projects vendor `lua/code/core/`,
`lua/code/kit/` and `lua/code/harness.lua`, hash-pinned.

### fixtures
- owns: fixtures/, lua/code/fixtures/
- responsibility: the behaviour every instance must reproduce

The fixture files (JSON), shared by every instance, and the Lua runner.

### principles
- owns: doc/SISO-principles.md
- responsibility: the rules every SISO core must meet

The user owns this text; its phase drafts and the user rules.

### js
- owns: js/code/core/, js/code/observer/, js/code/kit/, js/code/fixtures/, js/sisoTests/
- responsibility: the JavaScript instance of the core

The primitives and doors (`js/code/core/`: `Event`, `Gate`,
`Rejection`, `Stream`), the observer (`js/code/observer/streamlog.mjs`),
the data kit and suite runner (`js/code/kit/data.mjs`, `run.mjs`), the
fixture runner (`js/code/fixtures/run.mjs`), each ported from its Lua counterpart. One
boundary, because its parts use each other's implementations by design
(the kit's tests freeze real events, the fixture runner drives the real
core and decodes with the real kit), exactly as the Lua parts do; the
layering inside it is held by `js/sisoTests/layers.test.mjs` (ledger M6).

### php
- owns: php/code/core/, php/code/observer/, php/code/kit/, php/code/fixtures/, php/sisoTests/
- responsibility: the PHP instance of the core

The primitives and doors (`php/code/core/`: `Siso\Core\Event`, `Gate`,
`Rejection`, `Stream`), the observer (`Siso\Observer\StreamLog`), the
data kit and suite runner (`Siso\Kit\Data`, `Siso\Kit\Json`,
`php/code/kit/run.php`), and the fixture runner (`Siso\Fixtures\Runner`). One boundary, for the same reason as
`js`; the layering inside it is held by `php/sisoTests/layers.test.php`
(ledger M6).

### py
- owns: py/code/siso/core/, py/code/siso/observer/, py/code/siso/kit/, py/code/siso/fixtures/, py/code/siso/__init__.py, py/sisoTests/
- responsibility: the Python instance of the core

The primitives and doors (`siso.core`: `Event`, `Gate`, `Rejection`,
`Stream`), the observer (`siso.observer.streamlog`), the data kit and
suite runner (`siso.kit.data`, `siso/kit/run.py`) and the fixture runner
(`siso/fixtures/run.py`). One boundary, for the same reason as `js`; the
layering inside it is held by `py/sisoTests/layers.test.py` (ledger M6).

## Contracts

### core-api
- between: stream -> observer, kit, fixtures
- defined-in: lua/code/contracts/core_api.lua, lua/sisoTests/contract-core_api.test.lua, lua/sisoTests/contract-fake-core.lua
- test: cd lua && lua5.4 sisoTests/contract-core_api.test.lua

The modules, constructors and doors; per-propagation ids; the rejection
table and its codes; events read-only from construction; nothing
reachable from an event that holds state (getmetatable answers strings);
immutable `readonly:` sentinels; the stream's own copy at entry.
`check.doors`, `check.readonly` and `check.ownership` run it against an
implementation. The fake core exists only to show the checks can pass;
it is not a reference for `lua/code/core/`.

### observer-seam
- between: stream -> observer, fixtures
- defined-in: lua/code/contracts/observer_seam.lua, lua/sisoTests/contract-observer_seam.test.lua
- test: cd lua && lua5.4 sisoTests/contract-observer_seam.test.lua

One call per propagation, after it has ended, with a batch of records and
the outcome; observers isolated from the stream and from each other, each
with its own copy of the data and a plain copy of the rejection (no
metatables, nothing of the core's reachable). `check.seam` and
`check.ownership` run it against an implementation;
`rejection_copy_problem` judges one observer's rejection for any runner.

### fixture-format
- between: fixtures -> stream, observer, js, php, py
- defined-in: lua/code/contracts/fixture_format.lua, lua/sisoTests/contract-fixture_format.test.lua
- test: cd lua && lua5.4 sisoTests/contract-fixture_format.test.lua

The JSON format and its order-free matching: residue as a multiset,
causal traces as trees, a rejection's delivery named by its path of
types. The ports' runners read the same files under the same rules
(js-fixture-format, php-fixture-format).

### kit-api
- between: kit -> fixtures
- defined-in: lua/code/contracts/kit_api.lua, lua/sisoTests/contract-kit_api.test.lua
- test: cd lua && lua5.4 sisoTests/contract-kit_api.test.lua

What the Lua kit promises consumers here and in other projects:
`kit.data` (null and array marks, canonical JSON), `kit.sha256`,
`kit.helper`, and the census, lint and run commands.

### js-core-api
- between: stream -> js
- defined-in: js/code/contracts/core_api.mjs, js/sisoTests/contract-core_api.test.mjs, js/sisoTests/contract-fake-core.mjs
- test: node js/sisoTests/contract-core_api.test.mjs

core-api as the JavaScript instance holds it (the reference's
contract, carried into the port), with its SUBSTRATE section: frozen events with
an unforgeable private brand, data copied as it stands, plain data only,
private stream fields, `Rejection extends Error`. `check.doors`,
`check.readonly`, `check.ownership`.

### js-observer-seam
- between: stream, observer -> js
- defined-in: js/code/contracts/observer_seam.mjs, js/sisoTests/contract-observer_seam.test.mjs
- test: node js/sisoTests/contract-observer_seam.test.mjs

observer-seam in JavaScript: batches of the observer's own objects,
frozen record data, a frozen plain copy of the rejection.

### js-fixture-format
- between: fixtures -> js
- defined-in: js/code/contracts/fixture_format.mjs, js/sisoTests/contract-fixture_format.test.mjs
- test: node js/sisoTests/contract-fixture_format.test.mjs

The fixture-format matching rules in JavaScript, for the shared files.

### js-kit-api
- between: kit -> js
- defined-in: js/code/contracts/kit_api.mjs, js/sisoTests/contract-kit_api.test.mjs
- test: node js/sisoTests/contract-kit_api.test.mjs

`data` (canonical JSON in JavaScript) and the run command.

### php-core-api
- between: stream -> php
- defined-in: php/code/contracts/core_api.php, php/sisoTests/contract-core_api.test.php, php/sisoTests/contract-fake-core.php
- test: php php/sisoTests/contract-core_api.test.php

core-api as the PHP instance holds it, with its SUBSTRATE section: readonly final classes,
arrays as values, plain data checked at construction, enum cases as the
only sentinels, private stream properties, `Rejection extends
\RuntimeException`.

### php-observer-seam
- between: stream, observer -> php
- defined-in: php/code/contracts/observer_seam.php, php/sisoTests/contract-observer_seam.test.php
- test: php php/sisoTests/contract-observer_seam.test.php

observer-seam in PHP: batches, records and the rejection copy as arrays,
each observer's own by value.

### php-fixture-format
- between: fixtures -> php
- defined-in: php/code/contracts/fixture_format.php, php/sisoTests/contract-fixture_format.test.php
- test: php php/sisoTests/contract-fixture_format.test.php

The fixture-format matching rules in PHP, for the shared files.

### php-kit-api
- between: kit -> php
- defined-in: php/code/contracts/kit_api.php, php/sisoTests/contract-kit_api.test.php
- test: php php/sisoTests/contract-kit_api.test.php

`Siso\Kit\Data` (canonical JSON in PHP, `Json::EmptyArray`) and the run
command.

### py-core-api
- between: stream -> py
- defined-in: py/code/siso/contracts/core_api.py, py/code/siso/contracts/__init__.py, py/sisoTests/contract-core_api.test.py, py/sisoTests/contract-fake-core.py
- test: python3 -B py/sisoTests/contract-core_api.test.py

core-api as the Python instance holds it, with its SUBSTRATE section:
a final slotted `Event` with frozen data (`FrozenDict`, tuples), plain
data checked at construction, locked classes, stream state in closures,
`Rejection(Exception)`.

### py-observer-seam
- between: stream, observer -> py
- defined-in: py/code/siso/contracts/observer_seam.py, py/sisoTests/contract-observer_seam.test.py
- test: python3 -B py/sisoTests/contract-observer_seam.test.py

observer-seam in Python: batches and records as the observer's own
dicts, frozen record data, a frozen plain copy of the rejection.

### py-fixture-format
- between: fixtures -> py
- defined-in: py/code/siso/contracts/fixture_format.py, py/sisoTests/contract-fixture_format.test.py
- test: python3 -B py/sisoTests/contract-fixture_format.test.py

The fixture-format matching rules in Python, for the shared files.

### py-kit-api
- between: kit -> py
- defined-in: py/code/siso/contracts/kit_api.py, py/sisoTests/contract-kit_api.test.py
- test: python3 -B py/sisoTests/contract-kit_api.test.py

`siso.kit.data` (canonical JSON in Python) and the run command.

## Shared

### harness
- paths: lua/code/harness.lua
- reason: the test harness every Lua suite reports through (the port of runner.js); platform, not behaviour

### js-harness
- paths: js/code/harness.mjs
- reason: the test harness every JavaScript suite reports through, contract tests included (the port of harness.lua); platform, not behaviour

### py-harness
- paths: py/code/siso/harness.py
- reason: the test harness every Python suite reports through, contract tests included (the port of harness.lua); platform, not behaviour

### php-harness
- paths: php/code/harness.php
- reason: the test harness every PHP suite reports through, contract tests included (the port of harness.lua); platform, not behaviour

Each language's standard library is the other shared platform.

## Phases

A phase in `js`, `php` or `py` that carries a change from the reference reads
the Lua module and suites it ports, read-only (ledger M5), and ports the
suites too, case by case: a case
whose mechanism exists in the substrate is kept, a case whose mechanism
does not exist becomes the substrate's own attempt at the same violation
(the contract's SUBSTRATE section says which), and a case that cannot
apply is listed with its reason in the suite's header.

## Final conditions
- run: cd lua && lua5.4 code/kit/run.lua
- run: cd lua && lua5.4 code/kit/lint.lua
- run: cd lua && lua5.4 code/kit/census.lua
- run: cd lua && lua5.4 sisoTests/fixtures.test.lua
- run: node js/code/kit/run.mjs
- run: node js/code/fixtures/run.mjs
- run: php php/code/kit/run.php
- run: php php/code/fixtures/run.php
- run: python3 -B py/code/siso/kit/run.py
- run: python3 -B py/code/siso/fixtures/run.py

## Ledger

The core's own concessions are K1–K6 in the principles. Each instance's
entries cite what they bend. Cause is Substrate (the language forces it;
with a "vanishes when") or Instance (chosen; to be retired).

### Lua (the reference)

| ID | Concession | Bends | Cause | Thinnest form |
|---|---|---|---|---|
| R2 | The observer's state and door. Attached observers persist on the stream between propagations; an observer keeps what it is shown (`observer.streamlog`: entries, sequence, level, labels and lineage, per log). | K3 (attach *or detach*) | Instance | Attach and detach; each observer called once per propagation after it has ended, in protected mode, with its own batch; nothing in the stream reads what an observer keeps; no module-global state (pinned by `streamlog.test.lua`). |
| R3 | Purity by convention. A gate is a Lua function that can keep upvalues, reach globals, do I/O or raise, and `rawset` writes past a frozen table. | Rule 1 (statelessness, no side effects) | Substrate: Lua | Assignment to an event or anything in its data is refused by architecture from construction on (`core.event` freezes what it builds; the stream takes its own copy on entry and freezes on return, a no-op for a frozen event), and samples, observer records and an observer's rejection are their receivers' own frozen copies (core-api OWNERSHIP, observer-seam THE REJECTION), so nothing outside a propagation can reach the stream's objects. Since 13.0.0 nothing reachable from an event built of plain data holds state (getmetatable answers strings or `false`), and entry takes the stream's own copy. What remains convention is a gate's own code: keeping upvalues, reaching globals (the string metatable, reachable from any string, among them), effects (yielding a coroutine from inside a propagation among them: an exit that is not the return value, like a throw, which suspends the propagation), and `rawset` on what it is handed. The kit's lint flags `rawset` and global writes in pure layers (GLOBAL_READ, GLOBAL_WRITE) and the helper flags writes it can read back through the read surface; neither sees everything (their headers list the blind spots). *Vanishes when* the gate language cannot express state, effects or `rawset`. |
| R4 | Core 12 sentinels. A table whose metatable declares `__readonly = true` still passes through freezing (jää's `json.null` is one), and getmetatable on it hands out that metatable, a writable table reachable from any event that carries it. | Stateless (Rule 1, item 3) | Instance | Recognised for compatibility only; `kit.data.null` uses the string form "readonly:…", which hands out nothing. *Retired when* consumers move their sentinels to the string form. |
| R5 | The doors by convention for code that holds a stream. A stream is a Lua table: the outside that holds it can reach `residue`, `gates`, `gateCount` and `observers` directly, and every stream shares one metatable, bypassing the doors. | K3 (nothing else crosses) | Substrate: Lua | No gate is ever handed a stream, a queue or a residue list, so this binds only the outside (boundary and host code, trusted). The doors are the only documented interface. *Vanishes when* the substrate has private fields. |

### JavaScript

The full mapping from the reference's mechanisms is js-core-api's
SUBSTRATE section. Relative to the reference, R4 and R5 vanish (no
sentinels; private fields and a frozen instance), and so do R3's
`rawset` clause and X1's forged event, captured key and lying `__len`
(frozen objects, an unforgeable private brand, judge's own list).

| ID | Concession | Bends | Cause | Thinnest form |
|---|---|---|---|---|
| J1 | Purity by convention (R3's counterpart). A gate is a JavaScript function: it can keep closures and module state, reach `globalThis`, do I/O, start timers or async work, throw, and stamp its own private field on its input for the rest of one propagation. | Rule 1 (statelessness, no side effects) | Substrate: JavaScript | Every event and everything reachable from it is frozen from construction (there is no `rawset`); data is plain data or refused at construction (functions, class instances, symbol keys, accessors and non-enumerable properties included); entry, samples and observers receive fresh branded copies, so a stamp never reaches a later propagation; a throw is SISO_GATE_THREW and a Promise is SISO_NOT_EVENT. *Vanishes when* the gate language cannot express state or effects. |
| J2 | Realm intrinsics (`Object.prototype`, `Array.prototype`, `Function.prototype`, `globalThis`) are writable globals reachable from any value. A gate or host code that rewrites one writes a global; code that rewrites one before the core loads is handed whatever the core passes to it. | Rule 1 (stateless), K3 (nothing else crosses) | Substrate: JavaScript | The core holds no shared or writable object; the doors call built-ins captured at load and use plain loops on private state, so an intrinsic method rewritten after the core loaded never sees the stream's objects (stream-doors pins it); an index accessor installed on `Array.prototype` still sees the core's internal arrays when it writes a missing index (independent review); no check writes an intrinsic. *Vanishes when* the host freezes its intrinsics (e.g. SES `lockdown()`) before loading the core. |
| J3 | The observer's state and door (R2's counterpart). | K3 (attach *or detach*) | Instance | As R2; `StreamLog`, each log and each observer function are frozen and nothing is module-global (`streamlog.test.mjs`). |
| J4 | One number type: `1.0` is `1`. An integral number renders as digits in the safe range and in the reference's float form beyond it (`9007199254740992.0`). | Properties: Polyglot (plain data) | Substrate: JavaScript | Integers and non-integral floats render exactly as the reference renders them; only an integral float in the safe range differs (Lua `1.0`, JavaScript `1`). Fixture data is integers only. |
| J5 | Stack exhaustion. `emit` called with the host's stack nearly exhausted can throw the engine's `RangeError` before the stream can turn it into a rejection; a `RangeError` from the stack running out inside a gate's frame is that gate's SISO_GATE_THREW, and an entry the stream cannot copy for want of stack, however shallow, is SISO_NOT_EVENT. | Checklist 8 (only a rejection leaves) | Substrate: JavaScript | Every copy is iterative, so no depth of data overflows; only the host's own call depth remains. |
| J6 | Deceptive Proxies (X1's counterpart). A Proxy handed to the core is asked only for its own string keys and their values, each read once; what a lying Proxy answers is what is carried or refused. | Checklist 5, Drift 7 | Substrate: JavaScript; trusted code only | The stream queues exactly the members `judge` read; `Event.is` is never true for a Proxy; a revoked or trapping Proxy, returned or thrown, never escapes `emit` raw (independent review). |

### PHP

The full mapping is php-core-api's SUBSTRATE section. Relative to the
reference, R4 vanishes (enum cases are the sentinels), R5 thins to the
debug facilities (P2), and R3's `rawset` clause and X1's forged event,
captured key and lying `__len` vanish for ordinary code (readonly final
classes, `instanceof`, judge's own list).

| ID | Concession | Bends | Cause | Thinnest form |
|---|---|---|---|---|
| P1 | Purity by convention (R3's counterpart). A gate is a `\Closure`: it can keep `static` variables and `use (&$x)` captures, reach globals and `$GLOBALS`, do I/O, `exit`, raise warnings into an error handler, key a `\WeakMap` by its input, and keep objects whose destructors run later. | Rule 1 (statelessness, no side effects) | Substrate: PHP | Events are final readonly classes that refuse dynamic properties; data is checked plain at construction (scalars, arrays rebuilt without reference slots, Events, enum cases); entry, samples and observers receive fresh Event copies; arrays are values, so every batch, record and rejection copy is its observer's own by the language; what a gate returned is released inside the stream's protection. *Vanishes when* the gate language cannot express state or effects. |
| P2 | PHP's debug facilities (the counterpart of Lua's debug library). `debug_backtrace()` hands running code the arguments and `$this` of every frame on the stack (from a gate or an observer: the Stream, emit's `$this`); Reflection and closures bound into a class scope read and write private state and can build an Event whose data was never validated. | Rule 1 (no handle to the stream), K3, checklist 5 and 12 | Substrate: PHP; trusted code only | Ordinary code reaches the stream only through its doors (private properties); `emit` keeps the rejection, the observers list and the records in its own locals and calls observers from its own frame, so a backtrace shows none of them (independent review: an observer can no longer rewrite the host's rejection or call another observer that way). Not defended, as Lua's debug library is not (independent review). |
| P3 | Event types and gate signatures are strings. | K2 (types and the gate table) | Substrate: PHP (array keys) | `string`-typed constructor parameters; the reference accepts any value. |
| P4 | Exception plumbing. A rejection carries the substrate's file, line and trace (with arguments unless `zend.exception_ignore_args` is on). | K5 (the rejection carries nothing else) | Substrate: PHP | None of them is a public property, and none reaches an observer's copy; only the host holds them. |
| P5 | The observer's state and door (R2's counterpart). | K3 (attach *or detach*) | Instance | As R2; `StreamLog` holds no static state (`streamlog.test.php`). |
| P6 | JSON keys and empties. PHP turns decimal-integer string keys into ints, so an object whose keys are exactly `"0"…"n-1"` decodes as a list and re-encodes as an array; the empty JSON array needs a mark (`Json::EmptyArray`). | Properties: Polyglot (plain data) | Substrate: PHP | `Siso\Kit\Data` pins both (`kit-data.test.php`); the fixture format forbids empty arrays in event data. |
| P7 | Depth and memory. Arrays nested deeper than the C stack (about 150,000 levels) crash PHP when they are freed, wherever they were built, and exhausting `memory_limit` is fatal. | Checklist 8 (only a rejection leaves) | Substrate: PHP | The core's validation and copies are iterative; an entry the stream cannot copy is SISO_NOT_EVENT; the checks probe up to 60,000 levels. |
| P8 | A resource inside what a gate threw reaches each observer's rejection copy as the same handle, as a Lua userdata does. | observer-seam THE REJECTION | Substrate: PHP | Public properties are copied plain; a resource cannot be copied. |

### Python

The full mapping is py-core-api's SUBSTRATE section. Relative to the
reference, R4 vanishes (JSON null is `None`; no sentinels), R5 thins to
the debug facilities (Y2), and R3's `rawset` clause becomes Python's
own (`object.__setattr__`, Y1); X1's forged event, captured key and
lying `__len` vanish for ordinary code (a final slotted `Event`, no
proxy tables, exact `list`/`tuple` returns only, read once).

| ID | Concession | Bends | Cause | Thinnest form |
|---|---|---|---|---|
| Y1 | Purity by convention (R3's counterpart). A gate is a Python callable: it can keep closures and `nonlocal` state, reach module globals, do I/O, start threads or async work, and write past the locks with `object.__setattr__` or `type.__setattr__` (Python's `rawset`). | Rule 1 (statelessness, no side effects) | Substrate: Python | Events are final slotted objects that refuse assignment; data is copied at construction into `FrozenDict`, tuples and primitives (plain data of exact types only, odd keys refused); the core's classes are locked by a metaclass and none reachable from an event is an ABC; entry, samples and observers receive fresh copies; a throw is SISO_GATE_THREW, a generator or coroutine SISO_NOT_EVENT. *Vanishes when* the gate language cannot express state or effects. |
| Y2 | Python's debug facilities, reached by ordinary attributes. A generator's or coroutine's frame (`gi_frame.f_back.f_locals`, writable in 3.13) reaches `emit`'s locals, and so the stream's whole state, mid-run; dunder introspection (`__closure__`, `__globals__`), `gc`, `inspect`, `sys._getframe` and `ctypes` reach the stream's cells and a `FrozenDict`'s hidden dict. | Rule 1 (no handle to the stream), Rule 3, checklist 7, 8, 11 and 12 | Substrate: Python; trusted code only | The stream object itself has no attribute but its doors, and its state lives in closures, so only a frame (or the introspection above) reaches it; `emit` keeps the rejection and the observers list out of helper arguments. Not defended, as Lua's debug library (a global that needs no `require`) and PHP's `debug_backtrace` (P2) are not (independent review). *Vanishes when* gates run outside the host's interpreter (another process or an isolated interpreter). |
| Y3 | The lock's anchor. Pure Python cannot lock the core's metaclass: `type(type(e)).n = 1` writes it, and `type.__setattr__` on it replaces the lock itself. | Rule 1 (stateless) | Substrate: Python | The metaclass holds nothing the core reads besides the lock; the checks skip it. *Vanishes when* the core's classes are immutable types (a C extension, or immutable heap types in Python). |
| Y4 | Process-level exits. A `BaseException` that is not an `Exception` (`SystemExit`, `KeyboardInterrupt`, `GeneratorExit`), raised by a gate or by code of its objects that the stream calls, passes through `emit` unchanged. | Checklist 8 (only a rejection leaves) | Substrate: Python | Nothing is committed and the stream stays usable; the outcome is the same with or without observers, since the cause is snapshotted once per rejection (independent review). |
| Y5 | The recursion limit. `emit` called with the interpreter's recursion limit nearly reached can raise `RecursionError` before it can build a rejection; a `RecursionError` inside a gate's frame is that gate's SISO_GATE_THREW. | Checklist 8 | Substrate: Python | Every copy is iterative: data of any depth is accepted (10⁶ levels in the review). |
| Y6 | Unraisable exceptions. CPython prints and ignores an exception raised in `__del__` or in a collected generator's `finally` ("Exception ignored in", on stderr). | Without side effects (a note) | Substrate: Python | A returned coroutine is closed and any other returned object released under the stream's protection; nothing is ever raised out of `emit` that way. |
| Y7 | The observer's state and door (R2's counterpart). | K3 (attach *or detach*) | Instance | As R2; `StreamLog` holds no module or class state (`streamlog.test.py`). |
| Y8 | Event types and gate signatures are `str`. | K2 (types and the gate table) | Instance (as PHP's P3) | `str`-typed checks at construction; the reference accepts any value. |

### The method

| ID | Concession | Bends | Cause | Thinnest form |
|---|---|---|---|---|
| M4 | The phased-spec census reads Lua `dofile`/`loadfile`, not `require`, so it cannot see reach between the Lua boundaries. | the method's census | Substrate (tooling) | Held by the kit lint's layer rules (a final condition). The census does read the JavaScript ports' imports and the PHP ports' `require_once __DIR__ …`. |
| M5 | A port phase reads the Lua module and suites it ports, which belong to another boundary. | the method's M3 (reads by convention) | Instance | Read-only, named in the phase's declaration; the port's own contracts are what it implements against. *Retired when* no port is being made from the reference. |
| M6 | Each port is one boundary, so the census sees no layering inside it (the core reaching the kit, say). | the method's census | Instance | Held by each port's `layers` suite, the port of the Lua lint's LAYER rule: `core/` imports only `core/` and nothing from the host (`node:` in JavaScript; in Python only pure standard-library modules, the I/O and host modules listed in `layers.test.py` denied); `observer/` only `observer/` (Python's also `time`, its clock); `contracts/` nothing of the instance's code; `kit/` `kit/`, `core/`, `contracts/` and the harness; `fixtures/` all of those and `observer/`. *Retired when* the census reads layers inside a boundary. |

### Duplicates kept on purpose

| ID | Duplicate | Reason |
|---|---|---|
| D1 | Canonical rendering of data in `kit.canon` (kit) and `fixture_format.canon` (fixture-format contract). | Different jobs and strictness: the kit's is typed (an Event differs from a plain table, 1 from 1.0) for per-gate tests; the contract's is integers-only and must stand without the kit, so every instance's runner can apply the same matching rules. |
| D2 | A second implementation of the doors in each instance's `sisoTests/contract-fake-core.*` (its core-api contract). | It shows each contract check passing on something that honours the contract and failing on each departure it names. It is never a reference for that instance's core. |
| D3 | The contracts, the fixture matching rules, `kit.data` and the harness, once per language. | An instance stands alone in its language: its runner, checks and canonical JSON cannot call another language's. The Lua files are the reference; each port's header says where it departs and why. |

### Recorded departures

Rules an instance does not yet meet at their required strength, each
with its fix. Only the Lua reference has one; the ports hold its
mechanisms by architecture, and what deceptive trusted code can still do
there is named in J6, P2 and Y2. Each takes a metatable written to deceive, so ordinary gate
and boundary code never reaches it; deferring the fixes is the user's
call, and the security audit takes them up.

| ID | Departure | Rule | How it shows | Fix |
|---|---|---|---|---|
| X1 | (Lua) The stream reads what it is handed through Lua's operators, so a metatable that lies reaches past its judgement: a returned list whose `__len` disagrees with its members, a table whose `__metatable` answers the event metatable without being built by `Event`, a raised table whose `__index` raises when the rejection reads its `message` (`core.rejection`), and freeze's private key, which a table with a crafted `__index` can capture when freezing or `pairs`' iterator asks it for the key: the holder can then write past the refusal on any frozen table with no `rawset`, so the lint does not see it (no state: the key is a function and a view answers getmetatable false). | Checklist 5 (shape: architecture), 8 (only a rejection leaves: architecture); Drift 7 | `emit` raises a raw error instead of a rejection, carries fewer events than were listed, or carries an event with fields beyond `type` and `data`. | The stream queues exactly the members `judge` counted; `Event.is` recognises only what `Event` built or froze (not the metatable alone); `core.rejection` reads a thrown table's `message` raw and under protection; recognising proxies without asking foreign tables (a registry was declined: it is core state written on every freeze). |
</content>
</invoke>
