# Contrainterated

A game engine using the Emerge coding language. This repository starts as its **test surface**: two working,
fully tested SISO code bases that an Emerge game engine has to match, layer by layer, before it can claim to be one.

> **The name is open.** James asked for a better one (2026-11-13: "push them to the constrainteration, need a better
> name though"). Candidates and their reasons are in [Naming](#naming). Renaming is a GitHub setting
> (Settings → General → Repository name); GitHub redirects the old URL.

## Creative direction

James, 2026-11-13: "I'm am the creative direction." · "My mentor gave it to me he said it'd be like when I used to,
like when I was an admin on the game servers that I used to run with my brother. So I'm like an overseer or something".

He is the admin of the world, not a player in it: he sets the rules, and the world cannot cheat them. Emerge already
has that seat. Each world is a compartment, its rules are hard and soft constraints, and the hash-chained ledger is
the admin log that cannot be faked. Players, and the AI, only send signals that the rules accept or refuse. In the
game master, the AI proposes and the inventory guard refuses what does not exist. Games are the first kind of world;
the direction of what comes next is his.

James, 2026-11-13: "This built in emerge. Interconnected." The engine is written in Emerge, and its parts are
interconnected. In Emerge, that is components with typed `in` and `out` hooks joined by `wire`s, and systems nested
in systems (Nexus `emerge/emerge/tests/nesting.test.js`). The SISO surfaces stay the measure it is checked against.

### Where it sits among his work

| Piece | What it is | Its part here |
|---|---|---|
| **Emerge** (Nexus `emerge/`) | his language | what everything is written in |
| **SISO / WARP** (`surfaces/siso-core-13.2.1`; Eravos `warp/`) | how events move. WARP is SISO renamed (Event → Cause, Gate → Drawbridge, Stream → Flow) plus Axiom, hard or soft | the measure |
| **Wire** (Eravos `wire/`) | Component, Hook, Wire | the same as Emerge's components and wires |
| **Eravos** (`HorrisEllis/Eravos`) | two people's worlds connected: "Everything has be requested, besides sending love, pokes, or needs. Nothing one way" · "Only if the two consent." | the first world-to-world rule, now in `bridge/two_worlds.eg` |
| **ERAVOS canvas** (`HorrisEllis/Eravos-Canvas`, 3.186) | "a creative canvas to create creatively, and to connect with people"; component → tool → suite → project, one mechanism (wires) at every scale; v2 planned on Emerge + Looking Glass | the largest interconnected thing he has built; this repository is a place to prove Emerge before ERAVOS v2 stands on it |
| **this repository** | where the laws are tested before anything stands on them; games first | the proving ground |

### Two worlds, interconnected (`bridge/two_worlds.eg`)

Fantasy and Starship are two Emerge compartments, each with one hard law: no technology into Fantasy, and no magic
into Starship. Between them is Eravos's rule. Love, pokes and needs cross at once. Anything else waits behind a
request wall until the other side says yes or no. A world's law cannot be consented away: a laser that Fantasy
agreed to take is still refused. Every crossing and refusal is in the hash-chained ledger, and `ledger_verify()`
holds. 7 tests. Removing the law fails 1 of them, and removing the request wall fails 3.

Companion: [`ATLAS.md`](ATLAS.md), the system atlas, written on Idearium's atlas template
(Nexus `idearium/spec-engine/templates/atlas-template.md`).

---

## Contents

1. [What is here](#what-is-here)
2. [Why these two zips are a test surface](#why-these-two-zips-are-a-test-surface)
3. [Measured on 2026-11-13](#measured-on-2026-11-13)
4. [SISO in one page](#siso-in-one-page)
5. [The game master: a game engine already built on SISO](#the-game-master-a-game-engine-already-built-on-siso)
6. [Emerge and SISO, side by side](#emerge-and-siso-side-by-side)
7. [The test surface, level by level](#the-test-surface-level-by-level)
8. [The first bridge piece: the inventory guard in Emerge](#the-first-bridge-piece-the-inventory-guard-in-emerge)
9. [Running everything](#running-everything)
10. [Findings and gaps](#findings-and-gaps)
11. [Open questions (his to decide)](#open-questions-his-to-decide)
12. [Proposed phases](#proposed-phases)
13. [Naming](#naming)

---

## What is here

```
contrainterated/
  README.md                       this file
  ATLAS.md                        the system atlas (Idearium template)
  bridge/
    inventory_guard.eg            the first piece of the game engine in Emerge, with its tests
    two_worlds.eg                 two interconnected worlds, each with its own law, and Eravos's consent rule between them
  surfaces/
    siso-core-13.2.1/             the SISO core: Lua reference + JavaScript, PHP, Python ports, one fixture suite
    game-master-5.2.0/            an AI-narrated text adventure engine built on SISO core 13.1.0 (PHP server, JS client)
```

Both `surfaces/` folders are the uploaded zips, unpacked unchanged.

| Folder | Uploaded as | SHA-256 of the zip | Files | Version file |
|---|---|---|---|---|
| `surfaces/siso-core-13.2.1/` | `core13.2.1.zip` (top folder `core13.2.1/core/`) | `c1e704db3f14ee7c5c2e364c0749d15449e7a231f855845afc6d52ef3119e684` | 336 | `VERSION` = 13.2.1 |
| `surfaces/game-master-5.2.0/` | `worker_clean-5.2.0.zip` (top folder `worker_clean/`) | `ba318ce0f3e77bed568826510820ff92734edbd05648e52296b42d4cea16ef7e` | 303 | `VERSION` = 5.2.0; vendored core `siso/VERSION` = 13.1.0 |

Each keeps its own documents, which stay the authority on it:

- core: `doc/SPEC.md` (boundaries and contracts), `doc/SISO-principles.md` (the rules, 2.0), `doc/conformance.md`
  (how each instance holds each rule), `doc/ISSUES.md`, and `doc/log/` (every settlement from 12.0 to 13.2.1,
  older specs and changelogs, and the legacy JavaScript core).
- game master: `README.md`, `SPEC.md` (phase roadmap; phases 6 to 10 still to build), `doc/specs/spec-v5.2.0.md`
  (all 37 server gates and 7 client gates with signatures and tests), `doc/conformance.md` (audit, departures G1
  and G2), `doc/changelogs/`.

---

## Why these two zips are a test surface

Emerge is James's language. Its runtime already has the shape of an event engine: **compartments** with state and
**constraints**, **signals** delivered to **handlers** in FIFO order, **components** with typed `in` and `out` hooks
joined by **wires**, a **storm limit**, **rejection**, and a hash-chained **ledger** (Nexus
`emerge/emerge/lang/cie/world.eg`, `lang/README.md`). A game is one of the hardest honest tests of that shape:
every turn is a chain of events, state must change only in ways that can be checked, and a wrong change (an item
the AI made up, a stat that went negative) must be refused rather than repaired.

The two zips give that test something to be judged against, at three sizes:

| Size | What it is | What an Emerge engine must do with it |
|---|---|---|
| Small | 15 language-agnostic fixtures (`surfaces/siso-core-13.2.1/fixtures/*.json`) | produce the same residue, causal trees and rejections, read from the same JSON files |
| Medium | four contracts per instance (core API, observer seam, fixture format, kit API) with their checks and fake cores | hold the same contracts, checked the same way |
| Large | a working game: 37 pure server gates, 7 client gates, a boundary driver, 4 world templates, REST API, zone generation | play the same game, gate by gate, with each gate's whole output equal to the PHP or JS original |

The core has already been ported from Lua to JavaScript, PHP and Python against the same fixtures, so "port to a new
language and prove it with the fixture suite" is a path that has been walked three times (`doc/SPEC.md`,
*Instances*). Emerge would be the fifth instance.

---

## Measured on 2026-11-13

Run in this session's Linux container (PHP 8.3.6, Node 22.23.4, Python 3.13.18). Each figure is the suites' own last
line, summed.

| Surface | Command | Result |
|---|---|---|
| core, JavaScript | `node js/sisoTests/<name>.test.mjs`, all 21 | **380/380** passed |
| core, PHP | `php php/sisoTests/<name>.test.php`, all 21 | **371/371** passed |
| core, Python | `python3 -B py/sisoTests/<name>.test.py`, all 21 | **394/394** passed |
| core, Lua (the reference) | `cd lua && lua5.4 sisoTests/<name>.test.lua` | **not run**: Lua 5.4 is not installed in this container and could not be installed here |
| game master | `php run_tests.php` | **all 10 suites passed**: vendored core PHP 371/371, JS 376/376, fixtures 92/92 steps in each of PHP and JS, vendor pin 6/6, server census 37/37, client census 7/7, client scenarios 9/9, templates 4/4, scenarios 18/18 |
| bridge | `eg test bridge/inventory_guard.eg` (Emerge 1.13.0, Nexus 0.60.514) | **8/8** passed |
| two worlds | `eg test bridge/two_worlds.eg` | **7/7** passed; with Fantasy's law removed 6/7, with the request wall removed 4/7 (the tests can fail) |
| bridge vs PHP | the same 9 inventory cases through `StateUpdateGate::applyInventoryChange` by reflection | **9/9 equal** |

Not run: the game master's optional `tests/browser.e2e.mjs` (Playwright). Nexus tests UI in Clear Glass, never
Playwright, so it is listed and left alone.

---

## SISO in one page

Distilled from `surfaces/siso-core-13.2.1/doc/SISO-principles.md` (2.0). Read the original for the reasons.

**Three primitives.** An **Event** (a type and its data, read-only from construction), a **Gate** (a pure function from
one event to zero, one or many events, registered under one signature, the event type it claims), and a **Stream**
(routes each event to the one gate for its type).

**The outside uses four doors while the stream runs:** *entry* (put an event in; it runs one propagation until it
settles), *take* (read and empty the residue), *sample* (read the residue without emptying it), and *attach/detach*
(observers). A fifth door, *registration*, builds the stream before it runs; a duplicate signature is refused.

**Residue is the answer.** An event no gate claims settles as residue. The outside reads it by taking it.

**Rejection is not an answer.** A gate that throws, a causal chain that revisits an event type (a cycle), or an
entered value that is not an event rejects the whole propagation. Nothing is committed, and the rejection leaves
through the entry door carrying the reason and the deliveries involved.

**Five rules.** 1: gates are pure and events carry exactly what their gate needs. 2: single effect. 3: no ordering;
causality is carried by events and is acyclic, enforced by the stream. 4: the stream is the present; the log is a
recording, never consulted. 5: there is no orchestration.

**Eight named drifts** (how SISO projects go wrong): the log becomes consulted; registration order becomes
scheduling; an orchestration layer accumulates; gates reach outside their event; events carry speculative payload;
**a fourth primitive appears ("state", "context", "cache", "scheduler", "world")**; guard-rails silently repair; and
the unnamed concession. The sixth matters most for a game engine: see the next sections.

---

## The game master: a game engine already built on SISO

`surfaces/game-master-5.2.0/` is a text adventure: the player picks a world and types actions, Claude narrates and
proposes state changes, and the game applies only the changes it can verify, then saves.

**Where the state lives.** Not in the stream. Gates never touch files, the network, the clock or randomness, and never
throw. A gate that needs something from outside returns a **request event** (`io_read`, `io_write`, `ai_call`,
`io_collect`) that no gate claims, so it settles as residue. The **Driver** (`src/Boundary/Driver.php`) takes the
residue, performs each request (files via `FileStore`, the AI via an `AiTransport`), and enters the reply event the
request named. The flow ends with an `http_response` event that a gate built; the HTTP handler only sends it.

**One player turn**, from its README (⇒ is the boundary serving a request):

```
action_submitted ─ActionParseGate→ io_read{save} ⇒ turn_state_loaded ─TurnRouteGate→ io_read{template}
 ⇒ turn_template_loaded ─ContextBuildGate→ ai_call ⇒ turn_ai_replied ─ResponseParseGate→ io_read{save}
 ⇒ turn_apply_loaded ─TurnApplyRouteGate→ generic_turn_parsed ─StateUpdateGate→ turn_applied
 ─GameBudgetGate→ turn_accounted ─GameSnapshotGate→ io_write{save} ⇒ turn_saved ─GameResultGate→ http_response
```

**The client** (`public/js`) is the same model on the core's JavaScript instance: 7 client gates return `api_call`,
`ui_*` render instructions and `session`; `public/js/boundary/driver.mjs` serves them.

**What it gives an engine in Emerge to copy:**

| Engine concern | How the game master answers it | Where |
|---|---|---|
| A turn | one entry event; a causal chain of pure gates | `src/Http/Handlers`, `src/Gates` |
| Game state | a JSON save, read and written only by the boundary | `src/Boundary/FileStore.php`, `saves/` |
| World content | data, not code: 4 world templates (zones, exits, rules, tone) | `data/worlds/*.json` |
| Untrusted proposals (the AI) | type-checked and guarded; unverifiable changes discarded, the narrative still plays | `ResponseParseGate`, `StateUpdateGate`, `PortlandStateGate` |
| Procedural content | zones generated by the AI behind open exits, queued as jobs | `ZoneContextGate`, `src/Jobs`, `worker.php` |
| Cost | tokens and estimated cost per game, in the save (`budget`); no limit enforced | `GameBudgetGate` |
| Rendering | `ui_*` events drawn by plain DOM code outside the gates | `public/js/ui` |
| Proof | one test per gate, the gate alone on a core stream, whole output asserted; a census checks every gate has exactly one | `sisoTests/`, `sisoTests/census.php` |

---

## Emerge and SISO, side by side

Read from Nexus `emerge/emerge/lang/cie/world.eg`, `lang/README.md`, `lang/MAP.md`, `lang/examples/` and the SISO
principles. This is the coder's reading of both; where they disagree, the choice is his (see
[Open questions](#open-questions-his-to-decide)).

| SISO | Emerge | Same? |
|---|---|---|
| Event (type + read-only data) | signal (`emit "name" { … }`, `signal(name, payload)`) | yes |
| Gate: pure `transform(event)` returning events | handler (`on "name" (ev) { … }`) or component `in` hook; returns nothing, `emit`s instead | **no**: an Emerge handler may write its compartment's `state` |
| one gate per event type; duplicate refused | several handlers may hear one signal (a pattern ending in `*` matches a prefix) | **no** |
| Stream | the world's FIFO queue (`lang/cie/world.eg`) | close |
| residue: unclaimed events, taken by the outside | `unrouted` (`unrouted "log"` by default) | close; Emerge logs it, SISO hands it back as the answer |
| rejection: a throw or a cycle rejects the whole propagation, nothing committed | a hard `constraint` refuses one transition; `rejected` signal; emits discarded on refusal (`discard_emits_on_refusal true`) | close in spirit; different unit (one transition, not one propagation) |
| acyclicity: a causal chain may not revisit a type | storm limit: `max_deliveries` 100000 | **no**: a count, not a cycle check |
| no state in the core (drift 6) | compartments hold `state`, guarded by `constraint`s | **no**: the deepest difference |
| the log: a recording, never consulted (drift 1) | the ledger: hash-chained, verified, and readable by the program (`ledger()`, `ledger_why()`) | **no** |
| observers attached at the outside | `observe()`, the ledger | close |
| the boundary does all I/O | builtins `read_file`, `write_file`, `random`, … callable from anywhere | **no** (by convention only, as in the game master) |

Emerge already carries a SISO lineage: `emerge/emerge/siso/` (Event, Gate, Stream, StreamLog) is described in its own
header as a "direct port of SISO Core Stream.js to CommonJS", with `Stream.replay(log)` added. It descends from the
pre-v9 JavaScript core that core 13 keeps in `doc/log/legacy-js/` (the files differ; the lineage is the same). Under
principles 2.0, replaying a run from its log is drift 1 (the log consulted). `emerge-ide.js` and
`emerge-codegen-v2.js` still require it.

The short version: **Emerge is a stateful, constraint-checked event world; SISO is a stateless, purity-checked event
stream.** A game engine needs both answers: somewhere state has to live and be guarded. The game master puts it in the
save file at the boundary. Emerge puts it in compartments behind constraints. Those are two different designs, and
the test surface is where they can be compared on the same game.

---

## The test surface, level by level

Each level is a gate an Emerge engine passes before the next one means anything. All of it is already in `surfaces/`.

| Level | What is checked | Source of truth | Pass condition for Emerge |
|---|---|---|---|
| **L0 fixtures** | residue as a multiset, causal traces as trees, rejection paths, duplicate refusal, cycles of length 1, 2 and 3, a throwing gate, a non-event, rejection commits nothing, the longest legal chain, ids per propagation | `surfaces/siso-core-13.2.1/fixtures/01…15-*.json` (identical in core 13.1.0 and 13.2.1) | an Emerge runner reads the 15 files and passes every step, as the JS, PHP and Python runners do (92 steps) |
| **L1 contracts** | core API (doors, read-only events, ownership), observer seam (one call per propagation, isolated copies, one rejection snapshot), fixture format, kit API (canonical JSON) | `*/code/contracts/*`, `*/sisoTests/contract-*.test.*` | the same checks run against an Emerge instance; the fake core shows each check can fail |
| **L2 instance suites** | 21 suites per instance: doors, freeze, ownership, stateless, born-frozen, rejection copy, layers, streamlog, kit data | `*/sisoTests/*.test.*` | an Emerge suite of the same 21, last line `{suite}: {passed}/{total} passed` |
| **L3 gates** | each of the game's 37 server and 7 client gates alone on a stream, whole output asserted | `surfaces/game-master-5.2.0/sisoTests/*.test.php`, `sisoTests/client/*.test.mjs`, the two censuses | each gate ported to Emerge gives the same output for the same input; a census proves one test per gate |
| **L4 scenarios** | end to end through handlers with temp files and a scripted mock AI; the client with a scripted server | `tests/scenarios.test.php` (18), `tests/client-scenarios.test.mjs` (9), `tests/templates.test.php` (4) | the same scenarios, same saves, same responses |
| **L5 departures** | G1 (a corrupt save makes a gate throw), G2 (an AI stat key named like a reserved key makes later turns throw) | `tests/probes/`, `doc/conformance.md` | an Emerge engine either never throws on them, or records the same departure by name |

`bridge/inventory_guard.eg` is the first L3 piece, for one function inside one gate.

---

## The first bridge piece: the inventory guard in Emerge

`bridge/inventory_guard.eg` is the game master's Phase 5 inventory guard (the fix for items the AI invented) written
in Emerge, rule for rule from `StateUpdateGate::applyInventoryChange`:

- `add:X` is ignored when X is already held, and kept only when X names a known entity (lowercased name, else id), is
  an entity id, or is an entity changed this turn (an NPC handing something over). Otherwise it is discarded: the
  narrative plays, the state does not change.
- `remove:X` removes X only when it is held.

It is a pure function (SISO rule 1), so it fits both designs: a SISO gate can call it, and an Emerge compartment can
use it inside a hard constraint. Its 8 tests pass, and the same 9 cases run through the PHP original by reflection give
the same 9 inventories. The PHP side of that comparison was run by hand this session and is not yet a committed test;
making it one is phase P2 below.

Learned while writing it: in Emerge, `starts_with` and `contains` are handler-pattern words, not functions
(`lang/MAP.md`, "handlers"), so the file defines `_begins` and `_holds`. Worth a line in Emerge's learn notes.

---

## Running everything

Requirements: PHP 8.1 or later, Node 18 or later (22.15 or later for the JS copy-count cases), Python 3.10 or later,
Lua 5.4 for the core's reference, and a Nexus checkout for Emerge (`emerge/emerge/lang/eg.js`). No package installs.

The SISO core, from `surfaces/siso-core-13.2.1/`:

```bash
for t in js/sisoTests/*.test.mjs; do node "$t" | tail -1; done
```

```bash
for t in php/sisoTests/*.test.php; do php "$t" | tail -1; done
```

```bash
for t in py/sisoTests/*.test.py; do python3 -B "$t" | tail -1; done
```

```bash
cd lua && for t in sisoTests/*.test.lua; do lua5.4 "$t" | tail -1; done
```

The game master, from `surfaces/game-master-5.2.0/`:

```bash
php run_tests.php
```

```bash
GAMEMASTER_MOCK_AI=true php -S localhost:8080 router.php
```

The Emerge bridge, from the repository root (`NEXUS` is your Nexus checkout):

```bash
node "$NEXUS/emerge/emerge/lang/eg.js" check bridge/inventory_guard.eg
```

```bash
node "$NEXUS/emerge/emerge/lang/eg.js" test bridge/inventory_guard.eg
```

---

## Findings and gaps

Found while reading and running both zips this session.

1. **The game master vendors core 13.1.0; 13.2.1 is out.** 13.2.1 fixes the JavaScript observer seam: the rejection
   is now snapshotted once per rejection, so a getter on what a gate threw can no longer count the observers
   (`doc/log/settlement-v13.2.1.md`). The game master's client runs on the JS instance. Upgrading is the README's own
   procedure: replace `siso/`, regenerate the pin file in `doc/`, run `php run_tests.php`. The 15 fixtures are
   byte-identical between the two, so L0 does not move.
2. **License mismatch.** The game master's README says MIT; the vendored core's `siso/LICENSE` is GNU GPL v3. A GPL
   core inside an MIT project needs his decision before this repository picks a license.
3. **Recorded departures G1 and G2** (gates that can throw on a corrupt save or a hostile AI stat key) are still open
   in 5.2.0, with probes in `tests/probes/`.
4. **The Lua reference was not run here.** Three of four instances were measured; the reference was not.
5. **No Emerge instance of the SISO core exists yet.** Emerge's own `siso/` is the legacy CommonJS port with a replay
   feature that principles 2.0 calls drift 1.
6. **The game master's default model** is `claude-sonnet-4-20250514` (`GameStreamFactory` option `aiModel`), which is
   old. It is a configuration value; changing it is a one-line decision.
7. **Phases 6 to 10 of the game master** (inventory panel and zone map, save slots and history, world editor,
   multiplayer sessions, admin dashboard) are specified in `SPEC.md` and not built. They are ready-made features for
   the Emerge engine to build new rather than port.

---

## Open questions (his to decide)

The axioms do not settle these; each changes what gets built.

**Q1. Where does game state live in the Emerge engine?**

| Reading | What it means | Cost |
|---|---|---|
| A. SISO's answer | gates stay pure; state is a save at the boundary; Emerge compartments are not used for game state | Emerge's constraints and ledger sit unused for the game itself |
| B. Emerge's answer | each game is a compartment; hard constraints guard every change (no negative health, no invented item) | leaves SISO principles 2.0 by its drift 6; L1 and L2 must be rewritten as Emerge's own contract |
| C. Both, split by layer | pure SISO gates decide; one compartment per save holds state, and its constraints are the last check before a write | two models in one engine, with a seam between them that must be specified |

**Q2. What does Emerge do with SISO's acyclicity rule?** Emerge's storm limit counts deliveries; SISO rejects any
causal chain that revisits a type. Fixtures 08, 09 and 10 (self-loop, cycles of 2 and 3) will fail under a count-only
rule. Either Emerge adds the cycle check, or those fixtures are marked as a named departure.

**Q3. The name.** See [Naming](#naming).

---

## Proposed phases

The coder's proposal, in Nexus's phase form. Nothing below is built except P1.

| Phase | depends_on | files | Exit gate |
|---|---|---|---|
| P1 the surface in place | `[]` | `surfaces/`, `README.md`, `ATLAS.md`, `bridge/inventory_guard.eg` | **done**: suites measured above; bridge 8/8 |
| P2 differential runner | P1 | `bridge/diff/` (Emerge), a fixed list of inputs | the Emerge bridge and the PHP gate agree on every input, checked by one command |
| P3 an Emerge SISO instance, L0 | P1, Q2 | `emerge-siso/code/`, `emerge-siso/sisoTests/fixtures.test.eg` | 15 fixtures, 92 steps, read from `surfaces/siso-core-13.2.1/fixtures/` |
| P4 L1 and L2 in Emerge | P3 | `emerge-siso/code/contracts/`, the 21 suites | the same pass lines as JS, PHP and Python |
| P5 the game's gates in Emerge, one at a time | P3, Q1 | `engine/gates/*.eg`, a census | each gate's whole output equals the original's; the census holds |
| P6 a playable game | P5 | `engine/boundary/`, a world template | `derelict_spaceship` plays end to end with the mock AI, matching `tests/scenarios.test.php` |
| P7 core 13.2.1 in the game master | P1 | `surfaces/game-master-5.2.0/siso/` | `php run_tests.php` green on 13.2.1 |

---

## Naming

"Contrainterated" reads as *constraint* and *iterated* run together, and James wants a better name. Four candidates,
each grounded in the two systems or in his words:

| Name | Why |
|---|---|
| **Gatefield** (the coder's pick) | SISO's unit is the gate; Emerge's spec defines `gate = execution_control_point`; and "field" is his: WARP 2's "constraint relational field". The engine is gates over a field of constraints. |
| **Residue** | In SISO, what settles is the answer. In a game, the settled state after a turn is exactly that. Short. |
| **Settle** | The verb both systems share: a propagation settles; a turn settles. |
| **Tidewright** | His words, 2026-11-08: "Ripples, waves, tidal waves of change if I can help it." An engine where one entry event ripples through a causal chain. |

It is his call. After a rename, update the title of this file and of `ATLAS.md`.

---

## Credit

The SISO core, its principles and the game master are James Brooks's work, uploaded to this session on 2026-11-13.
Emerge is his language (Nexus, `emerge/`). The readings, the side-by-side comparison, the Emerge port of the inventory
guard and the proposed phases are the coder's, offered for his decision.
