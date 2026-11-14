# contrainterated — a test surface for a game engine written in Emerge

> **v0.1.0 (P1: surface in place)** · 1 module built (the Emerge bridge) · 2 surfaces vendored and measured · 5 modules
> specced · judges an Emerge game engine against SISO core 13.2.1 and a working SISO game · the repository's name is
> still open (README, *Naming*)

Written on Idearium's atlas template (Nexus [`idearium/spec-engine/templates/atlas-template.md`](https://github.com/HorrisEllis/Nexus/blob/main/idearium/spec-engine/templates/atlas-template.md)).
Every heading of the template is kept; an empty one says so.

**Author:** James Brooks (Erosmancer) · rheon.world
**License:** not chosen. The game master's README says MIT and its vendored core is GNU GPL v3
([`surfaces/game-master-5.2.0/siso/LICENSE`](surfaces/game-master-5.2.0/siso/LICENSE)); his decision (README, *Findings* 2).

| Repo | Description |
|---|---|
| **HorrisEllis/Contrainterated** (this) | the test surface: two vendored SISO code bases, the Emerge bridge, this atlas |
| HorrisEllis/Nexus | Emerge itself (`emerge/emerge/lang/`), Idearium and its atlas template |
| HorrisEllis/Emerge | Emerge's own repository |

---

## What It Is

Emerge is James's language: compartments with state and constraints, signals and handlers, components joined by wires,
and a hash-chained ledger. To become a game engine it needs something to be measured against that already works. This
repository holds that measure: the SISO core 13.2.1 (one rule set, four language instances, one fixture suite) and the
game master 5.2.0 (an AI-narrated text adventure built on SISO core 13.1.0, with a test for every gate).

The shape is necessary because a claim like "Emerge can run a game" means nothing without a fixed, outside reference.
The fixtures fix the stream's behaviour; the contracts fix the instance; the game's per-gate tests fix every step of a
turn. An Emerge engine moves up those levels one at a time (README, *The test surface, level by level*).

---

## Quick Start

```bash
git clone https://github.com/HorrisEllis/Contrainterated && cd Contrainterated
```

```bash
cd surfaces/game-master-5.2.0 && php run_tests.php
```

```bash
node "$NEXUS/emerge/emerge/lang/eg.js" test engine/laws/inventory_guard.eg
```

```
GameMaster — test suites
  ✓ siso/php (core 13.1.0)             run: 21 suites, 371/371 passed
  …
  ✓ tests/scenarios.test.php           scenarios: 18/18 passed
all suites passed

✓ add: an entity in the scene, by id
…
8 passed, 0 failed
```

**Tested:** Linux container, 2026-11-13: PHP 8.3.6, Node 22.23.4, Python 3.13.18; Emerge 1.13.0 from Nexus 0.60.514.
Lua 5.4 not available, so the core's reference instance was not run.

---

## File Structure

```
contrainterated/
  README.md                         the full guide
  ATLAS.md                          this map
  bridge/
    inventory_guard.eg              Emerge: the game's inventory guard, 8 tests
  surfaces/
    siso-core-13.2.1/
      VERSION                       13.2.1
      fixtures/                     15 JSON fixtures, shared by every instance
      lua/  code/ sisoTests/        the reference instance (Lua 5.4) and its kit: lint, census, helper, digest, sha256
      js/   code/ sisoTests/        JavaScript instance (ESM, Node 18+)
      php/  code/ sisoTests/        PHP instance (8.1+)
      py/   code/siso/ sisoTests/   Python instance (3.10+)
      doc/                          SPEC.md, SISO-principles.md (2.0), conformance.md, ISSUES.md, dev notes
      doc/log/                      settlements 12.0 → 13.2.1, specs and changelogs 9.0 → 11.1, legacy-js/
    game-master-5.2.0/
      VERSION                       5.2.0
      siso/                         core 13.1.0, vendored unchanged (php, js, fixtures), hash-pinned
      src/Gates/                    37 pure server gates
      src/Boundary/                 Driver, FileStore, AI transports: all I/O
      src/Http/                     Router, Request, Response, Middleware, Handlers (one entry event each)
      src/Pure/  src/Events/        pure helpers; boundary request events
      src/Jobs/                     zone job queue
      public/js/                    client: gates/ (7), pure/, events/, boundary/ (driver, http), ui/ (DOM)
      public/css/ public/templates/ styles and page templates
      data/worlds/                  4 world templates; data/zones/ empty (.gitkeep)
      saves/                        empty (.gitkeep): game saves land here at run time
      sisoTests/                    one test per server gate, census, fixtures; client/ the same for client gates
      tests/                        vendor pin, templates, scenarios, client scenarios, browser e2e, probes/ (G1, G2)
      doc/                          principles, conformance, core pin, specs, changelogs
```

| Path | Node kind it holds | Contains |
|---|---|---|
| `bridge/` | `component` | Emerge source for engine pieces; today one pure function and its tests |
| `surfaces/siso-core-13.2.1/fixtures/` | none | the behaviour every SISO instance must reproduce |
| `surfaces/siso-core-13.2.1/*/code/` | `component` | one SISO instance per language |
| `surfaces/game-master-5.2.0/src/Gates/` | `component` | the game's server gates, one per event type |
| `surfaces/game-master-5.2.0/public/js/gates/` | `component` | the game's client gates |
| `surfaces/game-master-5.2.0/src/Boundary/` | `component` | the only code that does I/O |
| `surfaces/game-master-5.2.0/data/worlds/` | none | game content as data |
| `.architecture/` | — | **does not exist yet**: no node files have been written for this repository (gap, see *Modules Not Yet Built*) |

---

## Architecture

### Spine

Each surface has one spine: the SISO **Stream**, with its five doors (registration, entry, take, sample,
attach/detach). Everything a gate does reaches the outside only as returned events. The import rule is one-way: core ←
observer, kit, fixtures; the layering is held by each instance's `layers` test
([`surfaces/siso-core-13.2.1/js/sisoTests/layers.test.mjs`](surfaces/siso-core-13.2.1/js/sisoTests/layers.test.mjs)).

The game master's exception, stated: I/O does not go through the stream. It goes through the boundary Driver
([`surfaces/game-master-5.2.0/src/Boundary/Driver.php`](surfaces/game-master-5.2.0/src/Boundary/Driver.php)), which
takes residue, serves `io_read` / `io_write` / `ai_call` / `io_collect`, and enters the reply. That is by design (I/O at
the boundary), not a bypass.

The Emerge bridge has no spine yet: it is one pure function.

### Governing axioms

| Name | Statement | Rationale |
|---|---|---|
| `PURE_GATES` | a gate is `transform(event)`; no files, network, clock, randomness or throwing | SISO rule 1; a gate that can only return data cannot reach the stream |
| `SINGLE_EFFECT` | one gate per event type, a duplicate signature refused | SISO rule 2 |
| `CAUSALITY_ACYCLIC` | a causal chain may not revisit an event type; the stream rejects it | SISO rule 3; enforced, not decreed |
| `STREAM_IS_PRESENT` | the log is a recording and is never consulted | SISO rule 4, drift 1 |
| `NO_ORCHESTRATION` | no layer assembles events for passive gates | SISO rule 5, drift 3 |
| `REJECT_OR_CARRY` | never silently repair what a gate returned | SISO drift 7 |
| `IO_AT_THE_BOUNDARY` | only `src/Boundary` (server) and `public/js/boundary` (client) do I/O | the game master's design; audited in its `doc/conformance.md` (B1–B9) |
| `ONE_TEST_PER_GATE` | every gate has exactly one test, the gate alone on a core stream, whole output asserted | the census, [`surfaces/game-master-5.2.0/sisoTests/census.php`](surfaces/game-master-5.2.0/sisoTests/census.php) |
| Nexus `AXIOMS-v3.1` | the axioms are law for every decision taken in this repository's name | Nexus `CLAUDE.md`; §5.9–§5.16 sovereignty: this repository owns its own data and modules |

### Boundaries (documentation clarity)

| Term | Definition | Distinguished from |
|---|---|---|
| `residue` (SISO) | events no gate claims; the outside's answer | `unrouted` (Emerge) — logged by the runtime, not handed back as the answer |
| `rejection` (SISO) | a whole propagation refused; nothing committed | `rejected` (Emerge) — one transition refused by a hard constraint |
| `log` (SISO) | a recording an observer keeps; never read by the system | `ledger` (Emerge) — hash-chained and readable by the program (`ledger()`) |
| `gate` (SISO) | a pure transform, one per event type | `handler` (Emerge) — may write its compartment's state; several may hear one signal |
| `boundary` (game master) | the layer that performs I/O requests | `boundary` (Emerge) — an invariant on a component's state (`boundary counts_up { … }`) |
| `instance` (SISO) | one language's implementation of the core | `surface` (this repo) — a vendored code base used as a reference |

### Seams (cut points)

| ID | Location | Between | Cut type | Isolation (if applicable) |
|---|---|---|---|---|
| `seam.fixtures` | `surfaces/siso-core-13.2.1/fixtures/` | any instance ↔ the shared behaviour | `isolation` | language: JSON only |
| `seam.boundary` | `src/Boundary/Driver.php` | server gates ↔ files and AI | `isolation` | gates hold no handle to I/O |
| `seam.client-boundary` | `public/js/boundary/driver.mjs` | client gates ↔ fetch and DOM | `isolation` | same, in the browser |
| `seam.vendor` | `surfaces/game-master-5.2.0/siso/` | the game ↔ the core | `boundary` | hash-pinned; replaced whole on upgrade |
| `seam.bridge` | `bridge/` | Emerge ↔ the surfaces | `boundary` | the bridge reads nothing from `surfaces/` at run time; equality is checked from outside |

### Sovereignty (cross-system contract)

| Other system | May read | May never write | Enforced by |
|---|---|---|---|
| Nexus Emerge | nothing in this repository | everything | process: Emerge is invoked as a tool on this repository's files |
| this repository → Nexus | Emerge's language through `eg.js` (check, test, run, build) | everything in Nexus | process boundary; by convention only (no API route used yet) |
| `surfaces/*` ↔ `bridge/` | the bridge's tests may name surface behaviour | the bridge never edits a surface | convention; a surface is replaced only whole, from a new upload |

### Pulse (liveness)

**Heartbeat interval:** not applicable (no server process).
**Announces on connect:** not applicable.
**Health score computed from:** not applicable; the game master has `GET /api/health` when served with `php -S`.

### Self-diagnostics (structural strain)

| Metric | Computed from | Formula | Exposed at |
|---|---|---|---|
| `gate_census` | server and client gates vs their tests | gates with exactly one passing test ÷ gates | `php sisoTests/census.php` (37/37), `node sisoTests/client/census.mjs` (7/7) |
| `vendor_pin` | `siso/` vs `doc/siso-core-13.1.0.sha256` | files whose hash matches ÷ files | `tests/siso-vendor.test.php` (6/6) |
| `instance_parity` | each instance's 21 suites | passed ÷ total, per instance | the suite loops in the README |
| `bridge_parity` | Emerge bridge vs PHP original on fixed inputs | equal results ÷ inputs | not yet exposed (phase P2); 9/9 by hand on 2026-11-13 |
| `departures_open` | `doc/conformance.md` | count of recorded departures | 2 (G1, G2) |

### Config layers

| Layer | Scope | Overrides |
|---|---|---|
| environment (`ANTHROPIC_API_KEY`, `GAMEMASTER_MOCK_AI`, `GAMEMASTER_DEBUG`) | process | overrides `../keys.json` and the factory defaults |
| `GameStreamFactory` options | per factory instance | overrides the built-in defaults (`aiModel`, `aiMaxTokens`, paths, `maxZonePreGenerate`) |
| world templates `data/worlds/*.json` | per game | content only; never overrides engine config |
| Emerge `lang/core/primitives.eg` | per Emerge build | `max_deliveries`, `checkpoint_every`, `unrouted`, `discard_emits_on_refusal` |

### Phases

**Source:** hand-maintained; README *Proposed phases* (the coder's proposal, his to confirm).

| Phase | Intent | Entry condition | Exit gate | Produces |
|---|---|---|---|---|
| `P1` | the surface in place | the two zips | all surface suites green here; bridge 8/8 | `surfaces/`, `engine/laws/inventory_guard.eg`, README, this atlas |
| `P2` | differential runner | P1 | one command shows the bridge and the PHP gate equal on every input | `bridge/diff/` |
| `P3` | Emerge SISO instance, L0 | P1, his answer to Q2 | 15 fixtures, 92 steps | `emerge-siso/` |
| `P4` | L1 and L2 in Emerge | P3 | the 21 suites' pass lines | contracts and suites in Emerge |
| `P5` | the game's gates in Emerge | P3, his answer to Q1 | each gate's whole output equal; census holds | `engine/gates/` |
| `P6` | a playable game | P5 | `derelict_spaceship` end to end with the mock AI | `engine/boundary/` |
| `P7` | core 13.2.1 in the game master | P1 | `php run_tests.php` green on 13.2.1 | an updated `siso/` and pin |

### Component status

| Status | Meaning | Entry condition | Exit condition |
|---|---|---|---|
| `vendored` | a surface, unchanged from its upload | unpacked from a zip whose hash is recorded | replaced whole by a newer upload |
| `ported` | an Emerge piece with its own tests | `eg check` clean, its tests pass | becomes `matched` |
| `matched` | an Emerge piece equal to its original on fixed inputs | a committed differential test passes | stays, or returns to `ported` if the original changes |

---

## The Modules

### bridge.inventory_guard

**id:** `contrainterated.bridge.inventory_guard`
**node file:** none yet (no `.architecture/` in this repository)

**What it does**

The game master's Phase 5 inventory guard in Emerge: decides whether an AI-proposed inventory change is applied. It
exists because the AI invents items; the guard keeps the narrative and drops the change. Invariant: the function is pure
and returns a new list; it never raises on a well-formed change.

**How it works internally**

1. `add:X` — trim X. Held already → unchanged. Else kept if `lower(X)` is a known entity's lowercased name (or id when
   it has no name), or X is an entity id, or X is an `entityId` in this turn's `entityChanges`. Else unchanged.
2. `remove:X` — trim X. Held → every copy removed. Else unchanged.
3. Any other prefix → unchanged.
4. `apply_all` folds a reply's changes in order.

**Commands**

| Command | Operates on | What it does |
|---|---|---|
| `bridge.inventory_guard.check` | [`engine/laws/inventory_guard.eg`](engine/laws/inventory_guard.eg) | `eg check`: names, types, axioms |
| `bridge.inventory_guard.test` | [`engine/laws/inventory_guard.eg`](engine/laws/inventory_guard.eg) | `eg test`: the 8 test blocks |

**How to use it**

```eg
use "./engine/laws/inventory_guard.eg" as G
let scene = [{ id: "terminal", name: "Ship Terminal" }]
print(G.apply_all(["flashlight"], ["add:terminal", "add:plasma_rifle"], scene, []))   // ["flashlight", "terminal"]
```

**What it connects to**

- `surfaces/game-master-5.2.0` `StateUpdateGate::applyInventoryChange` — its original
  ([`surfaces/game-master-5.2.0/src/Gates/StateUpdateGate.php:102`](surfaces/game-master-5.2.0/src/Gates/StateUpdateGate.php#L102)); equality checked by hand, 9/9.

**Bus events emitted**

None: a pure function.

---

### surface.siso-core

**id:** `contrainterated.surface.siso-core`
**node file:** none yet

**What it does**

The reference for every SISO behaviour: three primitives, five doors, rejection, residue, observers, canonical JSON.
Four instances, one version, one principles text, one fixture suite.

**How it works internally**

Per instance: `code/core/` (Event, Gate, Rejection, Stream), `code/observer/streamlog`, `code/kit/` (canonical data,
suite runner; Lua also lint, census, helper, digest, sha256), `code/fixtures/run`, `code/contracts/` (core API, observer
seam, fixture format, kit API, each with checks and a fake core). See
[`surfaces/siso-core-13.2.1/doc/SPEC.md`](surfaces/siso-core-13.2.1/doc/SPEC.md).

**Commands**

| Command | Operates on | What it does |
|---|---|---|
| `surface.siso-core.test-js` | all nodes in `js/sisoTests/` | 21 suites, 380/380 |
| `surface.siso-core.test-php` | all nodes in `php/sisoTests/` | 21 suites, 371/371 |
| `surface.siso-core.test-py` | all nodes in `py/sisoTests/` | 21 suites, 394/394 |
| `surface.siso-core.test-lua` | all nodes in `lua/sisoTests/` | not run here (no Lua 5.4) |

**How to use it**

```bash
cd surfaces/siso-core-13.2.1 && node js/sisoTests/fixtures.test.mjs
```

**What it connects to**

- `surface.game-master` — vendored there at 13.1.0 (`seam.vendor`).

**Bus events emitted**

None outside its own tests.

---

### surface.game-master

**id:** `contrainterated.surface.game-master`
**node file:** none yet

**What it does**

A complete SISO game: create a game from a world template, play turns narrated by an AI, generate zones, save. The
model for an engine in Emerge: pure gates, I/O at the boundary, one test per gate.

**How it works internally**

Every request is one entry event. Server gates by signature (from
[`src/GameStreamFactory.php:60`](surfaces/game-master-5.2.0/src/GameStreamFactory.php#L60)):

| Flow | Signature → gate |
|---|---|
| templates | `templates_requested` → TemplateListGate · `templates_loaded` → TemplateCatalogGate |
| new game | `game_create_requested` → GameCreateGate · `game_create_loaded` → WorldTemplateGate · `game_template_ready` → GameInitGate · `game_created` → GameCreatedGate |
| load | `game_load_requested` → GameLoadGate · `game_load_read` → GameStateGate |
| turn | `action_submitted` → ActionParseGate · `turn_state_loaded` → TurnRouteGate · `turn_template_loaded` → ContextBuildGate · `portland_turn_loaded` → PortlandContextGate · `turn_ai_replied` → ResponseParseGate · `turn_apply_loaded` → TurnApplyRouteGate · `generic_turn_parsed` → StateUpdateGate · `portland_turn_parsed` → PortlandStateGate · `turn_applied` → GameBudgetGate · `turn_accounted` → GameSnapshotGate · `turn_saved` → GameResultGate · `turn_failed` → GameErrorGate |
| zone generation | `zone_generate_requested` → ZoneGenerateRequestGate · `zone_generate_loaded` → ZoneGenerateCheckGate · `zone_pregenerate_requested` → ZonePregenerateRequestGate · `zone_pregenerate_loaded` → ZonePreGenerationGate · `zone_target` → ZoneContextGate · `zone_ai_replied` → ZoneParseGate · `zones_settled` → ZoneStoreGate · `zone_generate_stored` → ZoneGenerateResultGate · `zone_pregenerate_stored` → ZonePregenerateResultGate |
| zone read and delete | `zone_get_requested` → ZoneGetGate · `zone_get_read` → ZoneGetResultGate · `zone_list_requested` → ZoneListGate · `zone_list_read` → ZoneListResultGate · `zone_delete_requested` → ZoneDeleteGate · `zone_delete_loaded` → ZoneDeleteApplyGate · `zone_file_deleted` → ZoneIndexRemoveGate · `zone_deleted` → ZoneDeletedGate |

Client gates ([`public/js/gates/`](surfaces/game-master-5.2.0/public/js/gates/)): `app_started` → TemplatesRequestGate ·
`templates_replied` → WorldOptionsGate · `start_submitted` → StartGameGate · `game_create_replied` → GameStartedGate ·
`command_entered` → CommandSendGate · `command_replied` → TurnShownGate · `client_rejected` → RejectionShownGate.

**Commands**

| Command | Operates on | What it does |
|---|---|---|
| `surface.game-master.test` | all nodes in `sisoTests/`, `tests/`, `siso/` | `php run_tests.php`: 10 suites |
| `surface.game-master.census` | all gates in `src/Gates/` | one passing test per gate (37/37) |
| `surface.game-master.serve` | the whole app | `php -S localhost:8080 router.php` |
| `surface.game-master.worker` | `data/zones/` job queue | `php worker.php [--daemon|--status|--cleanup]` |

**How to use it**

```php
$factory = new GameMaster\GameStreamFactory(['useMockAI' => true]);
$settled = $factory->createDriver()->run(new Siso\Core\Event('action_submitted', [
    'gameId' => 'game-123', 'action' => 'examine the ancient door', 'now' => date('c'),
]));
```

**HTTP routes**

```
GET    /api/health                              → node: the app            health check
GET    /api/templates                           → node: templates_requested list world templates
POST   /api/game/new                            → node: game_create_requested
GET    /api/game/{gameId}                       → node: game_load_requested
POST   /api/game/{gameId}/action                → node: action_submitted   play a turn
POST   /api/action                              → node: action_submitted   play a turn {gameId, action}
POST   /api/game/{gameId}/zone/generate         → node: zone_generate_requested
POST   /api/game/{gameId}/zone/pregenerate      → node: zone_pregenerate_requested
GET    /api/game/{gameId}/zone/{zoneId}         → node: zone_get_requested
GET    /api/game/{gameId}/zones                 → node: zone_list_requested
DELETE /api/game/{gameId}/zone/{zoneId}         → node: zone_delete_requested
```

**What it connects to**

- `surface.siso-core` at 13.1.0, vendored (`seam.vendor`).
- the Anthropic Messages API through `AnthropicTransport`, or `MockAiTransport` when no key is set.

**Bus events emitted**

Boundary request events, settled as residue and served by the Driver: `io_read`, `io_write`, `ai_call`, `io_collect`;
the final `http_response`. Client: `api_call`, `ui_*`, `session`.

---

## Modules Not Yet Built

These are specced and on the roadmap. They don't exist yet. §1.1.

| Module | Version | Depends On | Description |
|---|---|---|---|
| `.architecture/` nodes | 0.2.0 | P1 | node files for the modules above, so this atlas addresses real ids |
| `bridge.diff` | 0.2.0 | P1 | P2: committed differential test, Emerge bridge vs PHP gate |
| `emerge-siso` | 0.3.0 | P1, Q2 | P3–P4: a fifth SISO instance, in Emerge |
| `engine.gates` | 0.5.0 | P3, Q1 | P5: the game's gates in Emerge, with a census |
| `engine.boundary` | 0.6.0 | P5 | P6: the I/O layer, a playable world |

---

## Module Conflict Registry

Not applicable: no mutually exclusive modules.

---

## Build & Run Reference

```bash
# every core suite, one instance at a time, from surfaces/siso-core-13.2.1/
for t in js/sisoTests/*.test.mjs; do node "$t" | tail -1; done
for t in php/sisoTests/*.test.php; do php "$t" | tail -1; done
for t in py/sisoTests/*.test.py; do python3 -B "$t" | tail -1; done
# the game master, from surfaces/game-master-5.2.0/
php run_tests.php
GAMEMASTER_MOCK_AI=true php -S localhost:8080 router.php
# the Emerge bridge, from the repository root
node "$NEXUS/emerge/emerge/lang/eg.js" check engine/laws/inventory_guard.eg
node "$NEXUS/emerge/emerge/lang/eg.js" test engine/laws/inventory_guard.eg
```

---

## Version History

**Source:** NOT YET WIRED TO VERSIONIUM: hand-maintained below. Gap: this repository does not report to Versionium
(Nexus §17.12 asks that every change and version go there through `lib/versionium-record.js`).

| Commit | Branch | Message | Author | Timestamp |
|---|---|---|---|---|
| `38293b6` | main | Initial commit | HorrisEllis | 2026-11-13 13:47 -0800 |
| (this commit) | main | the test surface: both zips, README, atlas, Emerge inventory guard | Claude, for James Brooks | 2026-11-13 |

**Commands / routes**

None yet (no Versionium wiring).

---

## Copyright

Copyright © 2026 James Brooks. All rights reserved until a license is chosen.
rheon.world · https://github.com/HorrisEllis/Contrainterated
