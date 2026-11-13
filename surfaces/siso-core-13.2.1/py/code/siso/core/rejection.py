"""siso.core.rejection -- what a rejected propagation raises (K5).

Contract py-core-api REJECTION. Port of lua/code/core/rejection.lua. A
rejection carries the reason and the identities of the delivery involved,
and nothing else. Rejection is final, a subclass of Exception, and its
fields are exactly

  code       SISO_SELF_LOOP | SISO_CYCLE | SISO_GATE_THREW | SISO_NOT_EVENT
  message    a str; str(rejection) is it and rejection.args is (message,)
  eventId    the delivery involved (None only for a refused entry)
  causeId    that delivery's cause (None for the entry delivery)
  eventType  that delivery's event type (None only for a refused entry)
  depth      SISO_CYCLE only
  cause      SISO_GATE_THREW only: exactly the exception the gate raised

each None when its code does not carry it (present, never missing). They
are slots set once, when the rejection is built: assigning or deleting a
field, or adding one, raises AttributeError. Dunder attributes
(__traceback__, __context__, __cause__, __notes__) are the substrate's
exception plumbing and pass through.

  from siso.core.rejection import Rejection
  Rejection.selfLoop(delivery, signature)
  Rejection.cycle(delivery, gateCount)
  Rejection.gateThrew(delivery, signature, cause)
  Rejection.notEvent(delivery, signature, what)   delivery None: a refused entry
  Rejection.isRejection(v)                         type(v) is Rejection

`delivery` is the stream's view of one delivery: a tuple (eventId,
causeId, eventType, depth). Each builder makes a fresh rejection holding
exactly the fields its code carries; nothing here is kept between calls.
`Rejection(...)` called from outside this module is refused: only the
builders make one. The builders and isRejection are instances of locked
slotted classes (no Python function is a class attribute), and the class
is locked, so nothing a holder of one rejection writes reaches another.

X1 (SPEC.md) applied: the gate's exception is rendered with str() under
protection (a __str__ that raises or does not return a str renders as
"<TypeName>"), normalized to an exact str, so building a rejection never
raises and never hands a str subclass's code to the message. An observer
is never shown this object: it receives a plain frozen copy of its fields
(observer-seam THE REJECTION, siso.core.freeze.plain).
"""

from siso.core.freeze import LockedClass, callable_, lock, typeName

_typeof = type
FIELDS = ("code", "message", "eventId", "causeId", "eventType", "depth", "cause")
_TOKEN = object()


def _safeStr(v):
    try:
        s = str(v)
        if isinstance(s, str):
            return str.__str__(s)
    except Exception:
        pass
    return "<" + typeName(v) + ">"


def _refuse(self, k, v=None):
    raise AttributeError("read-only: a rejection's fields are set once (field '" +
                         (k if _typeof(k) is str else "?") + "')")


def _dunder(k):
    return _typeof(k) is str and k.startswith("__") and k.endswith("__")


class Rejection(Exception, metaclass=LockedClass):
    __slots__ = FIELDS

    def __init__(self, token, code=None, message=None, eventId=None, causeId=None, eventType=None,
                 depth=None, cause=None):
        if token is not _TOKEN:
            raise TypeError("Rejection: only the stream builds a rejection")
        try:
            _SLOT["code"].__get__(self)
        except AttributeError:
            pass
        else:
            raise AttributeError("read-only: a rejection's fields are set once")
        Exception.__init__(self, message)
        for k, v in zip(FIELDS, (code, message, eventId, causeId, eventType, depth, cause)):
            _SLOT[k].__set__(self, v)

    def __setattr__(self, k, v):
        if _dunder(k):
            Exception.__setattr__(self, k, v)
            return
        _refuse(self, k)

    def __delattr__(self, k):
        if _dunder(k):
            Exception.__delattr__(self, k)
            return
        _refuse(self, k)

    def __str__(self):
        return _SLOT["message"].__get__(self)

    def __repr__(self):
        return "Rejection(" + repr(_SLOT["code"].__get__(self)) + ", " + repr(_SLOT["message"].__get__(self)) + ")"

    def __init_subclass__(cls, **kwargs):
        raise TypeError("Rejection is final")


_SLOT = {k: Rejection.__dict__[k] for k in FIELDS}


def fieldOf(r, k):
    """A rejection's field, read through its slot."""
    return _SLOT[k].__get__(r)


def _build(code, message, delivery, depth=None, cause=None):
    if delivery is None:
        return Rejection(_TOKEN, code, message)
    eventId, causeId, eventType, _ = delivery
    return Rejection(_TOKEN, code, message, eventId, causeId, eventType, depth, cause)


def _selfLoop(delivery, signature):
    return _build("SISO_SELF_LOOP", "Self-loop: gate '" + _safeStr(signature) + "' returned its own type", delivery)


def _cycle(delivery, gateCount):
    depth = delivery[3]
    return _build("SISO_CYCLE",
                  "Cycle: '" + _safeStr(delivery[2]) + "' claimed at depth " + _safeStr(depth) + " with " +
                  _safeStr(gateCount) + " gates registered — a type repeated on this causal chain",
                  delivery, depth=depth)


def _gateThrew(delivery, signature, cause):
    return _build("SISO_GATE_THREW", "Gate '" + _safeStr(signature) + "' threw: " + _safeStr(cause),
                  delivery, cause=cause)


def _notEvent(delivery, signature, what):
    if delivery is None:
        return _build("SISO_NOT_EVENT", "Not an Event: the entry door was given " + _safeStr(what), None)
    return _build("SISO_NOT_EVENT", "Not an Event: gate '" + _safeStr(signature) + "' returned " + _safeStr(what) +
                  " (a gate returns None, an Event or a list of Events)", delivery)


type.__setattr__(Rejection, "selfLoop", callable_(_selfLoop, "selfLoop"))
type.__setattr__(Rejection, "cycle", callable_(_cycle, "cycle"))
type.__setattr__(Rejection, "gateThrew", callable_(_gateThrew, "gateThrew"))
type.__setattr__(Rejection, "notEvent", callable_(_notEvent, "notEvent"))
type.__setattr__(Rejection, "isRejection", callable_(lambda v: _typeof(v) is Rejection, "isRejection"))
lock(Rejection)


def describe(v):
    """A short name for a value that is not an Event, for a rejection's
    message. Asks the value nothing (no code of its runs)."""
    if v is None:
        return "None"
    t = _typeof(v)
    if t is bool or t is int or t is float:
        try:
            return "the " + t.__name__ + " " + repr(v)
        except Exception:
            return "the " + t.__name__ + " (too large to render)"
    if t is str:
        return "a str"
    return "a " + typeName(v) + " that is not an Event"


__all__ = ["Rejection"]
