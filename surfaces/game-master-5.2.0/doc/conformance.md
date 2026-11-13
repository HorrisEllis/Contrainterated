# Conformance Audit — GameMaster (worker_clean)

**Project version:** 5.2.0 (`VERSION`)
**Audited against:** **SISO Principles — core, version 2.0** (`doc/SISO-principles.md`, read in full at
phase start; copied from the SISO core 13.1.0 release). No SPINE-principles or COMPONENT-SPEC was
supplied for this project.
**Core instances used:** SISO core **13.1.0**, vendored unchanged in `siso/` (pinned by
`doc/siso-core-13.1.0.sha256`, checked by `tests/siso-vendor.test.php`): the **PHP instance** (back end)
and the **JavaScript instance** (client). Each instance's own conformance (how the core itself holds the
rules, and its ledger P1–P8, J1–J6) is the core's audit, not this one; this audit covers what the
project builds on them.
**Audit date:** 2026-10-20
**Phase:** core-13 upgrade
**Auditor:** phase termination, plus an independent review by a separate agent that had not written the
code (see *Independent review*).

Evidence numbers come from this phase's runs (`php run_tests.php`): core PHP run: 21 suites, 371/371
passed · core JS run: 21 suites, 376/376 passed · fixtures 92/92 steps (PHP and JS) · siso-vendor 6/6
(and 6/6 with `SISO_RELEASE` pointed at the unpacked release) · census 37/37 (147/147 per-gate cases) ·
census (client) 7/7 (41/41 cases) · client-scenarios 9/9 · templates 4/4 · scenarios 18/18; the optional
`tests/browser.e2e.mjs`: browser-e2e 5/5; the probes `tests/probes/g1-corrupt-saves.php`: 7 of 32 flows
rejected (11 of 32 with `--warnings`) and `tests/probes/g2-stat-keys.php`: statuses 200,500,500.

Two streams are audited: the **server** stream (37 gates, `src/Gates`, one fresh stream per request) and
the **client** stream (7 gates, `public/js/gates`, one stream per page).

**Warnings are throws.** `api.php`, `worker.php` and `tests/runner.php` install an error handler that
turns every PHP warning and notice into an `ErrorException`. A notice raised inside a gate ("Array to
string conversion") is therefore a throw, and core 13 rejects the propagation (SISO_GATE_THREW). This is
deliberate (a warning in a gate is a bug, and it must not settle as an answer), and it is part of how
item 3 is measured: G1's probe is run both ways.

---

## Compliance checklist (principles 2.0)

| # | Item | Strength | Verdict | Evidence |
|---|---|---|---|---|
| 1 | Exactly three primitives; everything else in a ledger | Inspection | ✓ | Event, Gate, Stream are the core's (`siso/`). `GameGate` is how a gate is *written* (a signature and a transform, handed to the core as a Gate); it adds no capability and holds no state. The drivers, file store, transports and renderers are boundary, listed under *Boundary is I/O only*; their state is ledger L1–L2. |
| 2 | A gate receives one event and nothing else, and returns events | Architecture | ✓ | Held by the core: the stream calls `transform` with the event only (`$this->transform(...)` of a read-only GameGate on the server; module functions with `this` undefined on the client). Per-gate tests run each gate through a real one-gate stream, so a non-event return fails its case. |
| 3 | No gate has state or side effects (throwing included) | Architecture, or a recorded convention | **✗ departures G1, G2** (server gates throwing); ✓ otherwise | State and effects: a scan of `src/Gates`, `src/Pure`, `public/js/gates`, `public/js/pure` and `public/js/events` for file, network, clock, randomness, environment, superglobal, `static`, `globalThis`, `document`, `window`, `fetch` and `Date` use found none; no gate writes `$this->…` after construction; client gate modules export only constants and functions. Purity is the author's convention in both substrates (core ledger P1, J1). **Throwing, server:** gates throw on a save with the wrong field types (G1) and, after one AI reply whose `statChanges` names a reserved key, on every later turn that sets a flag (G2). A first fuzz of 24 malformed-AI-reply turns found no throw; the independent review found G2. **Throwing, client:** the review showed `String(v)` itself throws on a JSON object whose `toString` is not a function; fixed this phase — every displayed field goes through `pure/text.mjs` `text()`, which never throws, and hostile-JSON cases are in each reply gate's test. A rejected client propagation now enters `client_rejected`, whose gate frees the page (before the fix the page stayed busy). |
| 4 | Every gate has a single logical effect | Architecture, or a recorded convention | ✓ | Each gate has one test file; both censuses check that its accepted cases share one output shape. Tables below. Rejections are labelled (drop or abort) and decided by the same input. |
| 5 | An event is a type and data, carries what its gate requires, never annotated by delivery | Architecture (shape, annotation); convention (sizing) | ✓ | Shape and annotation: held by the core (final readonly / frozen events; delivery metadata beside the event, K4). Sizing: as in 5.1 (Note 3 on the server's pass-through); client entry events carry the three-field session because every client gate that reads it either guards on `busy`/`gameId` or carries it to its reply. |
| 6 | No gate depends on execution order | Architecture | ✓ | Routing by type, one gate per type (`register` refuses duplicates). Factories/registries are maps; order carries no meaning. Render instructions in one propagation must not depend on the order they are drawn in (the residue is a multiset): the client never puts two `ui_log` events in one propagation, and — fixed this phase after the review showed `ui_screen` + `ui_log` scrolled differently in the two orders — drawing `ui_screen` re-measures the log (`Output.settle()`), so both orders end in the same state (checked in headless Chromium with the review's probe: scrollTop 6116 and the same indicators either way). |
| 7 | No causal chain revisits a type; self-loops and cycles rejected | Architecture | ✓ | Held by the core (depth bound, self-loop check). No gate returns its own type; within a propagation every chain runs from an entry to a request or render instruction in residue. Each boundary reply is a new entry (Note 5). |
| 8 | A rejected propagation commits no residue and leaves only as a rejection through the entry door | Architecture | ✓ | Held by the core. Server: the Driver lets `Rejection` pass; `StreamEndpoint` catches it and answers 500 with nothing written by that propagation (writes happen only when the Driver serves a taken `io_write`). Client: the driver catches it and shows the error overlay. |
| 9 | Present-tense questions are answered by sampling, never from history | Inspection | ✓ | Handlers read the answer from what the Driver took; the client draws what it took. Game state is the save files — the world outside the stream, read on demand through `io_read`. No log is read by anything but the debug body. |
| 10 | Sampling is local, non-destructive and proportional to the present | Architecture | ✓ | Held by the core. The project now takes instead of sampling; nothing samples. |
| 11 | Answers leave only by being taken | Architecture | ✓ | Both drivers call `takeResidue()` after every `emit`; that is the only way they read results. |
| 12 | Attaching or removing every observer leaves behaviour unchanged | Architecture | ✓ | Held by the core (observer-seam). The only observer is the debug StreamLog, attached in debug mode. It changes no answer: its `history` is added only to the 500 body of a request that failed (as in 5.1). |
| 13 | No orchestration | Inspection | ✓ | The drivers call no gate, order none and assemble no context: they serve requests gates returned and enter the reply type the request names. The join (Note 1) is declared by an event. |
| 14 | Ticks, if present, are bookkeeping only | Inspection | ✓ | No ticks. |
| 15 | Every mechanism outside the primitives is in a ledger, in its thinnest form, or a recorded departure | Inspection | ✓ | *Ledger* (L1–L3), *Boundary is I/O only* (B1–B9, C2–C5), *Recorded departures* (G1, G2). |

## Drift patterns

- **Drift 1 (log consulted):** absent (item 9, 12).
- **Drift 2 (registration order as scheduling):** absent (item 6).
- **Drift 3 (orchestration accumulates):** absent on the server (as 5.1). **Fixed on the client this
  phase:** `app.js` held every decision (what to fetch, what to show, busy flags, name defaulting) in
  one orchestrating module with a state singleton; those decisions are now gates.
- **Drift 4 (gates reach outside):** absent. The old client `ui/output.js` read the global `State` to
  draw the inventory; the inventory now arrives on the `ui_log` event.
- **Drift 5 (speculative payload):** as 5.1 (Note 3).
- **Drift 6 (fourth primitive):** **fixed on the client this phase**: `core/state.js` (a "state" object
  with listeners) and `core/events.js` (an unused pub/sub bus) are gone. What the client keeps between
  propagations is the session, ledger L2.
- **Drift 7 (guard-rails):** absent in the project's boundary code: the drivers never drop, truncate or
  repair what a gate returned. A stray (a residue type the client has no renderer for) is logged, not
  drawn (K6). The *gates'* own guards (the inventory guard, ResponseParseGate's sanitising) are game
  rules inside one transformation, decided by the input, not runtime repair of a gate's return.
- **Drift 8 (unnamed concession):** the values that persist and are overwritten — the save files, the
  zone files, the job queue, the client session — are named in the ledger below or in B3.

## Ledger (the project's own)

Each entry cites what it bends. Cause: Instance (chosen; to be retired) or Substrate.

| ID | Concession | Bends | Cause | Thinnest form |
|---|---|---|---|---|
| L1 | **The server Driver holds what it took during one run** — requests still to serve, joins waiting, the settled events — and the game state lives in files it reads and writes (`saves/`, `data/zones/`). | Rule 4 (state lives as residue), K3 | Instance (persistence is a boundary concern, principles *The outside*) | Locals of `Driver::run()`; nothing outlives a request; files are read only through `io_read` and written only through a taken `io_write`. No gate sees either. |
| L2 | **The client keeps the session** `{gameId, playerName, busy}` between propagations. | Rule 4, Drift 8 (a named value that persists and is overwritten) | Instance | One value in `boundary/driver.mjs`, written only from a `session` event a gate returned, read only to build the next entry event (the outside takes and enters again; `client_rejected` carries it too). Not persisted: a reload starts a new session. |
| L3 | **Gates are written as classes** (`GameGate` on the server; modules exporting `signature`/`transform` on the client). | item 1 (exactly three primitives) | Instance | No state (read-only properties, constructor-fixed config in ZonePreGenerationGate only); `gate()` hands the core a plain Gate; nothing in the stream knows the class exists. |

## Recorded departures

| ID | Departure | Rule | How it shows | Fix |
|---|---|---|---|---|
| **G1** | **Server gates throw on a type-corrupted save file.** A save that is valid JSON but has the wrong types (`"player": "x"`, `"inventory": "x"`, `"entities": 5`, `"worldTemplateId": [...]`, `"currentZone": [...]`, Portland `"location": "x"`, `"turnCount": "many"`, `"zones": "z"`) reaches TurnRouteGate, ContextBuildGate, PortlandContextGate, StateUpdateGate, GameStateGate, ZoneContextGate and ZoneStoreGate, which index or iterate it and raise a PHP type error — or a notice, which the production error handler makes a throw (see *Warnings are throws*). | Rule 1 (a gate does not throw), checklist 3 | Core 13 rejects the propagation with SISO_GATE_THREW (nothing committed); the request answers 500. `php tests/probes/g1-corrupt-saves.php`: 7 of 32 flows rejected; with `--warnings` (production): 11 of 32. Same HTTP outcome as 5.1, where it was an uncaught exception. | A pure `Pure\Saves::shapeProblem(array $state): ?string` checked by every gate that receives a save (the `turn_state_loaded`, `turn_apply_loaded`, `game_load_read`, `zone_generate_loaded`, `zone_pregenerate_loaded`, `zones_settled` gates): a save that fails it takes the existing `corrupt` path (404 / `turn_failed` / zone error). One rejection case per gate test. Done when the probe reports 0 in both modes. A phase of its own. |
| **G2** | **One AI reply can break a save for later turns.** ResponseParseGate passes any `statChanges`; StateUpdateGate writes each stat into both `player.stats` and `worldState`, so a stat named `globalFlags` (or `day`, `time`) overwrites the game's own field. The turn answers 200 and is saved; every later turn whose reply sets a flag throws in StateUpdateGate. Found by the independent review; present in 5.1. | Rule 1, checklist 3 | `php tests/probes/g2-stat-keys.php`: statuses 200,500,500. G1's fix (a save shape check) would turn the later turns into a clean `turn_failed`, but the save stays broken. | In ResponseParseGate: keep only stats whose value is a scalar and whose name is not a `worldState` key the game owns (`globalFlags`, `day`, `time`, `date`, `landmarks_visited`); one case in `turn_ai_replied.test.php`. Done when the probe reports 200,200,200. Same phase as G1. |

## Always audited (phase method)

### Single effect — server gates, with their tests as evidence

Every gate's test is `sisoTests/<signature>.test.php`, run through a one-gate core 13 stream. Census
output for this phase (unchanged from 5.1 — no transform changed):

| Signature | Gate | Accepted shape | Rejections |
|---|---|---|---|
| templates_requested | TemplateListGate | io_read→templates_loaded | — |
| templates_loaded | TemplateCatalogGate | http_response 200 | — |
| game_create_requested | GameCreateGate | io_read→game_create_loaded | 4 (422) |
| game_create_loaded | WorldTemplateGate | game_template_ready | 1 (400) |
| game_template_ready | GameInitGate | io_write→game_created{save} | 1 (409) |
| game_created | GameCreatedGate | http_response 201 | 1 (400) |
| game_load_requested | GameLoadGate | io_read→game_load_read | 2 (400) |
| game_load_read | GameStateGate | http_response 200 | 3 (404) |
| action_submitted | ActionParseGate | io_read→turn_state_loaded | 6 (422/400) |
| turn_state_loaded | TurnRouteGate *(classifier)* | generic: io_read→turn_template_loaded / portland: io_read→portland_turn_loaded | 2 (422) |
| turn_template_loaded | ContextBuildGate | ai_call→turn_ai_replied | — |
| portland_turn_loaded | PortlandContextGate | ai_call→turn_ai_replied | — |
| turn_ai_replied | ResponseParseGate | io_read→turn_apply_loaded | 1 (turn_failed) |
| turn_apply_loaded | TurnApplyRouteGate *(classifier)* | generic: generic_turn_parsed / portland: io_read→portland_turn_parsed | 1 (turn_failed) |
| generic_turn_parsed | StateUpdateGate | turn_applied | — (guard discards are values, not shape) |
| portland_turn_parsed | PortlandStateGate | turn_applied | — |
| turn_applied | GameBudgetGate | turn_accounted | — |
| turn_accounted | GameSnapshotGate | io_write→turn_saved{save} | — |
| turn_saved | GameResultGate | http_response 200 | — |
| turn_failed | GameErrorGate | http_response 422 | — |
| zone_generate_requested | ZoneGenerateRequestGate | io_read→zone_generate_loaded | 4 (400/422) |
| zone_generate_loaded | ZoneGenerateCheckGate | io_collect→zones_settled, zone_target | 2 (404/409) |
| zone_pregenerate_requested | ZonePregenerateRequestGate | io_read→zone_pregenerate_loaded | 2 (400/422) |
| zone_pregenerate_loaded | ZonePreGenerationGate | io_collect→zones_settled, zone_target ×N | 2 (404) |
| zone_target | ZoneContextGate | ai_call→zone_ai_replied | — |
| zone_ai_replied | ZoneParseGate | zone_parsed | 1 (zone_failed) |
| zones_settled | ZoneStoreGate | io_write→<respondWith>{zone:*, index, state} | 1 (no save) |
| zone_generate_stored | ZoneGenerateResultGate | http_response 201 | 2 (500) |
| zone_pregenerate_stored | ZonePregenerateResultGate | http_response 200 | — |
| zone_get_requested | ZoneGetGate | io_read→zone_get_read | 2 (400/404) |
| zone_get_read | ZoneGetResultGate | http_response 200 | 2 (404) |
| zone_list_requested | ZoneListGate | io_read→zone_list_read | 1 (400) |
| zone_list_read | ZoneListResultGate | http_response 200 | — |
| zone_delete_requested | ZoneDeleteGate | io_read→zone_delete_loaded | 2 (400/404) |
| zone_delete_loaded | ZoneDeleteApplyGate | io_write→zone_file_deleted{zone} | 1 (404) |
| zone_file_deleted | ZoneIndexRemoveGate | io_write→zone_deleted{index} | 1 (500) |
| zone_deleted | ZoneDeletedGate | http_response 200 | — |

"Empty fan-out" cases (no targets, nothing to write) emit a subset of the accepted shape and are labelled
as such. ZoneStoreGate's reply type (`respondWith`) is chosen by the planner that requested the join and
arrives in the event; the gate passes it through as a return address (Note 4).

### Single effect — client gates

Every client gate's test is `sisoTests/client/<signature>.test.mjs`, run through a one-gate core 13
stream (JavaScript). Census output for this phase:

| Signature | Gate | Accepted shape | Rejections |
|---|---|---|---|
| app_started | TemplatesRequestGate | api_call GET /templates→templates_replied | — |
| templates_replied | WorldOptionsGate | ui_worlds, ui_error_overlay (hidden) | 4 (abort: overlay shown) |
| start_submitted | StartGameGate | session, ui_start_button, api_call POST /game/new→game_create_replied | 1 (drop: busy) |
| game_create_replied | GameStartedGate | session, ui_screen, ui_hud, ui_log | 3 (abort: session restored, button back, error line) |
| command_entered | CommandSendGate | session, ui_busy, ui_status, ui_log, api_call POST /game/{id}/action→command_replied | 4 (drop: empty, not text, busy, no game) |
| command_replied | TurnShownGate | session, ui_busy, ui_status, ui_hud, ui_log | 5 (abort: ERROR, input freed) |
| client_rejected | RejectionShownGate | session, ui_busy, ui_start_button, ui_error_overlay | — |

The aborts release what the input held (the busy flag, the start button) and report the failure; they are
decided by the same reply, so they are not second effects. Each reply gate's test includes hostile JSON
(objects where text is expected). **Census limits** (both censuses): a shape is the *set* of output types
(two events of one type count once), and a case is a rejection because its name starts with
"rejected"; the per-gate test still asserts the whole output, so these limit the census's shape check,
not the tests.

### Boundary is I/O only

Logic outside gates, each a finding with its planned phase:

| # | Where | Logic | Plan |
|---|---|---|---|
| B1 | `src/Http/Router.php`, `Middleware/*` | route matching, CORS headers, JSON body checks | HTTP transport; audit and, where it decides anything about the game, move to gates — *phase: HTTP boundary* |
| B2 | `api.php` `/api/health` | subsystem probes and the healthy/degraded roll-up | same phase as B1 (a `health_requested` gate over probe results) |
| B3 | `src/Jobs/GenerateZoneJob.php` | queue file status transitions, retry scheduling (`attempts`, `retryAt`), job selection, `http_response` → job-result mapping | *phase: jobs as events* — job records as resources, transitions as gates |
| B4 | `worker.php` | CLI argument handling, daemon loop, console output | transport; stays |
| B5 | `src/Boundary/Driver.php` | join readiness (`types`/`where`/`key`/`expect` matching) | generic, declared by the request event; stays (Note 1) |
| B9 | `src/Boundary/Driver.php` | holds what it took during one run (requests to serve, joins waiting, settled events) | boundary state for one request; stays (ledger L1) |
| C2 | `public/js/boundary/driver.mjs` | sorts each take: `api_call` → fetch, `session` → kept, else → render | generic, by type; stays |
| C3 | `public/js/boundary/http.mjs` | fetch; JSON only when the reply says so; errors as data | transport; stays (reading the result is `pure/api.mjs`, in gates) |
| C4 | `public/js/app.js`, `public/js/ui/*.js` | DOM input → entry events (with the session and the boundary's clock for the game id); drawing render instructions as they say (`ui_busy.clearInput` decides the input clear; `ui_screen` re-measures the log); scroll indicators, glitch timer, error-overlay timestamp; `onRejection` logs only | DOM I/O; stays |
| C5 | `public/js/boundary/driver.mjs` | on a rejection, enters `client_rejected` (once; a rejected `client_rejected` is only reported) | generic recovery entry, like a retry at the boundary; what it shows is RejectionShownGate's |
| B6 | `src/Boundary/AnthropicTransport.php` | retry with backoff on transient HTTP failure | transport behaviour; stays |
| B7 | `src/Boundary/MockAiTransport.php` | scripted replies; default reply echoing the action | a test double for the outside world; stays |
| B8 | `src/Boundary/FileStore.php` | resource-kind → path mapping; id and `_index` guard | I/O; stays (second line of defence after the gates) |

The client boundary decides nothing about the game: every choice the old `app.js` made is a gate
(Drift 3 above).

---

## Issues (open)

1. **G1, G2** — see *Recorded departures*. Probes in `tests/probes/`.
2. **Budget limits are not enforced** (Rule-neutral; product). Totals persist in `state.budget`.
   Fix when wanted: a gate before `ai_call` that rejects over-budget turns.
3. **Automatic zone pre-generation after a turn is not wired.**
4. **Pre-generation treats template zones as ungenerated.** Fix: include the template's zone ids in the
   "already exists" set.
5. **Portland has no inventory guard.**
6. **Id-keyed maps with numeric-looking ids** (zone "0") are saved and returned as JSON lists (core
   ledger P6 is the same PHP behaviour).
7. **Concurrent requests on one game** — last save write wins.
8. **Reloading the page loses the client session** (L2 is not persisted). The save is on disk. Fix when
   wanted: persist the session in the boundary and add a `session_restored` entry event and gate.
9. **The dev router serves every file under the project root**, `saves/` included. Dev server only.
10. **Minor:** a failed game start writes its error into the hidden game-screen log (as before); tabs in
    an action are removed, not collapsed; a player name of `<>` becomes empty; job queue dir
    `zones/_queue` shares a namespace with a game id `_queue`; `config.php` reads `../keys.json`
    relative to the working directory.

## Notes (pragmatic departures, not violations)

1. **The join (`io_collect`) is a boundary service, declared by an event.** As 5.1: a planner gate emits
   N `zone_target` branches plus one `io_collect` naming what it waits for; the driver answers it once
   it has taken one `zone_parsed`/`zone_failed` per expected id. Readiness is read from the events, so
   serving order cannot change the result.
2. **The cursor is gone.** 5.1's driver kept a cursor into pending; core 13's take door makes it
   unnecessary — each take holds exactly what the last propagation left.
3. **Continuation pass-through** (unchanged): `usage`/`model` and `action`/`now` ride through a few gates
   that don't use them, by `carry`.
4. **Return-address replies** (unchanged): a gate that asks for I/O names the reply type.
5. **Causality across the boundary is not in the log.** Each reply is a new entry (its own propagation,
   causeId null); the request → reply link is visible only through the reply type and carry.
6. **Validation messages and status codes** are those the old handlers returned, reproduced in gates.

## Phase fixes applied

- Server: moved to the core 13.1.0 PHP instance; the v10.0 hand port (and its retry mechanism) removed;
  per-gate tests now run through the real core.
- Client: rebuilt on the core 13.1.0 JavaScript instance — Drift 3 and Drift 6 fixed on the client;
  double start ignored; footer double-load fixed.
- From the independent review (below): client gates no longer throw on hostile JSON (`pure/text.mjs`);
  a rejected client propagation frees the page (`client_rejected`, RejectionShownGate); `ui_screen` and
  `ui_log` draw the same in either order; the input clear is decided by a gate (`ui_busy.clearInput`);
  the client test helper compares the core's own data, not a JSON copy (undefined and NaN can no longer
  vanish); the vendor test can compare with the release (`SISO_RELEASE`); G1 measured precisely and
  reproducible (`tests/probes/`); G2 recorded; doc references fixed.

## Independent review

A separate agent that had not written the code read principles 2.0 in full, diffed 5.2.0 against 5.1.0,
ran every suite and the browser check, and probed with its own scripts. It reported 5 confirmed defects
and some smaller items; each is resolved or recorded:

| # | Finding | Resolution |
|---|---|---|
| 1 | An AI `statChanges` key such as `globalFlags` is saved and breaks every later flag-setting turn; the audit had said no gate threw on malformed AI replies | Recorded as **G2** with its fix and a probe; item 3 corrected. Present in 5.1. |
| 2 | G1 did not name the warning-to-exception handler, missed `currentZone`, and its count was not reproducible | *Warnings are throws* added; G1 rewritten; `tests/probes/g1-corrupt-saves.php` added (7 / 11 of 32). GameStateGate stays listed: the probe shows it throwing on `"entities": 5`. |
| 3 | `String(v)` in client gates throws on hostile JSON, and a rejected reply then left the page busy | Fixed: `pure/text.mjs`; `client_rejected` + RejectionShownGate; tests and scenarios added. |
| 4 | `ui_screen` + `ui_log` scrolled differently depending on draw order (item 6) | Fixed: `Output.settle()` on `ui_screen`; the review's probe now gives the same result in both orders. |
| 5 | The vendor test proves only siso/ = pin | Documented; optional `SISO_RELEASE` byte comparison added (6/6 against the release). |
| 6 | Debug `history` shape changed; item 12 wording | Changelog notes the shape; item 12 corrected (history is on failures only, as in 5.1). |
| 7 | Client helper's JSON round-trip could hide undefined/NaN; census compares type sets and trusts names | Helper fixed; census limits recorded above. Prompt-gate tests using `assertContains` predate 5.2 (unchanged). |
| 8 | Doc slips (C1 label, `VENDORED.md`, this placeholder) | Fixed. |
| 9 | Small unlisted client changes; two decisions outside gates | Listed in the changelog; the input clear moved to a gate; `onRejection` now only logs. |

The review found nothing wrong with the Driver port (same serving order, join results and 500s on every
probe), the 37 gate bodies (unchanged), XSS (no `innerHTML` in `public/`), the other UI behaviours, L1,
L3, or the server per-gate tests.

## Verdict

**Compliant with SISO Principles — core 2.0, with two recorded departures (G1, G2).** Items 1–2 and 4–15
hold. Item 3 holds for state and side effects everywhere, and for throwing in the client; on the server,
gates can throw on a type-corrupted save (G1) and after an AI reply that overwrites a reserved
`worldState` key (G2). Both are recorded with their fix and a probe, and are the next phase. No drift
pattern is present in the gates; the boundary findings B1–B3 remain planned for later phases.
