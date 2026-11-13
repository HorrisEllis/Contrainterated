"""siso.core.gate -- Gate, a shape that recognises one type of event and
transforms it into others.

Contract py-core-api GATE. Port of lua/code/core/gate.lua.

  from siso.core.gate import Gate
  Gate(signature, transform=None, shape=None) -> a frozen Gate

`signature` (a str) is the unique key the stream routes by. `transform`
is the callable as given; the stream calls `gate.transform(event)` with
the event as its one positional argument. It returns:
  None                consumed, no follow-up
  an Event            one follow-up
  a list or a tuple   (exact types) of Events
Anything else is a rejection (SISO_NOT_EVENT). With no transform the gate
consumes and returns nothing (the core's no-op, the instance of a locked
class, not a Python function). `shape` is an optional declaration for
tooling ({"emits": [...], "rejects": [...]}); the stream never reads it.

A Gate has exactly the three slots; assigning or deleting an attribute
raises AttributeError, and calling __init__ again on a built gate is
refused. Its class is locked. The stream reads `signature` and
`transform` once, when the gate is registered.
"""

from siso.core.freeze import LockedClass, callable_, lock, typeName

_typeof = type


def _noTransform(event):
    return None


_NOOP = callable_(_noTransform, "noTransform")


def _refuse(self, k, v=None):
    raise AttributeError("a gate is frozen (field '" + (k if _typeof(k) is str else "?") + "')")


class Gate(metaclass=LockedClass):
    __slots__ = ("signature", "transform", "shape")

    def __init__(self, signature, transform=None, shape=None):
        try:
            _SIG.__get__(self)
        except AttributeError:
            pass
        else:
            raise AttributeError("a gate is frozen (it is built once)")
        if _typeof(signature) is not str:
            raise TypeError("Gate: signature is not a str (" + typeName(signature) + ")")
        if transform is None:
            transform = _NOOP
        elif not callable(transform):
            raise TypeError("Gate: transform is not callable (" + typeName(transform) + ")")
        _SIG.__set__(self, signature)
        _TRANSFORM.__set__(self, transform)
        _SHAPE.__set__(self, shape)

    __setattr__ = _refuse
    __delattr__ = _refuse

    def __repr__(self):
        try:
            return "Gate(" + repr(_SIG.__get__(self)) + ")"
        except Exception:
            return "Gate(<unset>)"


_SIG = Gate.__dict__["signature"]
_TRANSFORM = Gate.__dict__["transform"]
_SHAPE = Gate.__dict__["shape"]
lock(Gate)


def readGate(g):
    """(signature, transform) of a Gate, read once through its slots."""
    return _SIG.__get__(g), _TRANSFORM.__get__(g)


__all__ = ["Gate"]
