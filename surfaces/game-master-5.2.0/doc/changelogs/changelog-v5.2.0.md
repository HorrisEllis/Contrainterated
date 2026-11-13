# Changelog — v5.2.0

**Date:** 2026-10-20
**Previous version:** 5.1.0 (SISO core v10.0 PHP port, SISO-principles v1.2)
**Phase:** core-13 upgrade — move the back end and the client to SISO core 13.1.0 (SISO Principles — core 2.0)

---

## Summary

The project now runs on **SISO core 13.1.0**, vendored unchanged in `siso/`. The **PHP instance** runs the
back end (it replaces the hand port of core v10.0 in `src/Siso`). The **JavaScript instance** runs the
client, which was rebuilt on it: every decision the page made in `app.js` (what to fetch, what to show,
when to ignore input) is now one of 7 pure client gates with its own test, and fetch and the DOM are the
client's boundary. The REST contract and the UI are unchanged.

Numbers (from `php run_tests.php` and the optional browser check, this phase):

| Suite | 5.1.0 | 5.2.0 |
|---|---|---|
| SISO core | siso-core 35/35, siso-streamlog 25/25 (v10.0 hand port) | core PHP: run: 21 suites, 371/371 passed · core JS: run: 21 suites, 376/376 passed |
| Shared fixture suite | — | fixtures: 15 files, 92/92 steps passed (PHP and JS) |
| Vendored core pin | — | siso-vendor: 6/6 passed (and 6/6 against the release with SISO_RELEASE) |
| Server gate census | census: 37/37 passed (147 cases, gates called directly) | census: 37/37 passed (147/147 cases, each through a one-gate core 13 stream) |
| Client gate census | — (no client gates) | census (client): 7/7 passed (41/41 cases) |
| Client scenarios | — | client-scenarios: 9/9 passed |
| Templates · scenarios | 4/4 · 18/18 | templates: 4/4 passed · scenarios: 18/18 passed |
| Browser (optional) | headless check by hand | browser-e2e: 5/5 passed |

---

## Core (siso/) — what moved from v10.0 to 13.1.0

What the new core changes for a project, and how this one meets it:

| Core 13 change | Here |
|---|---|
| `Gate` is a final value: `new Gate(signature, transform, shape)`; gates can no longer be subclasses | Each gate class extends **`GameMaster\GameGate`** (signature + `transform()`), whose `gate()` hands the core that pair. The 37 gate bodies are unchanged. |
| Pending → **residue**; the **take** door (`takeResidue()`) is the only way an answer leaves | The Driver takes the residue after every propagation, instead of keeping a cursor into pending. |
| Retry left the core; acyclicity is strict and enforced (self-loops and cycles reject) | No gate used retries. The `retries` field is gone from Event and from the tests' expected output. |
| A gate does not throw; a throw, a cycle or a non-event return **rejects** the propagation, committing nothing (K5) | The handler catches `Siso\Core\Rejection`, logs its code and answers 500 (as an exception did before). See G1 for the gates that can still throw. |
| The log is an **observer** (`Siso\Observer\StreamLog`, attached with `attach($log->observer(label))`) | Debug mode attaches the log's observer; the debug body still carries `history`. |
| Event data must be plain data, checked at construction; events are read-only | All event data here was already arrays and scalars. |
| An instance passes the shared fixture suite | Both instances' fixture runs are in `run_tests.php`. |

- **[ADDED] siso/** — core 13.1.0's `VERSION`, `LICENSE`, `fixtures/`, `php/code`, `php/sisoTests`,
  `js/code`, `js/sisoTests`, byte-identical to the release (126 files).
- **[ADDED] doc/siso-core-13.1.0.sha256** — the pin; **[ADDED] tests/siso-vendor.test.php** checks it.
- **[REMOVED] src/Siso/Core/** (the v10.0 port), **tests/siso-core.test.php**, **tests/siso-streamlog.test.php**
  (replaced by the core's own suites).
- **[CHANGED] doc/SISO-principles.md** — v1.2 → **core 2.0**, copied from the core release.

## Back end (PHP)

- **[ADDED] src/GameGate.php** — how a gate is written here (see above). Read-only instances.
- **[CHANGED] src/Gates/*.php (37)** — `extends Gate` → `extends GameGate`; `ZoneParseGate` docblock.
  No transform changed.
- **[CHANGED] src/GameStreamFactory.php** — `definition($sig)` returns the GameGate, `gate($sig)` the core
  Gate; `createStream()` registers core gates and attaches a log's observer.
- **[CHANGED] src/Boundary/Driver.php** — enter → take → serve: requests, joins and settled events are
  sorted from each take; the cursor is gone. A Rejection passes to the caller.
- **[CHANGED] src/Http/Handlers/StreamEndpoint.php** — catches `Rejection` (code, event type and the
  thrown cause go to the log; debug body shows the code); `Siso\Observer\StreamLog`.
- **[CHANGED] src/Events/Io.php** — docblock: requests settle as residue and are taken.
- **[CHANGED] autoload.php, composer.json** — the core is required from `siso/php/code`; `Siso\` is no
  longer PSR-4 mapped.
- **[CHANGED] api.php, worker.php** — the core-present checks point at `siso/`; `/api/health` reports the
  core version.
- **[CHANGED] sisoTests/helper.php** — `run()` registers the gate alone on a core 13 stream, enters one
  event and takes the residue, so the real core judges every case. **sisoTests/census.php** reads gate
  classes through `definition()`. **sisoTests/*.test.php** — `retries => null` removed from expectations.

## Client (JavaScript)

- **[ADDED] public/js/gates/** — `TemplatesRequestGate` (`app_started`), `WorldOptionsGate`
  (`templates_replied`), `StartGameGate` (`start_submitted`), `GameStartedGate` (`game_create_replied`),
  `CommandSendGate` (`command_entered`), `TurnShownGate` (`command_replied`), `RejectionShownGate`
  (`client_rejected`: frees the page after a rejected propagation); `index.mjs` registers them.
- **[ADDED] public/js/pure/text.mjs** — display text from any JSON value, never throwing (`String(v)` can
  throw on a JSON object whose `toString` is not a function).
- **[ADDED] public/js/pure/api.mjs** (reading an API reply — the rules `api/client.js` applied),
  **public/js/pure/view.mjs** (HUD, narrative and log lines), **public/js/events/io.mjs** (`api_call`,
  `session`, `ui_*` builders).
- **[ADDED] public/js/boundary/driver.mjs** (enter → take → fetch / draw / keep session; on a rejection
  it enters `client_rejected`),
  **public/js/boundary/http.mjs** (fetch as transport: `{status, contentType, json, error}`, never throws).
- **[CHANGED] public/js/app.js** — wiring only: DOM input → entry events (with the session and the
  boundary's clock for the game id); render instructions → `ui/*.js`.
- **[CHANGED] public/js/ui/output.js, stats.js, overlays.js** — draw render instructions; no state, no
  game decisions. Inventory and error details are built with DOM nodes (no `innerHTML` with data).
- **[ADDED] public/js/ui/screens.js** — world picker, start button, screen switch, command input.
- **[REMOVED] public/js/core/state.js, core/events.js** (the unused bus), **api/client.js**.
- **[ADDED] sisoTests/client/** — one test per client gate, `helper.mjs` (one-gate core stream, canonical
  JSON comparison through the core's `kit/data`), `census.mjs`.
- **[ADDED] tests/client-scenarios.test.mjs**, **tests/browser.e2e.mjs** (optional).
- **[ADDED] tests/probes/g1-corrupt-saves.php, g2-stat-keys.php** — reproduce departures G1 and G2 (not
  tests; not in `run_tests.php`).
- **[CHANGED] router.php** — `.mjs` (and `.js`) served as `text/javascript`.

## Behaviour changes (deliberate)

1. **Double start is ignored.** Clicking Start (or Enter in the name field) while a game is being created
   no longer sends a second `POST /game/new`.
2. **A command entered before a game exists is ignored** (before, it was sent with `gameId` null).
3. **The footer no longer loads `app.js` a second time** as a classic script (the console SyntaxError is
   gone; issue 5 of 5.1.0).
4. **The retry button** re-requests the world list and leaves the overlay up until the answer arrives
   (before, it hid the overlay at once).
5. **A rejected propagation** (a gate threw, looped or returned a non-event) is logged with its core code
   (`SISO_GATE_THREW`, …) instead of as a generic exception. The HTTP answer is still 500.
6. **Debug `history`** (on a failed request's 500 body, `GAMEMASTER_DEBUG=true`) has the core 13 log
   shape: `claimed` is a boolean, and event ids restart at 1 in every propagation (each boundary reply
   is its own propagation); entries carry a `propagation` number.
7. **Small client differences:** the turn count is shown only when the reply's `turnCount` is a number;
   the game id is URL-encoded in the action path (ids are `[A-Za-z0-9_-]`, so the path is the same); a
   `gameId` inside a turn reply is no longer adopted (the server never sends one there); a turn reply
   without `player.inventory` shows no inventory line instead of the previous one (the server always
   sends it); reply fields that are not text (objects) show as empty text instead of `[object Object]`.
8. **A rejected client propagation** shows the error overlay with the core's code and frees the input
   and the start button; the Retry button reloads the world list as before.

## Fixed

- [FIXED] Frontend double-load of `app.js` (5.1.0 known issue).

## Found this phase (open)

- **G1** — gates throw on a type-corrupted save file.
- **G2** — one AI reply whose `statChanges` names `globalFlags` (or `day`, `time`) breaks the save for
  later flag-setting turns (found by the independent review; present in 5.1).

See `doc/conformance.md`, *Recorded departures* and *Independent review*.
