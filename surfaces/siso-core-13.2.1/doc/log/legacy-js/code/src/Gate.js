/**
 * Gate — a shape that recognizes one type of event
 * and transforms it into another.
 *
 * The signature is a unique key. No two gates in a
 * stream may share one. The stream uses it for
 * direct lookup — O(1), no scanning.
 *
 * transform is a pure function: receives one event,
 * returns zero, one, or many events. The gate cannot
 * reach the stream, the log, other gates, or residue.
 * It receives an event and returns data.
 *
 * A gate never returns its own type (self-loop), and its
 * outputs never lead back to a type already on the causal
 * chain (cycle). The stream throws on either.
 *
 * A gate does not throw. A failure it recognises is returned
 * as an event of an error type. If it throws anyway, the
 * stream rejects the propagation (SISO_GATE_THREW).
 *
 * This is the shape of the arrow in →E→E→.
 */
export class Gate {
  constructor(signature) {
    this.signature = signature;
  }

  /**
   * Transform the event. Returns:
   *   - Event     → one follow-up event
   *   - Event[]   → multiple follow-up events
   *   - null      → event consumed, no follow-up
   *   - undefined → same as null (default)
   *
   * @param {Event} event — the incoming event
   * @returns {Event|Event[]|null|undefined}
   */
  transform(event) {
    // Override in subclass.
  }
}
