"""siso -- the Python instance of the SISO core (port of the Lua reference, lua/code/).

The package (import root py/code/) holds, as the later phases write them:

  siso.core       Event, Gate, Rejection, Stream and the frozen data they carry
  siso.observer   streamlog, the observer
  siso.kit        data (canonical JSON) and run (the suite runner)
  siso.fixtures   run, the runner of the shared fixture suite
  siso.contracts  core_api, observer_seam, fixture_format, kit_api
  siso.harness    the test harness every Python suite reports through

This file imports nothing when it loads: each module is imported by its
own name, so importing siso.contracts or siso.harness never loads the
core (a contract stands between the sides, never inside one).

`from siso import Event, Gate, Rejection, Stream` works: those four names
are resolved on first use from siso.core (PEP 562 module __getattr__).
"""

_CORE = ("Event", "Gate", "Rejection", "Stream")


def __getattr__(name):
    if name in _CORE:
        import siso.core

        return getattr(siso.core, name)
    raise AttributeError("module 'siso' has no attribute " + repr(name))


def __dir__():
    return sorted(list(globals()) + list(_CORE))
