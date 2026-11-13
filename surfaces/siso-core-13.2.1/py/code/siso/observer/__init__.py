"""siso.observer -- observers on the observer seam (core 13, Python instance).

  siso.observer.streamlog   StreamLog, the audit trail (port of
                            lua/code/observer/streamlog.lua)

Re-exports StreamLog. Nothing here imports siso.core: an observer knows
the stream only through the batches it is shown (observer-seam).
"""

from siso.observer.streamlog import StreamLog

__all__ = ["StreamLog"]
