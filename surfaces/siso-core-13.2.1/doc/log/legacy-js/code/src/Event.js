/**
 * Event — a datum flowing through the stream.
 * Has a type (its signature) and arbitrary data.
 * Nothing else: {type, data}.
 *
 * Created, never modified. The substrate never annotates
 * an event; delivery metadata (causal id, depth) rides
 * alongside it in the stream's queue, never on it.
 *
 * This is E.
 */
export class Event {
  constructor(type, data = {}) {
    this.type = type;
    this.data = data;
  }
}
