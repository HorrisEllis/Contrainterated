# AI Game Master — SISO

An AI-narrated text adventure engine. Players pick a world and type actions; Claude narrates what happens
and proposes state changes; the game applies the changes it can verify and saves the state.

Version **5.2.0** (`VERSION`). Built on **SISO core 13.1.0**, vendored unchanged in `siso/`: the core's
**PHP instance** runs the back end and its **JavaScript instance** runs the client. Audited against
**SISO Principles — core 2.0** (`doc/SISO-principles.md`; audit in `doc/conformance.md`: compliant, two
recorded departures, G1 and G2 — server gates that can still throw on a corrupt save or a hostile AI
stat key; probes in `tests/probes/`).

## Project status

| Phase | Summary |
|---|---|
| 1 | SISO framework, events, gates, state persistence, world templates |
| 2 | AI integration (Claude API, response parsing, mock AI, token tracking) |
| 3 | REST API (router, handlers, middleware) |
| 4 | Zone generation and pre-generation, background job queue |
| 5 | Frontend split into PHP/CSS/JS; two-layer inventory guard |
| 5.1 | Port to SISO core v10.0 with full conformance — pure gates, I/O at the boundary, per-gate tests |
| **5.2** | **SISO core 13.1.0 for PHP and JavaScript** — the client rebuilt on the JS core |

The phase roadmap is in `SPEC.md`; the current specification is `doc/specs/spec-v5.2.0.md`; what changed
in 5.2 is in `doc/changelogs/changelog-v5.2.0.md`.

## How it works

SISO has three primitives: **Event**, **Gate**, **Stream**. A gate is a pure function from one event to
zero, one or many events, and the stream routes each event to the one gate registered for its type.
Events no gate claims settle as **residue**, and the outside **takes** the residue to read the answer.

- Every request becomes **one entry event** (`action_submitted`, `game_create_requested`, …) entered into a
  stream that holds all 37 gates.
- Gates never touch files, the network, the clock or randomness, and never throw. When one needs
  something from outside, it returns a **request event** — `io_read`, `io_write`, `ai_call`,
  `io_collect` — that no gate claims, so it settles as residue.
- The **Driver** (`src/Boundary/Driver.php`) takes the residue after each propagation, performs each
  request (files via `FileStore`, the AI via an `AiTransport`), and enters the **reply event** the request
  named. That reply triggers the next gate.
- The flow ends with an **`http_response`** event, built by a gate; the handler just sends it. A gate that
  throws or loops rejects its propagation (nothing is committed) and the handler answers 500.

A player turn, for example:

```
action_submitted ─ActionParseGate→ io_read{save} ⇒ turn_state_loaded ─TurnRouteGate→ io_read{template}
 ⇒ turn_template_loaded ─ContextBuildGate→ ai_call ⇒ turn_ai_replied ─ResponseParseGate→ io_read{save}
 ⇒ turn_apply_loaded ─TurnApplyRouteGate→ generic_turn_parsed ─StateUpdateGate→ turn_applied
 ─GameBudgetGate→ turn_accounted ─GameSnapshotGate→ io_write{save} ⇒ turn_saved ─GameResultGate→ http_response
```

(⇒ is the boundary serving a request.) Portland games take `portland_turn_loaded` /
`portland_turn_parsed` instead, chosen by the two routing gates from the save's world id.

The full list of gates, their signatures and tests is in `doc/specs/spec-v5.2.0.md`.

### The client

The page (`public/js`) runs the same model on the core's JavaScript instance. Each user action becomes
one entry event carrying the client session (`{gameId, playerName, busy}`) into a stream of 7 client
gates. Gates return `api_call` (the server), `ui_*` render instructions (the page) and `session`; the
client driver (`public/js/boundary/driver.mjs`) takes them, fetches and enters the reply, draws with
`public/js/ui/*.js`, and keeps the session for the next entry.

```
page load ─ app_started ─TemplatesRequestGate→ api_call ⇒ templates_replied ─WorldOptionsGate→ ui_worlds
Start     ─ start_submitted ─StartGameGate→ api_call ⇒ game_create_replied ─GameStartedGate→ ui_screen, ui_hud, ui_log
command   ─ command_entered ─CommandSendGate→ api_call ⇒ command_replied ─TurnShownGate→ ui_hud, ui_log, ui_status
```

## Running

```bash
# Development server (frontend + API)
php -S localhost:8080 router.php

# Environment
export ANTHROPIC_API_KEY="your-api-key"     # or ../keys.json: {"anthropic": {"key": "...", "models": ["..."]}}
export GAMEMASTER_MOCK_AI="true"            # use the mock AI (automatic when no key is configured)
export GAMEMASTER_DEBUG="true"              # include the stream log in error responses
```

Zone job worker:

```bash
php worker.php              # process the queue once
php worker.php --daemon     # keep processing
php worker.php --status     # queue counts
php worker.php --cleanup    # remove finished jobs older than a day
```

## Using it from PHP

```php
use GameMaster\GameStreamFactory;
use Siso\Core\Event;

$factory = new GameStreamFactory(['useMockAI' => true]);

// Any flow: enter its entry event through a driver and read the http_response.
// (A rejected propagation throws Siso\Core\Rejection.)
$settled = $factory->createDriver()->run(new Event('action_submitted', [
    'gameId' => 'game-123',
    'action' => 'examine the ancient door',
    'now'    => date('c'),
]));
foreach ($settled as $event) {
    if ($event->type === 'http_response') {
        echo $event->data['body']['data']['narrative'];
    }
}
```

Scripted AI replies (as the tests do):

```php
use GameMaster\Boundary\MockAiTransport;

$ai = new MockAiTransport([[
    'narrative' => 'The door creaks open, revealing a hidden chamber!',
    'stateUpdates' => ['flagChanges' => ['door_opened' => true]],
    'suggestedActions' => ['Enter chamber', 'Go back'],
]]);
$settled = $factory->createDriver($ai)->run($entryEvent);
```

## Configuration (`GameStreamFactory`)

| Option | Default | Description |
|---|---|---|
| `worldTemplatesPath` | `data/worlds` | World template JSON files |
| `savesPath` | `saves` | Game saves (`game-{id}.json`) |
| `zonesPath` | `data/zones` | Generated zones (`{gameId}/{zoneId}.json`, `_index.json`) |
| `aiEndpoint` | Anthropic Messages API | AI endpoint |
| `aiKey` | keys.json / `ANTHROPIC_API_KEY` | API key |
| `aiModel` | `claude-sonnet-4-20250514` | Model |
| `aiMaxTokens` | 2048 | Max tokens per reply |
| `aiTimeout` | 30 | Request timeout (s) |
| `aiMaxRetries` | 2 | Attempts on transient failures |
| `useMockAI` | false | Use `MockAiTransport` |
| `maxZonePreGenerate` | 3 | Zones generated per pre-generation request |

Token use and estimated cost accumulate per game in the save (`budget`); no limits are enforced.

## Game state

```json
{
  "gameId": "game-123",
  "worldTemplateId": "derelict_spaceship",
  "currentZone": "bridge",
  "zoneDescription": "The ship's bridge...",
  "turnCount": 5,
  "player": { "name": "Explorer", "inventory": ["flashlight"], "stats": { "health": 100 } },
  "entities": [{ "id": "terminal", "name": "Ship Terminal", "present": true, "properties": { "powered": false } }],
  "worldState": { "day": 1, "globalFlags": { "power_restored": false } },
  "profile": { "actionHistory": [{ "turn": 5, "action": "examine terminal" }], "observations": [] },
  "budget": { "sessionTokens": 12000, "sessionCost": 0.05, "lastTurnTokens": 2400 }
}
```

The AI is asked for:

```json
{
  "narrative": "The terminal flickers to life...",
  "stateUpdates": {
    "entityChanges": [{ "entityId": "terminal", "properties": { "powered": true } }],
    "inventoryChanges": ["add:data_chip"],
    "statChanges": { "oxygen": -5 },
    "flagChanges": { "terminal_accessed": true }
  },
  "suggestedActions": ["Read logs", "Download data", "Power down"],
  "profileInsight": "Player prefers exploration over combat"
}
```

**Inventory guard (Phase 5):** `remove:X` is ignored unless the player holds X; `add:X` is ignored unless
X names an entity known to the game (by name or id) or one changed this turn.

**Portland:** the AI also returns `location`, `timeAdvance`, `currentTime`, `moneyChange`,
`landmarkFound` and `streetViewLink`; each is type-checked, and a landmark only counts if the template
defines it.

## REST API

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/health` | Health check |
| GET | `/api/templates` | List world templates |
| POST | `/api/game/new` | Create a game `{worldTemplateId, playerName, gameId?, overwrite?}` |
| GET | `/api/game/{gameId}` | Game state (`?full=true` for the whole save) |
| POST | `/api/game/{gameId}/action` | Play a turn `{action}` |
| POST | `/api/action` | Play a turn `{gameId, action}` |
| POST | `/api/game/{gameId}/zone/generate` | Generate a zone `{zoneId, parentZoneId?, direction?, constraints?}` |
| POST | `/api/game/{gameId}/zone/pregenerate` | Generate the zones behind a zone's open exits `{fromZoneId}` |
| GET | `/api/game/{gameId}/zone/{zoneId}` | One zone |
| GET | `/api/game/{gameId}/zones` | Zone index |
| DELETE | `/api/game/{gameId}/zone/{zoneId}` | Delete a zone |

Game and zone ids are 1–100 characters of `A–Z a–z 0–9 _ -`.

```bash
curl -X POST http://localhost:8080/api/game/new -H "Content-Type: application/json" \
  -d '{"worldTemplateId": "derelict_spaceship", "playerName": "Captain", "gameId": "my-game-123"}'
# 201 {"success":true,"message":"Game created successfully","data":{"gameId":"my-game-123", "narrative":"...", ...}}

curl -X POST http://localhost:8080/api/game/my-game-123/action -H "Content-Type: application/json" \
  -d '{"action": "examine the control panel"}'
# 200 {"success":true,"data":{"narrative":"...","suggestedActions":[...],"player":{...},"turnCount":1,
#      "currentZone":"bridge","lastSaved":"2026-09-28T10:00:00+00:00"},"error":null,"meta":{"tokensUsed":2400,"turnTokens":2400}}

curl -X POST http://localhost:8080/api/game/my-game-123/zone/pregenerate -H "Content-Type: application/json" \
  -d '{"fromZoneId": "forest_clearing"}'
# 200 {"success":true,"message":"Success","data":{"fromZoneId":"forest_clearing","requested":2,
#      "generated":[{"zoneId":"cave","name":"Dark Cave"}, ...],"errors":[]}}
```

Zone data:

```json
{
  "id": "forest_clearing",
  "name": "Sunlit Clearing",
  "description": "You emerge into a peaceful clearing...",
  "entities": [{ "id": "old_tree", "name": "Ancient Oak", "description": "...", "properties": {}, "present": true }],
  "exits": [{ "direction": "north", "targetZoneId": null, "description": "An overgrown trail.", "locked": false, "requirements": [] }],
  "properties": { "lighting": "bright", "danger_level": 1, "ambient_sounds": ["birdsong"], "special_features": [] },
  "parentZoneId": "entrance", "direction": "north", "generated": "2026-09-28T10:00:00+00:00", "tokensUsed": 900
}
```

Background zone jobs:

```php
use GameMaster\Jobs\GenerateZoneJob;

$job = new GenerateZoneJob($factory);
$job->queue('game-123', 'forest_north', 'forest_clearing', 'north');
$results = $job->processQueue(10);   // runs the same flow as POST …/zone/generate
```

## Tests

Needs PHP 8.1+ and Node 18+.

```bash
php run_tests.php                          # every suite; -v for full output
php sisoTests/census.php                   # server gate census: every gate has exactly one test, and it passes
node sisoTests/client/census.mjs           # client gate census
php sisoTests/action_submitted.test.php    # one server gate's test
node sisoTests/client/command_entered.test.mjs   # one client gate's test
NODE_PATH=$(npm root -g) node tests/browser.e2e.mjs   # optional: headless Chromium via Playwright
```

| Suite | What |
|---|---|
| `siso/` core suites | the core's own PHP and JS suites and the shared fixture suite, run from `siso/` |
| `tests/siso-vendor.test.php` | `siso/` is exactly core 13.1.0 (hash pin in `doc/siso-core-13.1.0.sha256`) |
| `sisoTests/<signature>.test.php` | one per server gate: only that gate runs on a core stream, whole output asserted |
| `sisoTests/client/<signature>.test.mjs` | the same for each client gate |
| `tests/client-scenarios.test.mjs` | the client gates and driver with a scripted server |
| `tests/templates.test.php` | world template data |
| `tests/scenarios.test.php` | end to end through the handlers, temp files, scripted mock AI |

No PHPUnit, no npm packages; PHP warnings fail a test, as they fail a request in `api.php`.

**Upgrading the core:** replace `siso/` with the new release's `VERSION`, `LICENSE`, `fixtures/`,
`php/{code,sisoTests}` and `js/{code,sisoTests}`, regenerate the pin file, and run `php run_tests.php`.

## Project structure

```
siso/            SISO core 13.1.0, vendored unchanged (php/, js/, fixtures/)
src/GameGate.php how a gate is written here (signature + transform → core Gate)
src/Gates/       37 pure gates
src/Events/Io.php boundary request events
src/Pure/        pure helpers used by gates
src/Boundary/    Driver, FileStore, AI transports — all I/O
src/Http/        Request, Response, Router, Middleware, Handlers (one entry event each)
src/Jobs/        zone job queue
sisoTests/       per-gate tests, census, fixtures; client/ — the client gates' tests and census
tests/           vendor pin, template, scenario and client-scenario suites; browser.e2e.mjs; probes/ (G1, G2)
doc/             principles, conformance, core pin, specs, changelogs
data/worlds/     world templates      saves/  game saves      data/zones/  generated zones
public/js/       app.js; gates/ (7 client gates); pure/; events/; boundary/ (driver, http); ui/ (DOM)
public/          css/, templates/     index.php, api.php, router.php, worker.php
```

## License

MIT
