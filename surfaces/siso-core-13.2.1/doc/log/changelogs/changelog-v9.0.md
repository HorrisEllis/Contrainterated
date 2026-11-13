# Changelog — v9.0

**Date:** 2026-08-03
**Previous version:** 1.0

---

## Summary

Delivery rewritten from recursive depth-first to iterative
breadth-first queue. Causality recorded as data (`eventId`/`causeId`
in log entries) rather than implied by log ordering. Acyclicity of
causal chains canonized in SISO-principles.md (bumped 1.0 → 1.1).
Project migrated to the canonical SISO file structure.

Gate-visible behavior is unchanged: gates still receive one event
and return zero/one/many; `emit()` still returns only when the
causal tree has fully settled; pending and eventCount are computed
identically. What changed is internal delivery discipline and what
the log can express.

---

## Stream (code/src/Stream.js)

- **`emit()` rewritten: recursive → iterative FIFO queue.**
  Previously each returned event was re-emitted via a recursive
  `this.emit(result)` call, spending one engine stack frame per
  causal step. A legal chain of ~20,000 distinct gates threw
  `RangeError: Maximum call stack size exceeded` (verified against
  the v1.0 source). The new implementation drains an index-headed
  queue inside a single `emit()` call: chain length is now bounded
  only by the acyclicity rule (≤ gate count), never by the engine.
- **Traversal order changed: depth-first → breadth-first.**
  A gate's outputs join the back of the queue, so events process in
  causal generations (root, then all children, then all
  grandchildren) instead of complete subtrees. Per SISO Rule 3,
  processing order is not semantic — gates cannot observe it, so
  the set of events computed and the contents of pending are
  identical under either traversal. Observable difference: log
  entry order and pending order for trees that mix fan-out with
  chains. (No existing test depended on such an ordering.)
- **Causal tagging.** Each delivery is assigned an `eventId`
  (the running `eventCount`, 1-based) and a `causeId` (the id of
  the event whose transform produced it; `null` for external
  emits). Ids derive from `eventCount`, which the stream keeps
  regardless of logging — attaching or removing a log changes no
  behavior. Event objects are never annotated: causal metadata
  rides alongside the event in the queue and surfaces only through
  the log. Gates continue to receive the bare event as a single
  argument.
- **Docstrings updated**: breadth-first delivery, the
  causality-as-data stance, and the acyclicity invariant
  (documented, deliberately not enforced — a cycle is a
  non-terminating system and the failure belongs to the gate
  author).

## StreamLog (code/src/StreamLog.js)

- `record()` accepts and stores `eventId` and `causeId` at
  **EVENTS** level (and above). Entry order continues to mean
  time; `causeId` now means cause. Walking `causeId` links
  reconstructs any causal thread regardless of write order.
- Level documentation updated. Noted: `eventId` is per-stream, so
  when sub-streams share a log, disambiguate with `streamId` at
  DEEP level.
- No other changes. `seq`, `time`, level gating, `sample()`,
  `clear()`, `nextStreamId()` untouched.

## SISO-principles.md (doc/) — 1.0 → 1.1

- **Rule 3 gains the acyclicity clause**: a causal chain must not
  revisit an event type; gates are keyed by type, so a repeated
  type is a repeated gate and propagation never settles. Chain
  length is therefore bounded by registered gate count. Fan-out
  branches are independent chains. Runtime detection is explicitly
  not required.
- **Compliance checklist gains one item**: "No causal chain
  revisits an event type — causality is acyclic."

## Structure

- Migrated to the canonical SISO layout: `core/doc/`, `core/code/`
  (containing `VERSION` and `src/`), `core/tests/`. Previously the
  zip extracted flat with `src/` and `VERSION` at root.
- Test imports migrated: `../src/*` → `../code/src/*`.
- Zip now has a single `core/` root directory and is named
  `core-9.0.zip` per convention.

## Tests

- All 38 pre-existing tests pass unchanged (22 core + 16
  streamlog).
- Renamed: `Stream: depth-first processing via return chain` →
  `Stream: chain processes in causal order` (chain order is
  identical under any traversal; the old name pinned an
  implementation detail that no longer holds).
- Added to core.test.js (3):
  - breadth-first generation order on a two-level fan-out tree
    (pins the implementation choice; notes it is not a contract)
  - 20,000-gate legal chain completes (the v1.0 recursive
    implementation overflows on this input)
  - delivered and emitted events carry only `type`/`data` — no
    annotation by delivery
- Added to streamlog.test.js (5):
  - chain entries carry sequential `eventId` and linked `causeId`
  - fan-out siblings share their parent's id as cause
  - separate external emits are separate roots (`causeId` null),
    ids continue across emits
  - causal thread reconstruction from a mixed tree by walking
    `causeId` links
  - stream behavior identical with and without a log attached
- Totals: core 25/25, streamlog 21/21.

## Known issues

- **Resolved:** legal long chains overflowed the call stack
  (v1.0). Fixed by queue delivery.
- **Open, pre-existing:** `streamIdCounter` in StreamLog.js is
  module-level shared mutable state — already flagged in
  SISO-Canon-dev-notes.md §1 as an open canon question; left
  untouched this phase.
- **Open, pre-existing:** non-Event values inside a returned
  array are silently skipped (no error, no pending, no log
  entry), while `emit()` itself accepts any object shaped like an
  event. Asymmetric; candidate for a hard error in a future phase,
  consistent with the signature-collision stance.
- **Not included by decision:** runtime cycle detection (invariant
  documented only) and a `threads()` causal-projection view on
  StreamLog (natural follow-up now that `causeId` exists; noted in
  spec §Future).
