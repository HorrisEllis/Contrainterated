/**
 * Stream — the processing loop.
 *
 * Gates register by signature. Events arrive via emit().
 * The stream looks up the event's type in the gate table.
 * If a gate claims it, transform runs and returns follow-up
 * events. The stream queues them and keeps pulling until
 * the queue drains. If nothing claims an event, it lands
 * in residue.
 *
 * Delivery is breadth-first: a gate's outputs join the back
 * of the queue, so events process in causal generations.
 * This is an implementation choice, not a semantic one —
 * gates cannot observe processing order (Rule 3), so the
 * set of events computed and the contents of residue are
 * identical under any traversal. Causality is recorded as
 * data, not implied by sequence: every delivery carries the
 * id of the event that caused it, and the log receives both.
 *
 * Causal chains are acyclic (SISO Rule 3), and the stream
 * enforces it by architecture:
 *
 *   - Self-loop. A gate returning an event of its own type
 *     is a gate-design error. The stream throws at once.
 *   - Indirect cycle (a → b → a). Each queue entry carries
 *     its depth on the causal chain. A legal chain visits
 *     each type at most once, so no claimed event can sit
 *     deeper than the number of registered gates. A claimed
 *     event deeper than that proves a type repeated — the
 *     stream throws.
 *
 * Depth, like the causal id, rides alongside the event in
 * the queue. It is never on the event, never shown to a
 * gate, and gone once the entry is processed. The bound is
 * read from the present (the current gate count), not
 * configured.
 *
 * A gate that throws rejects the propagation the same way:
 * the stream wraps whatever it threw (code SISO_GATE_THREW,
 * the original as `cause`) with the ids of the delivery.
 *
 * A propagation that throws commits no residue. Event ids
 * already assigned are not reused.
 *
 * Gates never receive the stream. They receive an event
 * and return events. The stream does the plumbing. Events
 * are never annotated by delivery.
 *
 * Residue is what's left when processing settles — the
 * answer. sampleHere() reads it; takeResidue() removes it.
 * These, with emit() and an optional observer (the log),
 * are the core's only doors to the outside.
 *
 * If a StreamLog is provided, emit() records every delivery.
 * The log is shared — sub-streams write to the same one,
 * giving full visibility across the tree. Removing it
 * changes nothing.
 *
 * This is →E→E→.
 */
import { Event } from './Event.js';

function substrateError(code, message, details) {
  const err = new Error(message);
  err.code = code;
  Object.assign(err, details);
  return err;
}

export class Stream {
  constructor({ log = null, parentStreamId = null } = {}) {
    this.gates = new Map();
    this.residue = [];
    this.eventCount = 0;
    this.log = log;
    this.streamId = log ? log.nextStreamId() : null;
    this.parentStreamId = parentStreamId;
  }

  /**
   * Register a gate. Signature must be unique.
   * Collision is a hard error, not a silent precedence bug.
   */
  register(gate) {
    if (this.gates.has(gate.signature)) {
      throw new Error(`Signature collision: '${gate.signature}'`);
    }
    this.gates.set(gate.signature, gate);
  }

  /**
   * Emit an event into the stream. (Door: entry.)
   *
   * Processing is synchronous and iterative: the event and
   * everything it causes flow through a FIFO queue until it
   * drains. emit() returns only when the causal tree has
   * fully settled. No call-stack depth is spent, so chain
   * length is bounded by acyclicity, not by the engine.
   *
   * Each delivery is assigned an id (the running eventCount)
   * and tagged with the id of the event whose transform
   * produced it (causeId; null for an external emit) and its
   * depth on the causal chain (1 for an external emit).
   *
   * Throws (code 'SISO_SELF_LOOP') if a gate returns its own
   * type, (code 'SISO_CYCLE') if a claimed event's depth
   * exceeds the registered gate count, and (code
   * 'SISO_GATE_THREW') if a gate throws — the gate's own
   * error, whatever was thrown, is kept as `cause`. Every
   * rejection carries the ids of the delivery involved
   * (eventId, eventType; causeId where there is one). In
   * every case the propagation commits no residue.
   */
  emit(event) {
    const queue = [{ event, causeId: null, depth: 1 }];
    const settled = [];
    let head = 0;

    while (head < queue.length) {
      const { event: current, causeId, depth } = queue[head++];
      const eventId = ++this.eventCount;
      const gate = this.gates.get(current.type);

      if (this.log) {
        this.log.record({
          streamId: this.streamId,
          parentStreamId: this.parentStreamId,
          eventId,
          causeId,
          eventType: current.type,
          gateClaimed: gate ? gate.signature : null,
          eventData: current.data,
        });
      }

      if (!gate) {
        settled.push(current);
        continue;
      }

      if (depth > this.gates.size) {
        throw substrateError(
          'SISO_CYCLE',
          `Cycle: '${current.type}' claimed at depth ${depth} with ` +
          `${this.gates.size} gates registered — a type repeated on ` +
          `this causal chain`,
          { eventId, causeId, eventType: current.type, depth }
        );
      }

      let result;
      try {
        result = gate.transform(current);
      } catch (thrown) {
        throw substrateError(
          'SISO_GATE_THREW',
          `Gate '${gate.signature}' threw: ` +
          (thrown instanceof Error ? thrown.message : String(thrown)),
          { eventId, causeId, eventType: current.type, cause: thrown }
        );
      }
      const outputs = result instanceof Event ? [result]
        : Array.isArray(result) ? result
        : []; // null/undefined → consumed, no follow-up

      for (const out of outputs) {
        if (!(out instanceof Event)) continue;
        if (out.type === gate.signature) {
          throw substrateError(
            'SISO_SELF_LOOP',
            `Self-loop: gate '${gate.signature}' returned its own type`,
            { eventId, eventType: out.type }
          );
        }
        queue.push({ event: out, causeId: eventId, depth: depth + 1 });
      }
    }

    for (const e of settled) this.residue.push(e);
  }

  /**
   * Not getResult. The flow is ongoing.
   * We choose to look now. What we read
   * is a cross-section of →E→E→. (Door: sample.)
   * Non-destructive: the stream is unchanged.
   */
  sampleHere() {
    return {
      residue: [...this.residue],
      eventCount: this.eventCount,
      gateCount: this.gates.size,
    };
  }

  /**
   * Take the residue. (Door: the only way anything leaves.)
   * Returns what has settled and removes it from the stream.
   */
  takeResidue() {
    const taken = this.residue;
    this.residue = [];
    return taken;
  }
}
