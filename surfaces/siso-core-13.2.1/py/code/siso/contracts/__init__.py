"""siso.contracts -- the four contracts of the Python instance, each with its checks.

  siso.contracts.core_api        core-api: what the core hands everyone outside it
  siso.contracts.observer_seam   observer-seam: what the stream shows an observer
  siso.contracts.fixture_format  fixture-format: the shared suite's format and matching
  siso.contracts.kit_api         kit-api: what the kit promises its consumers

Ports of lua/code/contracts/*.lua. A contract imports only the standard
library: it stands between the sides, never inside one. This file imports
nothing.
"""
