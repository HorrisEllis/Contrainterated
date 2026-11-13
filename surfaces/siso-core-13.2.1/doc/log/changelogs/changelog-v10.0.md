# Changelog — v10.0

**Date:** 2026-08-28
**Previous version:** 9.0

---

## Summary

Bounded same-type re-emission (retry). A gate may now return
an event of its own type if the event carries an explicit retry
bound: `retries: { attempt, max }`. The stream enforces the
bound statelessly — it reads the field, checks attempt ≤ max,
and enqueues or blocks. Events without retries default to one
pass (1:1); acyclicity holds by default, retry is opt-in.

Event gains one optional field. The stream gains enforcement
logic but no state. Gates that don't retry are untouched.
SISO-principles bumped 1.1 → 1.2.

---

## Event (code/src/Event.js)

- **Constructor gains optional third parameter: `retries`.**
  `new Event(type, data, retries)` where retries is
  `{ attempt, max }`. When omitted, the event has no retries
  field — backward-compatible with all existing code. When
  provided, `this.retries` is set on the event.
- retries is gate-authored metadata, not delivery annotation.
  The gate constructs it when returning a same-type event; the
  stream reads it for enforcement. Events without retries carry
  only `{type, data}` as before.

## Stream (code/src/Stream.js)

- **Same-type re-emission enforcement.** When a gate returns
  an event whose type matches its own signature, the stream
  checks the retries field:
  - Present and `attempt ≤ max` → enqueued for another pass.
  - Absent or `attempt > max` → blocked. The event goes to
    pending (treated as unclaimed) and is logged with
    `gateClaimed: null`. An `eventId` is assigned.
- **No state added.** The check reads a field on the event
  being enqueued — the stream does not track retry counts,
  visited types, or chain history. The enforcement is the same
  kind of operation as reading `.type` for routing.
- **Different-type emission is unaffected.** Only same-type
  (event type === producing gate's signature) triggers the
  check. All other gate outputs are enqueued unconditionally,
  exactly as before.
- **Docstrings updated:** acyclicity section rewritten to
  describe the retry exception and enforcement mechanics.

## StreamLog (code/src/StreamLog.js)

- `record()` accepts an optional `retries` parameter. When
  present, the log entry includes `retries: { attempt, max }`
  at **EVENTS** level and above. Entries for non-retry events
  have no retries field — no wasted space.
- Blocked same-type events (retry exceeded or absent) are
  logged as unclaimed (`gateClaimed: null`) with a `causeId`
  linking to the producing event. This provides full audit
  visibility for retry enforcement.
- Level documentation updated: EVENTS now lists retries
  alongside eventId and causeId as chain metadata.

## SISO-principles.md (doc/) — 1.1 → 1.2

- **Rule 3 acyclicity clause updated.** The blanket "causal
  chains must not revisit an event type" becomes "must not
  revisit an event type without an explicit retry bound."
  Same-type re-emission is permitted when the event carries
  retries `{ attempt, max }` and `attempt ≤ max`.
- **Retry section added** under Rule 3: defines the retries
  field, enforcement semantics, the 1:1 default, the scope
  of the relaxation (same-type only; indirect cycles remain
  prohibited), and the canonical retry pattern.
- **Event primitive description updated** to mention the
  optional retries field.
- **Compliance checklist updated:** acyclicity item now
  reads "No causal chain revisits an event type without an
  explicit retry bound."

## Tests

- All 46 pre-existing tests pass unchanged.
- Added to core.test.js (8):
  - Event retries field absent by default
  - Event retries field present when provided
  - Same-type re-emission without retries → blocked to pending
  - Same-type with valid retries → enqueued, multiple passes
  - Retry exhaustion: gate emits error on last attempt
    (the canonical pattern)
  - Retry bound exceeded (attempt > max) → blocked to pending
  - Different-type emission from retry gate is always allowed
  - Retry events carry only type/data/retries — no delivery
    annotation
- Added to streamlog.test.js (4):
  - Retry entries carry retries field in log
  - Non-retry entries have no retries field
  - Blocked same-type re-emission logged as unclaimed with
    causal link
  - Retry causal chain reconstructible by walking causeId
- Totals: core 33/33, streamlog 25/25.

## Known issues

- **Open, pre-existing:** acyclicity for indirect cycles
  (A → B → A) is a documented invariant with no runtime
  detection. Retries relaxes the rule only for same-type
  (self-loop); indirect cycles remain prohibited and
  unenforced. A gate returning its own type without retries is
  now blocked (not non-terminating) — the failure mode improved.
- **Open, pre-existing:** `streamIdCounter`, non-Event array
  members, unknown log level strings — all unchanged. See
  spec v9.0 known issues.
