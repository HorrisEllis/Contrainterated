"""siso.kit -- the kit every Python project that vendors the core uses
(contract py-kit-api, py/code/siso/contracts/kit_api.py). Port of the Lua
kit (lua/code/kit/), as far as py-kit-api SUBSTRATE ports it.

  siso.kit.data   plain data and its canonical JSON
                  (null, array, isArray, encode, decode)
  siso.kit.run    the suite runner, also the command
                  python3 -B py/code/siso/kit/run.py [tests-dir]

This file imports nothing when it loads: each module is imported by its
own name (`from siso.kit import data`), so importing siso.kit.run never
loads the core, and importing siso.kit.data loads only the core's frozen
mapping it reads.

Not ported (py-kit-api SUBSTRATE, tooling for Lua gate authors):
kit.helper, kit.sha256, kit.digest, kit.canon, kit.census, kit.lint.
"""
