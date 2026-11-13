"""siso.observer.streamlog -- StreamLog, the audit trail, on the observer seam
(core 13, Python instance). Port of lua/code/observer/streamlog.lua.

A log is an observer (SISO principles, "The log -- not a primitive"). The
stream shows it one batch per propagation, after the propagation has
ended (py/code/siso/contracts/observer_seam.py):
  {"outcome": "settled" | "rejected", "rejection": <a frozen copy> | None,
   "records": [{"eventId", "causeId", "eventType", "claimed", "data"}, ...]}
The log stores what it is shown and reads nothing back into any stream.
Nothing here is consulted by a gate, a sample or any running part.

Everything the log keeps is its own (observer-side): entries, seq,
timestamps, level, stream labels, lineage and propagation numbers, all
held by one StreamLog instance (its slots) or by the observer function it
hands out (its closure). There is no module-global state: the module
holds only immutable constants, functions and the class, and two logs
share nothing. It imports nothing from siso.core, only the standard
library's `time`.

API
  from siso.observer.streamlog import StreamLog   (or: from siso.observer import StreamLog)

  log = StreamLog(level=None)
      level is "OFF" | "EVENTS" | "DEEP" | "DATA" (a str, exact type);
      None (or no argument) means "EVENTS". Any other value raises
        ValueError("StreamLog: unknown level '<level>' (OFF, EVENTS, DEEP, DATA)")  (X2)

  obs = log.observer(label, parentLabel=None)
      -> the function to pass to stream.attach(obs) (and stream.detach).
      label       a non-empty str naming the observed stream in this log
                  (else TypeError for a value that is not a str, ValueError
                  for "": "StreamLog: a stream label is a non-empty string");
                  a label already used in this log raises
                  ValueError("StreamLog: label '<label>' is already used").
      parentLabel optional (None: none); the label of the stream this one
                  is a sub-stream of. It must already be a label of this
                  log, else ValueError("StreamLog: parent label '<p>' is
                  not a label of this log") (lineage names only streams
                  the log knows).
      Each call of obs is one propagation of that stream. The observer
      numbers them 1, 2, ... (its own propagation number, counted for
      every batch it is shown, whatever the level).

  log.setLevel(level)   change the level; an unknown level raises (X2) and
                        the level is kept. The following batch respects it.
  log.level()           -> the current level.
  log.sample()          -> {"level", "count", "entries"} where entries is a
                          copy (a new list of new entry dicts); nothing
                          done to a sample reaches the log. "data", when
                          present, is the frozen data the stream showed
                          (the observer's own copy, observer-seam).
  log.clear()           drop all entries and reset seq to 0. Labels and
                        propagation numbers are kept, so a propagation is
                        never numbered twice.

LEVELS (each includes everything below)
  OFF     nothing is recorded
  EVENTS  per delivery: seq (0, 1, ... per log, in the order the log was
          shown records), time (whole seconds since the epoch, an int:
          int(time.time())), propagation, outcome ("settled" |
          "rejected"), eventId, causeId (None for the entry delivery),
          type, claimed (bool, as the seam shows it)
  DEEP    + streamLabel, and parentLabel when the stream has a parent
  DATA    + data
  An entry holds exactly the keys of its level: a key above its level, or
  parentLabel for a stream with no parent, is absent (Python's
  counterpart of Lua's nil field); causeId is an EVENTS key, present and
  None for the entry delivery (as the seam's record carries it).

IDENTITY AND CAUSALITY
  The stream numbers deliveries 1, 2, ... afresh in every propagation, so
  an eventId alone names nothing. Within one stream (label) a delivery is
  (propagation, eventId); across streams sharing a log it is
  (streamLabel, propagation, eventId), with streamLabel recorded at DEEP.
  causeId names a delivery of the same propagation: walking causeId links
  within one (streamLabel, propagation) reconstructs a causal chain,
  whatever order entries were written in. A rejected propagation's
  entries end with the delivery the rejection names (rejection eventId).

SUBSTRATE (Python)
  - Lua's nil fields become absent keys, except causeId, which the seam's
    record carries as None and the entry carries the same way.
  - Lua's os.time() becomes int(time.time()): whole seconds, an int, as in
    the reference (11.1's milliseconds stay retired).
  - Lua's `error(message)` becomes a ValueError (a value that is not one of
    the allowed ones) or a TypeError (a label that is not a str), with
    Lua's messages.
  - Lua's closure-held log state becomes the instance's __slots__ (no
    instance __dict__, no attribute beyond them); the per-observer
    propagation number stays in the observer's closure, as in Lua.
  - Levels are looked up in an immutable tuple, by exact str, so a str
    subclass or any other value is an unknown level.
  - One batch's entries are built before any is kept: a batch the log
    cannot read (not the seam's shape) raises in the observer, which the
    stream isolates (observer-seam ISOLATION), and the log keeps nothing
    of it. Its propagation is still counted.
"""

import time

# A level's rank is its index: an immutable constant, not state.
_LEVELS = ("OFF", "EVENTS", "DEEP", "DATA")
_EVENTS, _DEEP, _DATA = 1, 2, 3


def _render(v):
    if type(v) is str:
        return v
    try:
        s = repr(v)
        if type(s) is str:
            return s
    except Exception:
        pass
    return "<" + type(v).__name__ + ">"


def _rank(level):
    if type(level) is not str or level not in _LEVELS:
        raise ValueError("StreamLog: unknown level '" + _render(level) + "' (OFF, EVENTS, DEEP, DATA)")
    return _LEVELS.index(level)


class StreamLog:
    """An audit trail: an observer factory whose record is its own."""

    __slots__ = ("_level", "_rank", "_entries", "_seq", "_labels")

    def __init__(self, level=None):
        if level is None:
            level = "EVENTS"
        self._rank = _rank(level)
        self._level = level
        self._entries = []
        self._seq = 0
        self._labels = set()

    def level(self):
        return self._level

    def setLevel(self, level):
        self._rank = _rank(level)
        self._level = level

    def observer(self, label, parentLabel=None):
        if type(label) is not str:
            raise TypeError("StreamLog: a stream label is a non-empty string")
        if label == "":
            raise ValueError("StreamLog: a stream label is a non-empty string")
        if label in self._labels:
            raise ValueError("StreamLog: label '" + label + "' is already used")
        if parentLabel is not None and (type(parentLabel) is not str or parentLabel not in self._labels):
            raise ValueError("StreamLog: parent label '" + _render(parentLabel) + "' is not a label of this log")
        self._labels.add(label)
        log = self
        propagation = 0

        def observe(batch):
            nonlocal propagation
            propagation += 1
            rank = log._rank
            if rank == 0:
                return
            outcome = batch["outcome"]
            built = []
            for r in batch["records"]:
                e = {
                    "seq": log._seq + len(built), "time": int(time.time()), "propagation": propagation,
                    "outcome": outcome, "eventId": r["eventId"], "causeId": r["causeId"],
                    "type": r["eventType"], "claimed": r["claimed"],
                }
                if rank >= _DEEP:
                    e["streamLabel"] = label
                    if parentLabel is not None:
                        e["parentLabel"] = parentLabel
                if rank >= _DATA:
                    e["data"] = r["data"]
                built.append(e)
            log._seq += len(built)
            log._entries.extend(built)

        return observe

    def sample(self):
        return {"level": self._level, "count": len(self._entries), "entries": [dict(e) for e in self._entries]}

    def clear(self):
        self._entries = []
        self._seq = 0
