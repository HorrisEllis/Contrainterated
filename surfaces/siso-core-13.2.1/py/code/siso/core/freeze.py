"""siso.core.freeze -- the read-only form of every event and the copies the
doors hand out (py-core-api EVENT / READ-ONLY / STATELESS THROUGH THE EVENT
/ OWNERSHIP, py-observer-seam RECORD data and THE REJECTION; core 13.0.0).
Port of lua/code/core/freeze.lua (with lua/code/core/event.lua's Event,
whose public face is siso.core.event).

  from siso.core.freeze import FrozenDict, Event
  FrozenDict(data=None)   -> the core's frozen mapping of a dict (frozen as
                             Event freezes data); a FrozenDict is itself
  freeze(v, copying, memo)  the one engine (internal to the core):
      copying False  Event's construction: plain data copied into fresh
                     frozen values; what the core already froze (an Event,
                     a FrozenDict) kept by identity; anything that is not
                     plain data refused with
                     TypeError("Event: data at <path> is not plain data (<what>)")
      copying True   the receiver's own copy (entry, a sample, an
                     observer's record data): a fresh Event, FrozenDict and
                     tuple at every depth; `memo` (a dict) is shared by the
                     copies made for one owner, so sharing among them is
                     kept and nothing is shared with the original or with
                     another owner
  plain(v)          observer-seam THE REJECTION: a fresh frozen copy of a
                    rejection field (a gate's raised object) made of its own
                    items or attributes at every depth (see plain)

How the Lua mechanisms map (py-core-api SUBSTRATE):
  - Lua's proxy tables, their private KEY and their views vanish: an Event
    is a final slotted class whose __setattr__/__delattr__ raise, its data
    a FrozenDict (a mapping with no mutating method whose storage is a
    MappingProxyType in a slot), a list a tuple. Reads are the language's
    own; reading a field twice gives the same object.
  - "Recognising a frozen table" becomes `type(v) is Event` / `type(v) is
    FrozenDict` (exact: both classes are final, and an exact type check
    asks the value nothing, so a look-alike or a __class__ property is
    never recognised: X1's counterpart).
  - getmetatable answers become "every class reachable is locked": the
    core's classes are built by a metaclass that refuses setting or
    deleting a class attribute once the class is locked, and no class
    attribute that is not a dunder is a Python function (callables such
    as Event.isEvent are instances of locked slotted classes, and methods
    such as FrozenDict.keys are instances of locked slotted descriptor
    classes that bind with types.MethodType).
  - No class reachable from an event is an ABC (13.2.0): an ABC's registry
    is state anyone can write through register(), and a lock on register()
    is passed by super(type(T), T).register. FrozenDict implements the
    Mapping protocol itself (inherits nothing from collections.abc) and is
    registered with collections.abc.Mapping from outside, once, when this
    module loads, so isinstance(fd, Mapping) holds. Mapping's registry is a
    standard-library global reached by import, not through the event.
  - Data marks and "readonly:" sentinels vanish (None is null, a tuple is
    a list); with them R4.
  - Plain data is checked at construction, exact types only: str, int,
    float, bool, None, dict (str keys), list, tuple, Event, FrozenDict.
    Anything else -- a set, bytes, a function, a class instance, a
    subclass of a builtin, a non-str key, a cycle -- is refused, never
    dropped (Drift 7).

Every walk here is iterative (an explicit stack, never recursion), so data
of any depth the host can allocate is frozen and copied; within one call
(or one owner's memo) each original is handled once, so sharing is kept.
A cycle is refused (plain data is acyclic; a tuple cannot hold itself).
The path in a refusal is rendered only when a refusal names it, so depth
costs no memory beyond the stack. Nothing here is written after load:
the module holds the classes, the slot descriptors it captured and pure
functions. The cost of a call grows with the size of what it freezes or
copies, never with history.
"""

import collections.abc
import types

_typeof = type
_LEAVES = frozenset({str, int, float, bool, type(None)})
_NAME = type.__dict__["__name__"]
_LOCK = "__sisoLocked__"
_Mapping = collections.abc.Mapping


def typeName(v):
    """The name of v's class, read from the class itself (no metaclass code runs)."""
    try:
        n = _NAME.__get__(_typeof(v), type)
        if _typeof(n) is str:
            return n
    except Exception:
        pass
    return "object"


# ── locked classes ──────────────────────────────────────────────────

def _refuseClassWrite(cls, k):
    raise TypeError("siso.core: the core's classes are locked (cannot set or delete '" +
                    (k if _typeof(k) is str else "?") + "' on " + _NAME.__get__(cls, type) + ")")


class LockedClass(type):
    """The metaclass of every core class: once locked, a class refuses
    setting or deleting a class attribute (no class is a state channel)."""

    def __setattr__(cls, k, v):
        if _LOCK in type.__getattribute__(cls, "__dict__"):
            _refuseClassWrite(cls, k)
        type.__setattr__(cls, k, v)

    def __delattr__(cls, k):
        if _LOCK in type.__getattribute__(cls, "__dict__"):
            _refuseClassWrite(cls, k)
        type.__delattr__(cls, k)


def lock(cls):
    type.__setattr__(cls, _LOCK, True)
    return cls


def callable_(fn, name):
    """A callable class attribute that is not a Python function: the
    instance of a locked, slotted class whose __call__ is fn."""
    cls = LockedClass(name, (), {
        "__module__": __name__,
        "__slots__": (),
        "__call__": staticmethod(fn),
        "__repr__": lambda self: "<siso.core " + name + ">",
    })
    lock(cls)
    return cls()


def method_(fn, name):
    """A method of a core class that is not a Python function: the instance
    of a locked, slotted descriptor class; read on an instance it binds
    with types.MethodType (a builtin, which takes no attribute)."""
    def get(self, obj, owner=None):
        if obj is None:
            return self
        return types.MethodType(fn, obj)

    cls = LockedClass(name, (), {
        "__module__": __name__,
        "__slots__": (),
        "__get__": get,
        "__call__": staticmethod(fn),
        "__repr__": lambda self: "<siso.core method " + name + ">",
    })
    lock(cls)
    return cls()


# ── FrozenDict ──────────────────────────────────────────────────────

def _refuseWrite(self, k, v=None):
    raise AttributeError("read-only: an event and its data cannot be assigned (field '" +
                         (k if _typeof(k) is str else "?") + "')")


def _short(v):
    t = _typeof(v)
    if t in _LEAVES:
        try:
            return repr(v)
        except Exception:
            return "<" + t.__name__ + ">"
    if t is tuple:
        return "(...)" if v else "()"
    return "<" + typeName(v) + ">"


def _fdGet(self, k, default=None):
    return _FD_M.__get__(self).get(k, default)


def _fdKeys(self):
    return _FD_M.__get__(self).keys()


def _fdValues(self):
    return _FD_M.__get__(self).values()


def _fdItems(self):
    return _FD_M.__get__(self).items()


class FrozenDict(metaclass=LockedClass):
    """The core's frozen mapping: read through [], get, keys, values, items,
    len, iteration, `in` and ==; no mutating method; final. Not an ABC: it
    implements the Mapping protocol itself and is registered with
    collections.abc.Mapping below."""

    __slots__ = ("_m",)

    def __new__(cls, data=None):
        if data is None:
            return _mkFD({})
        t = _typeof(data)
        if t is FrozenDict:
            return data
        if t is not dict:
            raise TypeError("FrozenDict: data is not a mapping (" + typeName(data) + ")")
        return freeze(data, False, {})

    def __getitem__(self, k):
        return _FD_M.__get__(self)[k]

    def __iter__(self):
        return iter(_FD_M.__get__(self))

    def __len__(self):
        return len(_FD_M.__get__(self))

    def __contains__(self, k):
        try:
            return k in _FD_M.__get__(self)
        except TypeError:
            return False

    def __eq__(self, other):
        if isinstance(other, _Mapping):
            return dict(_FD_M.__get__(self).items()) == dict(other.items())
        return NotImplemented

    __hash__ = None
    __reversed__ = None

    get = method_(_fdGet, "get")
    keys = method_(_fdKeys, "keys")
    values = method_(_fdValues, "values")
    items = method_(_fdItems, "items")

    def __repr__(self):
        try:
            m = _FD_M.__get__(self)
        except Exception:
            return "FrozenDict(<unset>)"
        return "FrozenDict({" + ", ".join(_short(k) + ": " + _short(x) for k, x in m.items()) + "})"

    __setattr__ = _refuseWrite
    __delattr__ = _refuseWrite

    def __reduce__(self):
        return (FrozenDict, (dict(_FD_M.__get__(self)),))

    def __copy__(self):
        return self

    def __deepcopy__(self, memo):
        return self

    def __init_subclass__(cls, **kwargs):
        raise TypeError("FrozenDict is final")


_FD_M = FrozenDict.__dict__["_m"]
lock(FrozenDict)
collections.abc.Mapping.register(FrozenDict)


def _mkFD(d):
    o = object.__new__(FrozenDict)
    _FD_M.__set__(o, types.MappingProxyType(d))
    return o


# ── Event (public face: siso.core.event) ────────────────────────────

class Event(metaclass=LockedClass):
    """Event(type, data=None) -> a read-only event with exactly `type` and `data`."""

    __slots__ = ("type", "data")
    __module__ = "siso.core.event"

    def __new__(cls, type, data=None):  # noqa: A002 (the contract's name)
        if _typeof(type) is not str:
            raise TypeError("Event: type is not a str (" + typeName(type) + ")")
        if data is None:
            d = _mkFD({})
        else:
            t = _typeof(data)
            if t is FrozenDict:
                d = data
            elif t is dict:
                d = freeze(data, False, {})
            else:
                raise TypeError("Event: data is not a mapping (" + typeName(data) + ")")
        return _mkEvent(type, d)

    __setattr__ = _refuseWrite
    __delattr__ = _refuseWrite

    def __repr__(self):
        try:
            return "Event(" + _short(_EV_TYPE.__get__(self)) + ", " + repr(_EV_DATA.__get__(self)) + ")"
        except Exception:
            return "Event(<unset>)"

    def __reduce__(self):
        return (Event, (_EV_TYPE.__get__(self), _EV_DATA.__get__(self)))

    def __copy__(self):
        return self

    def __deepcopy__(self, memo):
        return self

    def __init_subclass__(cls, **kwargs):
        raise TypeError("Event is final")

    isEvent = callable_(lambda v: _typeof(v) is Event, "isEvent")


_EV_TYPE = Event.__dict__["type"]
_EV_DATA = Event.__dict__["data"]
lock(Event)


def _mkEvent(t, d):
    e = object.__new__(Event)
    _EV_TYPE.__set__(e, t)
    _EV_DATA.__set__(e, d)
    return e


def fieldsOf(e):
    """(type, data) of an Event; AttributeError when one is unset (an Event
    built past its constructor)."""
    return _EV_TYPE.__get__(e), _EV_DATA.__get__(e)


# ── the engine ──────────────────────────────────────────────────────

_KEEP, _MAP, _SEQ, _EV = 0, 1, 2, 3


class _NotPlain(Exception):
    """What is not plain data (internal: freeze turns it into the refusal)."""


def _open(v, copying):
    """-> (kind, items, eventType): _KEEP with items = the value itself, or a
    container kind with its children as a tuple of (key, child)."""
    t = _typeof(v)
    if t in _LEAVES:
        return _KEEP, v, None
    if t is dict:
        items = tuple(dict.items(v))
        for k, _ in items:
            if _typeof(k) is not str:
                raise _NotPlain("a key of type " + typeName(k))
        return _MAP, items, None
    if t is FrozenDict:
        if not copying:
            return _KEEP, v, None
        return _MAP, tuple(_FD_M.__get__(v).items()), None
    if t is list or t is tuple:
        return _SEQ, tuple(enumerate(v)), None
    if t is Event:
        if not copying:
            return _KEEP, v, None
        et, ed = fieldsOf(v)
        if _typeof(et) is not str:
            raise _NotPlain("an event type of type " + typeName(et))
        return _EV, (("data", ed),), et
    raise _NotPlain(typeName(v))


def _path(stack, last):
    out = ["data"]
    for i in range(1, len(stack)):
        out.append(".data" if stack[i - 1][1] == _EV else "[" + repr(stack[i][5]) + "]")
    out.append(".data" if stack[-1][1] == _EV else "[" + repr(last) + "]")
    return "".join(out)


def _refusal(path, what):
    return TypeError("Event: data at " + path + " is not plain data (" + what + ")")


def freeze(root, copying, memo):
    """root frozen (copying False) or copied (copying True); see the header."""
    try:
        kind, items, et = _open(root, copying)
    except _NotPlain as r:
        raise _refusal("data", r.args[0]) from None
    if kind == _KEEP:
        return items
    hit = memo.get(id(root))
    if hit is not None:
        return hit[1]
    # a frame: [original, kind, children, next index, built children, key, event type]
    stack = [[root, kind, items, 0, [], None, et]]
    onPath = {id(root)}
    while True:
        f = stack[-1]
        children = f[2]
        i = f[3]
        if i < len(children):
            f[3] = i + 1
            key, child = children[i]
            if _typeof(child) in _LEAVES:
                f[4].append(child)
                continue
            hit = memo.get(id(child))
            if hit is not None:
                f[4].append(hit[1])
                continue
            try:
                ck, citems, cet = _open(child, copying)
            except _NotPlain as r:
                raise _refusal(_path(stack, key), r.args[0]) from None
            if ck == _KEEP:
                f[4].append(citems)
                continue
            if id(child) in onPath:
                raise _refusal(_path(stack, key), "a cycle")
            onPath.add(id(child))
            stack.append([child, ck, citems, 0, [], key, cet])
            continue
        stack.pop()
        orig = f[0]
        onPath.discard(id(orig))
        kind = f[1]
        out = f[4]
        if kind == _MAP:
            built = _mkFD({k: x for (k, _), x in zip(children, out)})
        elif kind == _SEQ:
            built = tuple(out)
        else:
            if _typeof(out[0]) is not FrozenDict:
                raise TypeError("Event: an event's data is not a mapping (" + typeName(out[0]) + ")")
            built = _mkEvent(f[6], out[0])
        memo[id(orig)] = (orig, built)
        if not stack:
            return built
        stack[-1][4].append(built)


def copy(v, memo=None):
    """The receiver's own frozen copy of v (OWNERSHIP)."""
    return freeze(v, True, {} if memo is None else memo)


# ── the plain copy (observer-seam THE REJECTION) ────────────────────


def _keyOf(k):
    if _typeof(k) in _LEAVES:
        return k
    s = repr(k)
    return str.__str__(s)


def _openPlain(v):
    """-> (kind, items) for a container, or (_KEEP, value) for a leaf or a
    fresh Event."""
    t = _typeof(v)
    if t in _LEAVES:
        return _KEEP, v
    if t is Event:
        return _KEEP, freeze(v, True, {})
    if t is FrozenDict:
        return _MAP, tuple(_FD_M.__get__(v).items())
    if issubclass(t, dict):
        return _MAP, tuple(dict.items(v))
    if issubclass(t, _Mapping):
        return _MAP, tuple(v.items())
    if issubclass(t, list):
        return _SEQ, tuple(enumerate(list.copy(v)))
    if issubclass(t, tuple):
        return _SEQ, tuple(enumerate(tuple.__getitem__(v, slice(None))))
    try:
        d = object.__getattribute__(v, "__dict__")
    except Exception:
        d = None
    if _typeof(d) is dict:
        return _MAP, tuple(dict.items(d))
    return _MAP, ()


def plain(root):
    """observer-seam THE REJECTION: str, int, float, bool and None as they
    are; an Event a fresh Event; a dict, a FrozenDict or any other Mapping a
    FrozenDict of its items (a key that is not a leaf becomes repr(key)); a
    list or a tuple (subclasses included) a tuple of its members; any other
    object a FrozenDict of its own attributes (vars, else empty): nothing
    inherited, no method, no class. An object met again on its own path (a
    cycle) becomes None. Containers are read with the base types' own
    methods (dict.items, list.copy), so no code of a dict or list subclass
    runs; a foreign Mapping is read through its items(). Iterative."""
    kind, items = _openPlain(root)
    if kind == _KEEP:
        return items
    stack = [[root, kind, items, 0, []]]
    onPath = {id(root)}
    while True:
        f = stack[-1]
        children = f[2]
        i = f[3]
        if i < len(children):
            f[3] = i + 1
            child = children[i][1]
            if id(child) in onPath:
                f[4].append(None)
                continue
            ck, citems = _openPlain(child)
            if ck == _KEEP:
                f[4].append(citems)
                continue
            onPath.add(id(child))
            stack.append([child, ck, citems, 0, []])
            continue
        stack.pop()
        onPath.discard(id(f[0]))
        if f[1] == _MAP:
            built = _mkFD({_keyOf(k): x for (k, _), x in zip(children, f[4])})
        else:
            built = tuple(f[4])
        if not stack:
            return built
        stack[-1][4].append(built)
