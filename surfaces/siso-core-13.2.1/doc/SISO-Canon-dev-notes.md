# SISO Canon — Development Notes

**Status:** Active reference.

---

## 1. Implementation guidance appendix

**Source:** Open Proposals, Proposal 4.
**Status:** Open — defer until patterns are proven.

The principles doc deliberately avoids implementation guidance.
A separate appendix — "Patterns and Anti-patterns for SISO
Implementations" — would capture generalizable decisions made
during implementation without expanding the principles themselves.

Candidate topics:
- How to structure a locally-sampleable stream.
- How to handle non-trivial event sizes.
- How to verify compliance beyond the checklist.
- How to handle shared mutable state in observer-side
  infrastructure (e.g. module-level ID counters) without
  creating cross-instance coupling.

Deferred until multiple implementations surface enough common
patterns to justify the document. Writing it from one
implementation would be premature.

---

## 2. Fixture format as test harness

**Source:** Fixtures branch (deprecated as development methodology).
**Status:** Experimental.

The Fixtures branch defined a JSON format for declarative test
specifications: register these gates, emit these events, expect
this outcome. The development methodology (code generation from
fixtures) is deprecated, but the fixture format itself is a
useful test declaration format independent of how gates are
authored.

A fixture-based test harness for Canon would allow:
- Language-agnostic test specifications (one JSON file, run
  against any Canon implementation in any language).
- Structural verification of gate behavior without writing
  test code per language.
- A shared compliance test suite across implementations.

The format from the deprecated branch:
```json
{
  "name": "descriptive name",
  "gates": [{ "signature": "x", "behavior": "..." }],
  "emit": [{ "type": "x", "data": {} }],
  "expect": {
    "eventCount": 2,
    "residue": [{ "type": "result", "data": {} }]
  }
}
```

Not yet integrated. Would need adaptation — the gate behaviors
in the fixture format (drop, passthrough, emit, copy-field) are
fixture-specific abstractions, not Canon gates. A Canon test
harness would need to reference actual gate implementations or
define a minimal set of test-only gate behaviors.
