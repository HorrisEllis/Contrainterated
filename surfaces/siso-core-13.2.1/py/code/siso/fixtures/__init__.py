"""siso.fixtures -- the runner of the language-agnostic fixture suite
(contract py-fixture-format, py/code/siso/contracts/fixture_format.py).
Port of lua/code/fixtures/.

  siso.fixtures.run   run(fixture, core=None), also the command
                      python3 -B py/code/siso/fixtures/run.py [dir]

  from siso.fixtures.run import run

This file imports nothing when it loads: the runner is imported by its
own name, so importing siso.fixtures never loads the core.
"""
