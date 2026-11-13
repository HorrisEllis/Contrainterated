# Conformance Audit — core

**Project version:** 13.2.1
**Audited against:** `doc/SISO-principles.md` **2.0**, read in full this session. Each instance's own ledger is in `doc/SPEC.md` (*Ledger*). There is no SPINE-principles or COMPONENT-SPEC, because the core is a library, not a component.
**Instances:**
- the Lua 5.4 reference (`lua/code/core/`, standard library only);
- the JavaScript port (`js/code/core/`: ESM, Node 22 in this run, the core imports nothing from `node:`);
- the PHP port (`php/code/core/`: PHP 8.3 in this run);
- the Python port (`py/code/siso/core/`: Python 3.13 in this run, standard library only; its contract tests also pass on 3.10, 3.11 and 3.12).
**Audit date:** 2026-10-20
**Suites (pasted from this settlement's run):**
- **Lua:** `run: 24 suites, 510/510 passed`; `fixtures: 22/22 passed`; `lint: 20 files, 0 findings, 0 excused, 0 stale exceptions`; `census: 0 gates, 0 tested, 0 untested`.
- **JavaScript:** `run: 21 suites, 380/380 passed`.
- **PHP:** `run: 21 suites, 371/371 passed`.
- **Python:** `run: 21 suites, 394/394 passed`.
- **Shared fixtures:** each of the four runners: `fixtures: 15 files, 92/92 steps passed`.
**Reviews:** independent agents, which had not seen the work, ran differential scenarios on every instance and probed each port's "by architecture" claims, writing their scripts outside the project. Each found issue was fixed or recorded, then confirmed by a second pass:
- **JavaScript and PHP, at 13.1.0:** 53 scenarios, 0 divergences; three faults and three overstated claims.
- **Python, at 13.2.0:** 72 scenarios on all four instances. One Python divergence, explained by its SUBSTRATE mapping; three faults, recorded conventions and false claims.
- **JavaScript again, at 13.2.0 (audit):** the per-observer copy flaw, fixed in 13.2.1.
**Lua code:** byte-identical to the archive's `core13.zip` (13.0.0) except the three fixture-path lines the move to `lua/` changed. The Lua probe results are from the 13.0.0 audit, re-checked by this run's suites and by the differential scenarios. The per-observer `__pairs` probe was run this session.

IDs:
- **K:** the core's concessions (principles).
- **R, X:** the Lua reference's concessions and recorded departure.
- **J, P, Y:** the JavaScript, PHP and Python ports' concessions.
- **M:** the method's concessions.
- **D:** duplicates kept on purpose.

**Threat model** (all four instances): outside input reaches the core only as data, and data cannot carry behaviour. *Honest mistakes* the core makes loud. *Deception or debugging by trusted code* is named and not defended with machinery, by the user's decision: Lua's lying metatables and `debug` library, JavaScript's rewritten intrinsics and lying Proxies, PHP's `debug_backtrace`, Reflection and bound closures, and Python's frames, `object.__setattr__` and introspection. The user has noted that runtime architecture against malicious gates is a later concern. In every instance it would mean running gates outside the host's memory.

---

## Verdict

- **Lua (reference): not fully compliant. 12 of 15 items are met at their required strength.** Items 5, 8 and 12 are met except for recorded departure X1 (deceptive metatables). Item 12 is new this audit: a thrown table's `__pairs` runs once per attached observer while the rejection is copied (ISSUES).
- **JavaScript: compliant, 15 of 15** since 13.2.1. Item 12 had been marked met at 13.1.0 while a getter on a thrown object ran once per observer. The cause is now snapshotted once per rejection. Concessions: J1–J6.
- **PHP: compliant, 15 of 15,** with the same reading the reference applies to its debug library (P2). Probed this session for the per-observer copy flaw: no gate code runs (0 runs with 0, 1 and 3 observers).
- **Python: compliant, 15 of 15,** with the same reading. Python's debug facilities are reachable by ordinary attributes (Y2): a generator's `gi_frame.f_back.f_locals` reaches and writes `emit`'s locals mid-run. Like Lua's `debug` global and PHP's P2, that is held as convention for items the principles mark "architecture required" (2, 7, 8, 11, 12). That is a policy reading the reviewer flagged, and it rests on those precedents.

---

## SISO-principles 2.0 — compliance checklist

| # | Item | Required | Lua | JavaScript | PHP | Python |
|---|---|---|---|---|---|---|
| 1 | Exactly three primitives. Everything else is in a ledger. | Inspection | Met | Met | Met | Met |
| 2 | A gate receives one event and nothing else, and returns events. | Architecture | Met | Met | Met (P2) | Met (Y2) |
| 3 | No gate has state or side effects (throwing included). | Architecture, or a recorded convention | Met (R3) | Met (J1, J2) | Met (P1, P2) | Met (Y1, Y2, Y3) |
| 4 | Every gate has a single logical effect. | Architecture, or a recorded convention | Met (vacuous) | Met (vacuous) | Met (vacuous) | Met (vacuous) |
| 5 | An event is a type and data, carries what its gate requires, and is never annotated by delivery. | Architecture (shape, annotation); convention (sizing) | Met except X1 | Met | Met (P2) | Met (Y1) |
| 6 | No gate depends on execution order. | Architecture | Met | Met | Met | Met |
| 7 | No causal chain revisits a type; self-loops and cycles are rejected. | Architecture | Met | Met | Met | Met (Y2) |
| 8 | A rejected propagation commits no residue and leaves only as a rejection through the entry door. | Architecture | Met except X1 | Met (J5) | Met (P7) | Met (Y2, Y4, Y5) |
| 9 | Present-tense questions are answered by sampling, never from history. | Inspection | Met | Met | Met | Met |
| 10 | Sampling is local, non-destructive and proportional to the present, and nothing done to a sample reaches the stream. | Architecture; architecture or a recorded convention for read-only samples | Met | Met | Met | Met |
| 11 | Answers leave only by being taken. | Architecture | Met (R5) | Met (J2) | Met (P2) | Met (Y2) |
| 12 | Attaching or removing every observer leaves behaviour unchanged. | Architecture | Met except X1 | Met (since 13.2.1) | Met (P2) | Met (Y2) |
| 13 | No orchestration. | Inspection | Met | Met | Met | Met |
| 14 | Ticks, if present, are bookkeeping only. | Inspection | Met | Met | Met | Met |
| 15 | Every mechanism outside the primitives is in the core's ledger or the instance's, in its thinnest form or marked as not yet, or is a recorded departure. | Inspection | Met | Met | Met | Met |

### Evidence

The Lua, JavaScript and PHP evidence for items 1–11 and 13–15 is as in the 13.1.0 audit (settlement logs v13.1.0, v13.2.1). Their code is unchanged except JavaScript's `stream.mjs` (item 12). This run's counts: JS `stream-doors` 22/22, `stream-ownership` 15/15, `stream-rejection-copy` 10/10; PHP `stream-doors` 20/20, `stream-ownership` 15/15. What follows is the Python evidence, then item 12 for all four.

**Python, items 1–11 and 13–15.**
1. **Three primitives.** `siso/core/` holds `event.py` (re-exporting `Event` from `freeze.py`, where the copying lives), `gate.py`, `rejection.py` (K5), `freeze.py` (`FrozenDict` and the copies) and `stream.py`. The stream's state is in closures, and the stream object has only its six doors.
2. **One argument.** `gate.transform(event)` with one positional argument (`checkDoors`). Y2: a frame reaches emit's.
3. **Stateless, no side effects.**
   - Assignment to an event, its data, a gate or a core class is refused.
   - The count probe sees 0 on a second stream through everything an event reaches by ordinary access: class attributes, and, since 13.2.0, the ABC registry by `register` and by `ABCMeta.register` (`checkReadonly`; `stream-stateless` 14/14).
   - A throw is SISO_GATE_THREW; a generator or coroutine is SISO_NOT_EVENT, and the stream closes it.
   - What remains: Y1 (`object.__setattr__`, closures, I/O), Y2 (frames, introspection) and Y3 (the metaclass).
5. **Event shape.** A final slotted `Event` with exactly `type` and `data`; `Event.isEvent` is `isinstance` on a final class. An `object.__new__(Event)` forgery is SISO_NOT_EVENT, never a raw error. Delivery metadata rides on queue entries.
6. **Order.** Covered by the fixtures (92/92), the differential scenarios (residue multisets and causal forests equal to Lua's) and the identical fixture-runner output.
7. **Acyclicity.** SISO_SELF_LOOP and SISO_CYCLE: fixtures 08, 09, 10 and 14; `checkDoors` pins the 2-cycle at eventId 3, causeId 2, depth 3. Y2: a gate that rewrites `gateCount` through a frame can let a cycle settle (review), which is convention.
8. **Only a rejection leaves.**
   - Every copy is iterative: data 10⁶ deep was accepted in review.
   - Lying list subclasses, generators, coroutines, and objects whose `__del__`, `__eq__` or `__hash__` raises are SISO_NOT_EVENT (review).
   - What remains: Y4 (process-level exits pass through, identically with or without observers), Y5 (the recursion limit) and Y2.
10. **Sampling.** Fresh frozen copies (`checkOwnership`; `stream-ownership` 15/15).
11. **Answers only by take.** `takeResidue` is the only door that removes residue, and entry takes the stream's own copy (`checkOwnership`). Y2: a frame reaches the residue list.
13–15. No orchestration and no ticks. The ledger is Y1–Y8.

**Item 12, observers, all four.**
- **The common check:** every batch is built before any observer is called, and each observer's records are its own copies (`check.seam` and `check.ownership` in each language).
- **The cause snapshot.** A gate's thrown value whose reading runs gate code (a getter in JavaScript, a `__dict__` property in Python, `IteratorAggregate` in PHP, `__pairs` in Lua) was probed with 0, 1 and 3 observers:
  - **Lua:** 0, 1, 3 runs. Gate code runs per observer (X1's family; ISSUES).
  - **JavaScript:** 0, 0, 0 since 13.2.1 (it was 0, 1, 3). The snapshot is taken once per rejection; the probe is at the end of `check.ownership`, and `stream-rejection-copy` 10/10.
  - **PHP:** 0, 0, 0. The copy reads public properties only.
  - **Python:** the same count with any number of observers since 13.2.0. The snapshot is taken once per rejection (`checkOwnership`; `stream-rejection-copy` 10/10).
- **Y2 (Python):** an observer reaching `emit`'s frame can blank another observer's batch or cancel the rejection (review). That is convention, as P2 is in PHP.

---

## Ledger audit

| In the code | Maps to | Matches the ledger text? |
|---|---|---|
| Lua: as in 13.0.0 | K1–K6, R2–R5, X1 | Yes. Unchanged code; X1's family now includes per-observer `__pairs` (ISSUES). |
| JS: as in 13.1.0, plus the per-rejection snapshot in `stream.mjs` | J1–J6; observer-seam THE REJECTION | Yes (`check.ownership`, `stream-rejection-copy`). |
| PHP: as in 13.1.0 | P1–P8 | Yes. |
| Python `Event`, `FrozenDict` (no ABC, registered with `Mapping`), locked classes, plain-data check | Event; Y1, Y3 | Yes (`checkReadonly`, `stream-born-frozen` 11/11, `stream-stateless` 14/14). |
| Python `Stream`: closure-held state, locked instance, FIFO queue, release and closing of returns under protection, one cause snapshot per rejection | K1–K5; Y2, Y4, Y5, Y6 | Yes (`checkDoors`, `checkOwnership`, `stream-doors` 18/18). |
| Python `Rejection(Exception)`, final, seven attributes | K5 | Yes (`rejectionProblems`). |
| Python `StreamLog`, `siso.kit.data` | Y7; canonical JSON identical to Lua's | Yes (`streamlog` 39/39, `kit-data` 61/61). |
| Types and signatures typed `str` | Y8 (and P3) | Yes. |

No mechanism was found outside the ledgers.

---

## Drift-pattern audit (all four instances)

- **Drift 1** (the log consulted): absent.
- **Drift 2** (registration order as scheduling): absent.
- **Drift 3** (orchestration): absent.
- **Drift 4** (gates reaching outside): R3; J1, J2; P1, P2; Y1, Y2.
- **Drift 5** (speculative payload): nothing can be added to an event after construction in any instance.
- **Drift 6** (a fourth primitive): absent.
- **Drift 7** (guard-rails): Lua X1's lying `__len`. In Python, odd keys, dict subclasses and list subclasses are refused, not dropped.
- **Drift 8** (the unnamed concession): the Python review's recorded findings are named (Y2–Y6), and the false contract claims are corrected. The per-observer copy behaviour found in the audit is fixed (JS, Python), absent (PHP) or named (Lua, ISSUES).

---

## The method's items (phased-spec)

- **Single effect.** No gates in any instance; vacuous.
- **I/O boundary is I/O only.** The core has none. Only the suite and fixture runners do file and process I/O. The `layers` suites forbid I/O or host imports in each `core/`; Python's also forbids the I/O and host modules listed in `layers.test.py`.
- **Boundaries.** `census: 299 files scanned, 16 boundary files in languages not scanned, 66 declared crossings` / `census: clean`.
  - The census reads JavaScript imports, PHP `require_once __DIR__ …` and Python imports (absolute ones through the `py/code/` import root). It does not read Lua `require` (M4).
  - Each port's internal layering is held by its `layers` suite (M6): JS 12/12, PHP 10/10, Python 17/17, each with 0 findings on the real tree.
  - The 16 unscanned files are the fixture JSON and the principles.
- **Contracts.**
  - Lua: `core-api: 14/14`, `observer-seam: 12/12`, `fixture-format: 5/5`, `kit-api: 6/6`.
  - JavaScript: `22/22`, `14/14`, `6/6`, `9/9`.
  - PHP: `18/18`, `14/14`, `7/7`, `8/8`.
  - Python: `32/32`, `18/18`, `6/6`, `7/7` (all `passed`).
  - Each port's checks pass on its fake core and fail on each switch they target: 13 switches in JavaScript, 19 in PHP, 33 in Python.
- **Shared.** `harness`, `js-harness`, `php-harness`, `py-harness`: each is one language's test runner, platform not behaviour.
- **Duplicates.** D1, D2 (one fake core per instance) and D3 (contracts, fixture matching, `kit.data` and harness once per language).

---

## Issues

1. **X1 (Lua), deceptive metatables** (items 5, 8 and 12; Drift 7). The fix is recorded in the spec and deferred by the user. The new item-12 member, the per-observer `__pairs` run, has its fix named in ISSUES (snapshot once per rejection, as the JavaScript and Python ports do).

## Notes

1. **Lua notes 1–7 of the 13.0.0 audit still hold.** The Lua code is unchanged.
2. **The ports copy as the reference does,** at entry, per sample and per observer (the cause from one snapshot per rejection, in JavaScript and Python). Iterative copies remove the depth limit; only the host's call stack (J5, Y5) and PHP's C stack when freeing deep arrays (P7) remain.
3. **Messages.** SISO_NOT_EVENT messages name each substrate's types. Every other code's message is the reference's text. Tests assert codes, never messages.
4. **Numbers across instances.**
   - Lua and Python have the same integer/float split, and their canonical JSON is byte-identical (64,539 floats and 3,000 random values compared).
   - JavaScript renders an integral float in the safe range as an integer (J4), and `-0` as `0`.
   - PHP reads an object whose keys are exactly `"0"…"n-1"` as a list (P6).
5. **Version history.** The release first settled as 13.0.1 is numbered 13.0.0 (core 13), and the build before it 13.0.0-rc1, in this project as in the archive's `core13.zip`. 13.2.0 (the Python port) was settled but not released; 13.2.1 replaces it.
6. **Runners.** `js/code/kit/run.mjs`, `php/code/kit/run.php` and `py/code/siso/kit/run.py` run from the project root, as the Lua kit runs from `lua/`. Python commands run as `python3 -B`.
</content>
</invoke>
