"""siso.core -- the Python instance of the SISO core (py-core-api).

  from siso.core import Event, Gate, Rejection, Stream, FrozenDict

  siso.core.event      Event
  siso.core.gate       Gate
  siso.core.stream     Stream
  siso.core.rejection  Rejection
  siso.core.freeze     FrozenDict, the core's frozen mapping, and the
                       copies the stream takes

The core imports only pure standard-library modules (abc,
collections.abc, types) and nothing that does I/O.
"""

from siso.core.event import Event
from siso.core.freeze import FrozenDict
from siso.core.gate import Gate
from siso.core.rejection import Rejection
from siso.core.stream import Stream

__all__ = ["Event", "FrozenDict", "Gate", "Rejection", "Stream"]
