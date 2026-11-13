# Changelog — v11.1

**Date:** 2026-10-02
**Previous version:** 11.0

---

## Summary

A gate that throws is rejected the same way as a self-loop or a cycle, and its rejection now has the same shape. `emit` wraps whatever the gate threw in an `Error` with `code: 'SISO_GATE_THREW'`, the delivery's `eventId`, `causeId` and `eventType`, and the original as `cause`. This resolves departure X3 (spec v11.0): principles 2.0, K5, require a rejection to carry the reason and the identities involved, and a gate's own error carried neither.

This release also ships the 11.0 docs revision:
- SISO-principles 2.0 DRAFT (core only, the core's ledger K1–K6);
- the implementation ledger in the spec (R1–R3, X1–X2);
- the conformance re-audit.

**Breaking:** code that caught a gate's own error type from `emit` now receives an `Error` with `code: 'SISO_GATE_THREW'`. The original is `err.cause`.

---

## Stream (code/src/Stream.js)

- **`gate.transform` is wrapped.** Anything thrown (an `Error` or any other value) is rethrown as a substrate error: `code: 'SISO_GATE_THREW'`, `eventId` (the delivery that threw), `causeId` (`null` for an external entry), `eventType` (the event the gate was given), and `cause` (exactly what was thrown). The message names the gate's signature and includes the original message.
- As with every rejection, the propagation commits no residue, and the stream remains usable.
- Docstrings: the gate-threw rejection, and the ids every rejection carries.

## Gate (code/src/Gate.js)

- Docstring only: a gate does not throw. A failure it recognises is returned as an event of an error type. A throw rejects the propagation.

## Docs

- `specs/spec-v11.1.md` added. Its differences from the revised v11.0: the gate-threw contract, the rejection exit's reference names, X3 moved to *resolved*, and test counts.
- `conformance.md` re-audited for 11.1. Item 8's evidence is now tests, and X3 is resolved.
- `SISO-principles.md` (2.0 DRAFT): unchanged by this release.

## Tests

- core.test.js (+3):
  - a gate that throws rejects with `SISO_GATE_THREW`, ids and cause;
  - a gate that throws commits no residue (a queued sibling is not committed) and leaves the stream usable;
  - a non-Error throw is wrapped the same way (`cause` is the thrown value; `causeId` is `null` for an external entry).
- streamlog.test.js (+1): a gate-threw error points at the claiming entry in the log.
- The new SISO_GATE_THREW tests fail against 11.0 and pass against 11.1 (the residue test already held in 11.0).
- Totals: core 41/41, streamlog 26/26.

## Known issues

- **Resolved:** X3.
- **Unchanged:** X1 (non-Event outputs skipped), X2 (unknown log level), and R1–R3 with their open questions (spec v11.1).
