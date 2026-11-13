# GameMaster — Phased Development Specification

Each phase is sized to fit in a single prompt.  
Each phase ends with: a changelog, a file manifest, a test suite entry, and a deliverable zip.

---

> **v5.2.0 — core-13 upgrade (2026-10-20).** The back end and the client run on SISO core 13.1.0
> (vendored in `siso/`), audited against SISO Principles — core 2.0. For the phases below:
>
> - Server gates extend `GameMaster\GameGate` (signature + `transform()`); register them in
>   `GameStreamFactory`. Gates never throw (a throw rejects the propagation).
> - Client work (Phases 6–10 frontends) is client gates in `public/js/gates` returning `api_call`,
>   `ui_*` and `session` events, with one `sisoTests/client/<signature>.test.mjs` each; drawing goes in
>   `public/js/ui/`. E.g. Phase 6's inventory panel and map are new `ui_*` events from TurnShownGate /
>   GameStartedGate plus renderers; Phase 7's save picker is new client gates over the new endpoints.
> - Rule 3 below is revised accordingly. See `doc/specs/spec-v5.2.0.md`.

---

> **v5.1.0 — core-10 port (2026-09-28).** Between Phase 5 and Phase 6 the backend moved to SISO core
> v10.0 with full conformance to SISO-principles v1.2. What this means for the phases below:
>
> - A gate is a pure `transform(Event)`. Anything the phases below describe a gate doing with files,
>   HTTP, the clock or a shared log (e.g. Phase 7's `SaveSlotGate` "manages saves/…", Phase 9's
>   `BroadcastGate` "writes to a shared log file", `SessionGate` enforcing turn order from stored
>   sessions) is done by gates that emit `io_read`/`io_write` requests, served by `src/Boundary/Driver`.
>   Handlers build one entry event and send the gate-built `http_response`.
> - Tests: every gate gets `sisoTests/<signature>.test.php` (checked by `sisoTests/census.php`);
>   end-to-end cases go in `tests/scenarios.test.php` or a new `tests/*.test.php` added to
>   `run_tests.php`. The `tests/Phase{N}*.php` + "QuickTest" convention in rule 5 is retired.
> - Phase docs live in `doc/`: `doc/specs/spec-v{version}.md`, `doc/changelogs/changelog-v{version}.md`,
>   `doc/conformance.md`; the version is in `VERSION`.
>
> See `doc/specs/spec-v5.1.0.md` for the current architecture.

---

## Completed Phases (baseline for this spec)

| Phase | Summary |
|-------|---------|
| 1 | SISO core framework — events, gates, stream, state persistence |
| 2 | AI integration — AIGenerateGate, ResponseParseGate, MockAIGate, BudgetGate |
| 3 | REST API layer — Router, Handlers, Middleware |
| 4 | Zone pre-generation — ZoneContextGate, ZoneParseGate, ZoneStoreGate, background jobs |

---

## Phase 5 — Frontend Restructure + Inventory Guard

**Goal:** Split the monolithic `index.html` into a proper PHP/CSS/JS file structure.  
Fix the hallucination bug where non-existent items appeared in inventory.

### What to build

#### File structure
- `index.php` — PHP entry point using `include` for header/footer
- `public/templates/header.php` — shared `<head>`, CSS links
- `public/templates/footer.php` — closing tags, optional script injection
- `public/css/terminal.css` — CSS variables, CRT chrome, resets
- `public/css/components.css` — buttons, inputs, chips, stats panel, spinners
- `public/css/screens.css` — start screen, game screen, output log, overlays
- `public/css/animations.css` — all `@keyframes` in one file
- `public/js/core/state.js` — client state singleton (no DOM, no fetch)
- `public/js/core/events.js` — typed client event bus (mirrors SISO pattern)
- `public/js/api/client.js` — all `fetch` calls, returns `{ok, data, error}`
- `public/js/ui/output.js` — narrative log, scroll indicators
- `public/js/ui/stats.js` — HUD stats bar, action chips
- `public/js/ui/overlays.js` — processing spinner, connection-error overlay
- `public/js/app.js` — entry point, wires all modules via ES module imports
- `router.php` — updated to serve `/public/` static files with correct MIME types

#### Backend fix: two-layer inventory guard
- `src/Gates/ContextBuildGate.php` — system prompt gains `ITEM AND ENTITY CONSTRAINTS` block:
  - Injects canonical inventory list verbatim
  - Injects canonical entity ID list
  - Tells AI it may only `remove:X` items it can see in the list
  - Tells AI it may only `add:X` items anchored to a scene entity
  - Frames refusal as good gameplay, not failure
- `src/Gates/StateUpdateGate.php` — enforcement layer:
  - `remove:X` silently discarded if X not in current inventory
  - `add:X` checked against entity names and IDs; discarded if no match
  - NPC transfers allowed if the entity appears in `entityChanges` this turn

#### Tests
- `tests/Phase5InventoryGuardTest.php` — SISO-native test class (no PHPUnit)
- `run_tests.php` — `Phase5QuickTest` registered

### Changelog.5
```
[ADDED]   public/css/terminal.css
[ADDED]   public/css/components.css
[ADDED]   public/css/screens.css
[ADDED]   public/css/animations.css
[ADDED]   public/js/core/state.js
[ADDED]   public/js/core/events.js
[ADDED]   public/js/api/client.js
[ADDED]   public/js/ui/output.js
[ADDED]   public/js/ui/stats.js
[ADDED]   public/js/ui/overlays.js
[ADDED]   public/js/app.js
[ADDED]   public/templates/header.php
[ADDED]   public/templates/footer.php
[ADDED]   index.php
[ADDED]   tests/Phase5InventoryGuardTest.php
[CHANGED] src/Gates/ContextBuildGate.php — inventory + entity constraint prompt block
[CHANGED] src/Gates/StateUpdateGate.php — add/remove validation layer
[CHANGED] router.php — /public/ static file MIME handling
[CHANGED] run_tests.php — Phase5QuickTest registered
[REMOVED] index.html (replaced by index.php)
```

### File Manifest.5
```
gamemaster/
├── index.php
├── router.php
├── api.php
├── autoload.php
├── run_tests.php
├── worker.php
├── composer.json
├── phpunit.xml
├── public/
│   ├── templates/
│   │   ├── header.php
│   │   └── footer.php
│   ├── css/
│   │   ├── terminal.css
│   │   ├── components.css
│   │   ├── screens.css
│   │   └── animations.css
│   └── js/
│       ├── app.js
│       ├── core/
│       │   ├── state.js
│       │   └── events.js
│       ├── api/
│       │   └── client.js
│       └── ui/
│           ├── output.js
│           ├── stats.js
│           └── overlays.js
├── src/
│   ├── GameStreamFactory.php
│   ├── Events/  (unchanged)
│   ├── Gates/
│   │   ├── ContextBuildGate.php  ← changed
│   │   ├── StateUpdateGate.php   ← changed
│   │   └── ... (all others unchanged)
│   ├── Http/    (unchanged)
│   └── Jobs/    (unchanged)
├── tests/
│   ├── Phase5InventoryGuardTest.php  ← new
│   ├── Gates/   (unchanged)
│   └── Integration/ (unchanged)
├── data/
│   └── worlds/
│       ├── derelict_spaceship.json
│       ├── portland_scavenger.json
│       ├── shall_we_play_a_game.json
│       └── the_matrix.json
└── saves/
```

---

## Phase 6 — Inventory Panel + Zone Map UI

**Goal:** Make the game UI richer without changing any backend gates.  
Add a persistent inventory sidebar, a zone-connection mini-map,  
and a theme switcher (green / amber / blue CRT palettes).

### What to build

#### New files
- `public/css/inventory.css` — inventory panel styles
- `public/css/map.css` — zone mini-map styles
- `public/js/ui/inventory.js` — inventory panel: renders items, supports click-to-use
- `public/js/ui/map.js` — zone mini-map: renders known zones as SVG nodes with edges
- `public/js/ui/theme.js` — theme switcher: swaps CSS custom property values

#### Changed files
- `index.php` — add inventory panel and map panel to the game screen layout
- `public/css/screens.css` — two-column game layout (log | sidebar)
- `public/js/app.js` — call `Inventory.update()` and `Map.update()` after each action
- `public/templates/header.php` — add inventory.css and map.css links

#### Backend: no changes

#### Tests
- `tests/Phase6UITest.php` — JavaScript unit tests run via PHP-driven headless check  
  (assert JS modules export expected symbols; validate theme token values)

### Changelog.6
```
[ADDED]   public/css/inventory.css
[ADDED]   public/css/map.css
[ADDED]   public/js/ui/inventory.js
[ADDED]   public/js/ui/map.js
[ADDED]   public/js/ui/theme.js
[ADDED]   tests/Phase6UITest.php
[CHANGED] index.php — two-column layout, inventory panel, map panel
[CHANGED] public/css/screens.css — sidebar layout
[CHANGED] public/js/app.js — inventory + map update calls
[CHANGED] public/templates/header.php — new CSS links
[CHANGED] run_tests.php — Phase6QuickTest registered
```

### File Manifest.6
```
gamemaster/
├── ... (all Phase 5 files)
├── public/
│   ├── css/
│   │   ├── ... (Phase 5)
│   │   ├── inventory.css   ← new
│   │   └── map.css         ← new
│   └── js/
│       ├── ... (Phase 5)
│       └── ui/
│           ├── ... (Phase 5)
│           ├── inventory.js  ← new
│           ├── map.js        ← new
│           └── theme.js      ← new
└── tests/
    ├── ... (Phase 5)
    └── Phase6UITest.php  ← new
```

---

## Phase 7 — Save Slots + Game History

**Goal:** Support multiple named save slots per world. Add a game-history screen  
that replays past turns. No AI changes.

### What to build

#### Backend
- `src/Gates/SaveSlotGate.php` — manages `saves/{gameId}/{slotName}.json` layout
- `src/Http/Handlers/SaveHandler.php` — `POST /api/game/{id}/save/{slot}`, `GET /api/game/{id}/saves`, `POST /api/game/{id}/load/{slot}`
- `api.php` — register save/load routes

#### Frontend
- `public/js/ui/saves.js` — save slot picker modal
- `public/js/ui/history.js` — turn-history replay viewer
- `public/css/modal.css` — shared modal chrome

#### Tests
- `tests/Phase7SaveSlotTest.php` — round-trip save/load, slot collision, slot limit

### Changelog.7
```
[ADDED]   src/Gates/SaveSlotGate.php
[ADDED]   src/Http/Handlers/SaveHandler.php
[ADDED]   public/js/ui/saves.js
[ADDED]   public/js/ui/history.js
[ADDED]   public/css/modal.css
[ADDED]   tests/Phase7SaveSlotTest.php
[CHANGED] api.php — save/load routes
[CHANGED] index.php — save/history buttons in game header
[CHANGED] public/js/app.js — save/load wiring
[CHANGED] run_tests.php — Phase7QuickTest registered
```

---

## Phase 8 — World Editor

**Goal:** In-browser JSON world template editor.  
Create, edit, and validate world templates without touching the filesystem.

### What to build

#### Backend
- `src/Http/Handlers/WorldEditorHandler.php` — `GET /api/templates/{id}`, `PUT /api/templates/{id}`, `DELETE /api/templates/{id}`, `POST /api/templates/validate`
- `src/Gates/WorldValidatorGate.php` — deep template validation (zone graph reachability, stat schema, rule length limits)
- `api.php` — register editor routes

#### Frontend
- `public/js/ui/editor.js` — template editor: form-driven zone builder, raw JSON toggle
- `public/css/editor.css` — editor panel styles
- `public/templates/editor.php` — standalone editor page (uses header/footer)

#### Tests
- `tests/Phase8WorldEditorTest.php` — validate/invalidate template edge cases, zone graph with unreachable node

### Changelog.8
```
[ADDED]   src/Http/Handlers/WorldEditorHandler.php
[ADDED]   src/Gates/WorldValidatorGate.php
[ADDED]   public/js/ui/editor.js
[ADDED]   public/css/editor.css
[ADDED]   public/templates/editor.php
[ADDED]   tests/Phase8WorldEditorTest.php
[CHANGED] api.php — world editor routes
[CHANGED] run_tests.php — Phase8QuickTest registered
```

---

## Phase 9 — Multiplayer Sessions (Shared State)

**Goal:** Two players share one game via a session token.  
Turn order is enforced server-side. Each player sees the same narrative log.

### What to build

#### Backend
- `src/Gates/SessionGate.php` — validates session token, enforces turn order
- `src/Gates/BroadcastGate.php` — after each `GameResponseEvent`, writes to a shared log file polled by all clients
- `src/Http/Handlers/SessionHandler.php` — `POST /api/session/new`, `POST /api/session/{token}/join`, `GET /api/session/{token}/poll`
- `api.php` — register session routes

#### Frontend
- `public/js/ui/session.js` — session join/create modal, turn indicator, live poll loop
- `public/css/session.css` — session HUD styles

#### Tests
- `tests/Phase9MultiplayerTest.php` — two-player turn sequence, out-of-turn rejection, poll response format

### Changelog.9
```
[ADDED]   src/Gates/SessionGate.php
[ADDED]   src/Gates/BroadcastGate.php
[ADDED]   src/Http/Handlers/SessionHandler.php
[ADDED]   public/js/ui/session.js
[ADDED]   public/css/session.css
[ADDED]   tests/Phase9MultiplayerTest.php
[CHANGED] api.php — session routes
[CHANGED] public/js/app.js — session awareness
[CHANGED] run_tests.php — Phase9QuickTest registered
```

---

## Phase 10 — Admin Dashboard

**Goal:** Password-protected admin panel showing live game stats, budget usage,  
active sessions, and log tail. No game logic changes.

### What to build

#### Backend
- `src/Http/Handlers/AdminHandler.php` — `GET /admin/stats`, `GET /admin/games`, `GET /admin/logs`
- `src/Http/Middleware/AuthMiddleware.php` — HTTP Basic auth for `/admin/*`
- `api.php` — register admin routes behind auth middleware

#### Frontend
- `public/templates/admin.php` — admin dashboard page
- `public/js/ui/admin.js` — live-refresh stats table, log tail
- `public/css/admin.css` — dashboard table and panel styles

#### Tests
- `tests/Phase10AdminTest.php` — auth rejection without credentials, stats endpoint shape

### Changelog.10
```
[ADDED]   src/Http/Handlers/AdminHandler.php
[ADDED]   src/Http/Middleware/AuthMiddleware.php
[ADDED]   public/templates/admin.php
[ADDED]   public/js/ui/admin.js
[ADDED]   public/css/admin.css
[ADDED]   tests/Phase10AdminTest.php
[CHANGED] api.php — admin routes with auth middleware
[CHANGED] run_tests.php — Phase10QuickTest registered
```

---

## Cross-cutting rules (apply to every phase)

1. **One prompt per phase.** If a phase would require two prompts to implement fully, split it.
2. **CSS separation.** All styles live under `public/css/`. No inline styles except generated SVG.
3. **JS separation.** All scripts live under `public/js/`. Organised as `gates/` (pure client gates),
   `pure/`, `events/`, `boundary/` (driver, http) and `ui/` (DOM renderers); the SISO core is imported from
   `siso/js/code/core`. *(Revised in v5.2.0; was `core/`, `api/`, `ui/`.)*
4. **PHP includes.** Every HTML page uses `header.php` / `footer.php`. No inline `<!DOCTYPE>`.
5. **SISO tests.** Every gate has one test in `sisoTests/<signature>.test.php` (client gates:
   `sisoTests/client/<signature>.test.mjs`), written with the gate; both censuses must pass. Scenario tests go in `tests/`. No PHPUnit required to run them. *(Revised in v5.1.0; was `tests/Phase{N}*.php`.)*
6. **No phase breaks a previous phase.** All existing tests must pass after each phase.
7. **Changelogs use `[ADDED]`, `[CHANGED]`, `[REMOVED]`, `[FIXED]` tags only.**
8. **File manifests show the full tree**, not just new files.
9. **Zips include all files to date**, not just the delta.
