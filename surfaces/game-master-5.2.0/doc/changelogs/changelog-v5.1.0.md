# Changelog — v5.1.0

**Date:** 2026-09-28
**Previous version:** none recorded (the Phase 5 codebase: SPEC.md phases 1–5, uploaded as `phase3.zip`)
**Phase:** core-10 port — move GameMaster onto SISO core v10.0 with full conformance to SISO-principles v1.2

---

## Summary

The whole backend now runs on a PHP port of the SISO Canon core v10.0
(Event, Gate, Stream, StreamLog). Every gate is a pure
`transform(Event): Event|array|null`, routed by event type alone. All
I/O — save files, templates, zone files, the AI API, the HTTP answer —
moved to a small boundary (`src/Boundary`) that serves request events
from pending. Validation, status codes and response bodies moved out of
the handlers into gates. The REST contract used by `public/js` is
unchanged; the frontend was verified in a headless browser.

Numbers (from `php run_tests.php` and `php sisoTests/census.php` this phase):

| Suite | Before (as shipped) | After |
|---|---|---|
| SISO core (Event/Gate/Stream) | — | siso-core: 35/35 passed |
| SISO StreamLog | — | siso-streamlog: 25/25 passed |
| Gate census (per-gate tests) | no per-gate tests | census: 37/37 passed (147/147 cases) |
| Template data | (part of PortlandGatesTest, wrong path) | templates: 4/4 passed |
| Scenarios through the handlers | — | scenarios: 18/18 passed |
| Old `run_tests.php` | Results: 61 passed, 50 failed (91/20 with config.php prepended) | retired |

---

## Core (src/Siso/Core)

- **[CHANGED] Event.php** — now the core v10.0 Event: `final`, readonly
  `type`, `data` (array) and optional `retries {attempt, max}`. Dynamic
  properties are gone; events can no longer be mutated.
- **[CHANGED] Gate.php** — `transform(Event $event): Event|array|null`
  with return-based propagation; `matches()` removed; `signature` is the
  routing key. Gates no longer receive the stream.
- **[CHANGED] Stream.php** — core v10.0 semantics: `register()` by unique
  signature (collision throws), `emit()` with an iterative FIFO queue,
  eventIds/causeIds, stream-enforced retry bound for same-type
  re-emission, `sampleHere()` → `{pending, eventCount, gateCount}`.
  Registration order no longer matters; `process()`, `getGate()`, the
  500-event cap and the stream's own history are gone.
- **[ADDED] StreamLog.php** — observer-side log (OFF/EVENTS/DEEP/DATA),
  as in core v10.0. Used only for debug output.
- **[REMOVED] LoggingLevel.php, Utilities/DontMatchGate.php, Utilities/ResultGate.php.**
- Port note: a `retries` array missing `attempt` or `max` is blocked, as
  in the JS core. PHP compares numeric strings numerically where JS
  compares them as text; not emulated.

## Gates (src/Gates) — 37 gates, all rewritten

Every gate is pure: no file or network I/O, no clock, no randomness, no
logging, no mutable instance state. Old → new:

| Old | New (signature) |
|---|---|
| ActionParseGate | ActionParseGate (`action_submitted`) — also the handler's validation |
| ContextBuildGate | TurnRouteGate (`turn_state_loaded`, classifier) + ContextBuildGate (`turn_template_loaded`) |
| PortlandContextGate | PortlandContextGate (`portland_turn_loaded`) |
| AIGenerateGate | removed → `AnthropicTransport` (boundary) |
| MockAIGate | removed → `MockAiTransport` (boundary) |
| ResponseParseGate | ResponseParseGate (`turn_ai_replied`) + TurnApplyRouteGate (`turn_apply_loaded`, classifier) |
| StateUpdateGate | StateUpdateGate (`generic_turn_parsed`) — Phase 5 guard kept |
| PortlandStateGate | PortlandStateGate (`portland_turn_parsed`) |
| GameBudgetGate | GameBudgetGate (`turn_applied`) |
| GameSnapshotGate | GameSnapshotGate (`turn_accounted`) |
| GameResultGate | GameResultGate (`turn_saved`) |
| GameErrorGate | GameErrorGate (`turn_failed`) |
| WorldTemplateGate | TemplateListGate, TemplateCatalogGate, WorldTemplateGate (`game_create_loaded`) |
| GameInitGate | GameCreateGate, GameInitGate (`game_template_ready`), GameCreatedGate |
| GameLoadGate | GameLoadGate (`game_load_requested`) + GameStateGate (`game_load_read`) |
| ZoneContextGate | ZoneContextGate (`zone_target`) |
| ZoneParseGate | ZoneParseGate (`zone_ai_replied`) |
| ZoneStoreGate | ZoneStoreGate (`zones_settled`) |
| ZonePreGenerationGate | ZonePregenerateRequestGate + ZonePreGenerationGate (`zone_pregenerate_loaded`) |
| ZoneResultGate | ZoneGenerateResultGate, ZonePregenerateResultGate |
| (ZoneHandler logic) | ZoneGenerateRequestGate, ZoneGenerateCheckGate, ZoneGet/List/Delete gates, ZoneIndexRemoveGate |

- The prompts (generic, Portland, zone) are byte-identical to the old
  gates' — checked against fixtures generated from the pre-port code.
- **[ADDED] src/Events/Io.php** — builders for the boundary request
  events (`io_read`, `io_write`, `io_collect`, `ai_call`, `http_response`).
- **[ADDED] src/Pure/** — pure helpers: `Http` (response bodies), `Ids`
  (id checks), `Zones` (zone-planning helpers).
- **[REMOVED] src/Events/*Event.php** (typed mutable event classes).

## Boundary (src/Boundary) — new

- **Driver** — emits the entry event, samples pending, serves each
  request (read/write files, call the AI) by emitting the reply type
  the request names, serves a join (`io_collect`) once the events it
  names are all present, and returns the settled events.
- **FileStore** — resource kinds → paths; ids outside
  `[A-Za-z0-9_-]{1,100}` never reach the filesystem; `_index` is
  reserved.
- **AiTransport** / **AnthropicTransport** (cURL, retry with backoff) /
  **MockAiTransport** (scripted or default replies).

## HTTP and jobs

- **[CHANGED] Handlers** — thin: build one entry event (raw request
  values plus `now`/`nonce`), run the driver, send the `http_response`
  event. Shared base `StreamEndpoint`. Test helpers kept.
- **[CHANGED] api.php** — `/api/action` no longer validates the
  `gameId` itself (the gate does); unused `enableZonePreGeneration` removed.
- **[CHANGED] GenerateZoneJob::handle()** — runs `zone_generate_requested`
  through the driver like the HTTP endpoint; reads the save fresh (the
  queued `worldContext` snapshot is ignored).
- **[CHANGED] GameStreamFactory** — one stream with every gate; builds
  the store, the transport and the driver. Budget-limit config keys
  removed (they were never enforced).
- **[CHANGED] composer.json** — dropped `siso/framework` and its
  `../siso-framework` path repository (the core is in `src/Siso`).

## Behaviour changes (deliberate)

1. **gameId validated on every route.** Action and zone routes reject
   ids outside `[A-Za-z0-9_-]{1,100}` with 400 "Invalid game ID"
   (before: used raw in file paths — traversal possible). Load and
   create still strip invalid characters, as before.
2. **Portland state updates now apply.** The old PortlandStateGate read
   `rawResponse`, which the event never had, so location, time, money
   and landmarks never changed. They are now read from the parsed AI
   object and **type-checked** (bad fields ignored). A landmark only
   counts if the Portland template defines it.
3. **Budget totals persist.** Token and cost totals are kept in
   `state.budget` and accumulate across requests (before: gate state,
   reset every request). `meta.tokensUsed` is now the session total.
   Limits are still not enforced.
4. **Prices corrected.** Haiku 4.5 → $1/$5, Opus 4.5 → $5/$25 per
   million tokens (were $0.80/$4 and $15/$75); source:
   platform.claude.com/docs/en/about-claude/pricing.
5. **`default.json` fallback removed.** An unreadable template goes
   straight to the built-in default (no `default.json` ever existed).
6. **Pregeneration is one batch.** All targets are generated, then
   stored together with one index and one save write; hand-made zone
   files are never overwritten (reported as "already exists").
7. **Jobs no longer overwrite existing zones** (fail with "Zone 'x' already exists").
8. **Zone delete is two steps:** the index entry is removed only after
   the file is deleted.
9. **Zone ids:** over 100 characters or `_index` → 422 on generate; skipped
   as pregeneration targets. `GET …/zone/_index` → 404.
10. **ActionParseGate no longer classifies actions** (type/target were
    read only by the old mock AI). The action text passes through as before.
11. **Game id "0"** is accepted on the action route (before: `empty()`
    rejected it); `/api/action` accepts a numeric `gameId`.
12. **AI transport failures** give the error message in the in-world 422
    body ("Something unexpected happens. (…)"), as before.

## Fixes found by the independent review (all in this phase)

- Malformed Portland AI fields could make a save unplayable (500 on every later turn).
- Any ten landmark ids won the game.
- Pregeneration overwrote zone files missing from the index.
- PHP retry check looped where the JS core blocks (missing keys).
- The zone join completed on "nothing else outstanding" (scheduling);
  it now completes when the expected results are present.
- Id pattern accepted a trailing newline (`$` → `\z`).
- MockAiTransport executed string replies that named a PHP function.
- 500s on malformed input: non-array `properties` in entityChanges, save
  entities without a name, nested zone constraints.

## Tests

- **[REMOVED]** `tests/Gates/*`, `tests/Integration/*` (PHPUnit-style,
  never run, or written against a Stream API that did not exist) and
  the old `run_tests.php` body.
- **[ADDED]** `tests/runner.php` (PHP port of the core's runner; turns
  warnings into failures), `tests/siso-core.test.php`,
  `tests/siso-streamlog.test.php`, `tests/templates.test.php`,
  `tests/scenarios.test.php`.
- **[ADDED]** `sisoTests/` — one `<signature>.test.php` per gate,
  `helper.php` (`gate('<signature>')`, `run()`), `census.php`,
  `fixtures/` (reference prompts from the pre-port gates).
- The Phase 5 inventory-guard cases now live in
  `sisoTests/generic_turn_parsed.test.php`.

## Known issues

See `doc/specs/spec-v5.1.0.md` → Known issues and `doc/conformance.md` → Issues.
