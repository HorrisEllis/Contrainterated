/*
observer/streamlog — StreamLog, the audit trail, on the observer seam
(core 13, JavaScript instance). Port of lua/code/observer/streamlog.lua.

A log is an observer (SISO principles, "The log — not a primitive"). The
stream shows it one batch per propagation, after the propagation has
ended (js/code/contracts/observer_seam.mjs):
  { outcome: "settled" | "rejected", rejection (rejected only),
    records: [{ eventId, causeId, eventType, claimed, data }, …] }
The log stores what it is shown and reads nothing back into any stream.
Nothing here is consulted by a gate, a sample or any running part.

Everything the log keeps is its own (observer-side): entries, seq,
timestamps, level, stream labels, lineage and propagation numbers. There
is no module-global state: the module holds only frozen constants and a
frozen factory, and two logs share nothing. It imports nothing from
js/code/core/ and nothing from `node:`.

API
  StreamLog, the named and the default export of js/code/observer/streamlog.mjs

  const log = StreamLog(level)
      level is "OFF" | "EVENTS" | "DEEP" | "DATA"; undefined means
      "EVENTS". Any other value throws "StreamLog: unknown level …" (X2).
      Calling it with `new` returns the same kind of object.

  const obs = log.observer(label, parentLabel)
      → the function to pass to stream.attach(obs) (and stream.detach).
      label       a non-empty string naming the observed stream in this
                  log; a label already used in this log throws.
      parentLabel optional (undefined for none); the label of the stream
                  this one is a sub-stream of. It must already be a label
                  of this log, else it throws (lineage names only streams
                  the log knows).
      Each call of obs is one propagation of that stream. The observer
      numbers them 1, 2, … (its own propagation number, counted for every
      batch it is shown, whatever the level).

  log.setLevel(level)   change the level; unknown levels throw (X2) and
                        the level is kept. The following batch respects it.
  log.level()           → the current level.
  log.sample()          → { level, count, entries } where entries is a copy
                          (new Array, new entry objects); nothing done to a
                          sample reaches the log. `data`, when present, is
                          the frozen data the stream showed (the observer's
                          own copy, observer-seam).
  log.clear()           drop all entries and reset seq to 0. Labels and
                        propagation numbers are kept, so a propagation is
                        never numbered twice.

LEVELS (each includes everything below)
  OFF     nothing is recorded
  EVENTS  per delivery: seq (0, 1, … per log, in the order the log was
          shown records), time (whole seconds since the epoch,
          Math.floor(Date.now() / 1000)), propagation, outcome ("settled" |
          "rejected"), eventId, causeId (absent for the entry delivery),
          type, claimed (boolean, as the seam shows it)
  DEEP    + streamLabel, and parentLabel when the stream has a parent
  DATA    + data
  A field that does not apply is absent, not present-but-undefined.

IDENTITY AND CAUSALITY
  The stream numbers deliveries 1, 2, … afresh in every propagation, so
  an eventId alone names nothing. Within one stream (label) a delivery is
  (propagation, eventId); across streams sharing a log it is
  (streamLabel, propagation, eventId), with streamLabel recorded at DEEP.
  causeId names a delivery of the same propagation: walking causeId links
  within one (streamLabel, propagation) reconstructs a causal chain,
  whatever order entries were written in. A rejected propagation's
  entries end with the delivery the rejection names (rejection.eventId).

SUBSTRATE (JavaScript)
  - Lua's nil fields become absent keys (never present-but-undefined).
  - Lua's os.time() becomes Math.floor(Date.now() / 1000): whole seconds,
    as in the reference (11.1's Date.now() milliseconds stay retired).
  - Levels are looked up as own keys of a frozen table, so an inherited
    name ("toString", "__proto__") is an unknown level like any other.
  - The factory, each log and each observer function are frozen: a JS
    function is an object that could carry writable state; freezing
    leaves the module nothing anyone can write.
*/

const LEVELS = Object.freeze({ OFF: 0, EVENTS: 1, DEEP: 2, DATA: 3 });

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

function rank(level) {
  if (typeof level !== "string" || !hasOwn(LEVELS, level)) {
    throw new Error(`StreamLog: unknown level '${str(level)}' (OFF, EVENTS, DEEP, DATA)`);
  }
  return LEVELS[level];
}

const now = () => Math.floor(Date.now() / 1000);

// an entry's own fields, copied into a new object
const copyEntry = (e) => ({ ...e });

export function StreamLog(initial) {
  if (initial === undefined) initial = "EVENTS";
  let lvl = rank(initial);
  let level = initial;
  let entries = [];
  let seq = 0;
  const labels = new Set();

  function observer(label, parentLabel) {
    if (typeof label !== "string" || label === "") {
      throw new TypeError("StreamLog: a stream label is a non-empty string");
    }
    if (labels.has(label)) throw new Error(`StreamLog: label '${label}' is already used`);
    if (parentLabel !== undefined && !labels.has(parentLabel)) {
      throw new Error(`StreamLog: parent label '${str(parentLabel)}' is not a label of this log`);
    }
    labels.add(label);
    let propagation = 0;
    return Object.freeze((batch) => {
      propagation += 1;
      if (lvl === 0) return;
      for (const r of batch.records) {
        const e = { seq, time: now(), propagation, outcome: batch.outcome, eventId: r.eventId };
        if (r.causeId !== undefined) e.causeId = r.causeId;
        e.type = r.eventType;
        e.claimed = r.claimed;
        if (lvl >= 2) {
          e.streamLabel = label;
          if (parentLabel !== undefined) e.parentLabel = parentLabel;
        }
        if (lvl >= 3) e.data = r.data;
        seq += 1;
        entries.push(e);
      }
    });
  }

  return Object.freeze({
    level: () => level,
    setLevel(l) {
      lvl = rank(l);
      level = l;
    },
    observer,
    sample: () => ({ level, count: entries.length, entries: entries.map(copyEntry) }),
    clear() {
      entries = [];
      seq = 0;
    },
  });
}

Object.freeze(StreamLog.prototype);
Object.freeze(StreamLog);

export default StreamLog;
