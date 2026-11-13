# Changelog — v11.0

**Date:** 2026-10-02
**Previous version:** 10.0

---

## Summary

Retry leaves the core. A pure gate's output depends only on its
event, so retrying inside the core can only repeat the same
result; transient failure, polling and backoff involve the world
and belong to the boundary layer. With retry gone, acyclicity
becomes strict — and is now enforced by architecture rather than
left as an invariant on gate design:

- A self-loop (gate returns its own type) throws.
- Any other cycle is caught by a depth guard: no legal chain can
  hold more claimed events than there are registered gates.

`pending` is renamed `residue` — it is the answer, not something
waiting — and gains a take door, `takeResidue()`. Event is back
to `{type, data}`. SISO-principles bumped 1.2 → 1.3.

**Why the v10 mechanism was removed, not repaired.** v10's bound
was authored by the gate: a gate that kept returning
`{ attempt: 2, max: 3 }` looped forever (verified — 10,000
passes, the stream never intervened). The principles called the
bound "a safety net against unbounded loops from gate bugs"; it
was not. Moving the count into the stream would have fixed the
net but kept a mechanism the core has no honest use for.

**Breaking:** `retries` no longer exists; `sampleHere().pending`
is now `sampleHere().residue`; same-type re-emission throws
instead of settling in pending.

---

## Event (code/src/Event.js)

- **`retries` removed.** Constructor is `new Event(type, data)`.
  Extra arguments are ignored; the event carries only
  `{type, data}`.

## Gate (code/src/Gate.js)

- Docstring only: references residue instead of pending, and
  states that self-loops and cycles are rejected by the stream.

## Stream (code/src/Stream.js)

- **Retry enforcement removed.**
- **Self-loop throws** `Error` with `code: 'SISO_SELF_LOOP'`,
  `eventId`, `eventType`.
- **Depth guard.** Queue entries carry `depth` alongside
  `causeId` (1 for an external emit, parent + 1 otherwise). A
  claimed event with `depth > gates.size` throws `Error` with
  `code: 'SISO_CYCLE'`, `eventId`, `causeId`, `eventType`,
  `depth`. Depth never touches the event, is never visible to a
  gate, and disappears with the queue entry. The bound is the
  current gate count — read from the present, not configured.
- **Atomic residue.** Unclaimed events are collected during the
  propagation and committed only when it settles. A propagation
  that throws commits nothing; `eventCount` keeps its advance.
- **`pending` → `residue`.** `sampleHere()` returns
  `{ residue, eventCount, gateCount }`.
- **`takeResidue()` added.** Returns the residue and empties it —
  the core's only way out.
- Docstrings rewritten: acyclicity enforcement, residue, the
  four doors.

## StreamLog (code/src/StreamLog.js)

- `retries` parameter and entry field removed.
- Level doc: `claimed` is a signature or `null` for residue.

## SISO-principles.md (doc/) — 1.2 → 1.3

- Event primitive: optional retries field removed.
- Rule 3: "acyclic by default" → **acyclic**, with a new
  paragraph on architectural enforcement (self-loop rejection,
  depth guard, depth as delivery metadata, no residue on
  rejection).
- Rule 3: retry section and canonical retry pattern replaced by
  **"Retry is a boundary concern."**
- Rule 4: sampling text says residue; new **Residue** subsection
  (residue is the answer; taking removes, sampling does not).
- Checklist: acyclicity item now requires enforcement; new item
  for residue leaving only by being taken.

## Other docs

- `conformance.md` re-audited against v1.3.
- `spec-v11.0.md` added.
- `SISO-Canon-dev-notes.md`: fixture example uses `residue`.

## Tests

- `runner.js`: **`assertThrows` fixed.** It previously passed
  silently when no substring was given and nothing was thrown
  (the "none thrown" error was caught by its own `catch`). All
  existing callers still pass.
- core.test.js: removed 8 retry tests; renamed pending → residue
  throughout; added 13 — Event shape; self-loop throws (direct,
  in fan-out); cycles of length 2 and 3 throw; cycle depth is
  gates + 1; sibling branches may repeat a type; longest legal
  chain ends unclaimed; throw commits no residue; stream usable
  after a throw; takeResidue returns and empties; residue after
  a take; take on empty; sampling does not take.
- streamlog.test.js: removed 4 retry tests; added 4 — no retries
  field; cycle readable from causal chain; self-loop error points
  at producing entry; log presence does not change outcome.
- Totals: core 38/38, streamlog 25/25.

## Known issues

- **Closed:** indirect cycles were non-terminating (open since
  v9.0). Now caught by the depth guard.
- **New, by decision:** a mistyped event type settles as residue;
  visible in the log as `claimed: null`. Declared exits deferred
  to the self-learning work.
- **Open, pre-existing:** `streamIdCounter`, non-Event array
  members, unknown log level strings — unchanged.
