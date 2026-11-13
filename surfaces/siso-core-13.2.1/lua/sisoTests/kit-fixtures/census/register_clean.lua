-- Fixture register for sisoTests/kit-census.test.lua (fx: signatures, not
-- a project's gates). Read by code/kit/census.lua as its register argument
-- "kit-fixtures.census.register_clean"; the census passes it on to each
-- per-gate test as KIT_REGISTER. Order carries no meaning.
--   gates/       the fixture gates of every register here
--   clean/       a test for each gate, a plain suite and a fixtures/
--                directory the census never enters: the census passes
--   untested/    fx:start has no test
--   orphan/      a per-gate test named for no registered gate
--   nohelper/    fx:start's test requires its gate module; fx:route's is
--                a plain suite calling the transform
--   failing/     fx:double's test expects a wrong value
--   override/    fx:double's test calls use_register; fx:route's plants
--                the register through package.loaded
--   noregister/  only a plain suite (run with a register that does not exist)
return {
  "kit-fixtures.census.gates.fx_start",
  "kit-fixtures.census.gates.fx_double",
  "kit-fixtures.census.gates.fx_route",
}
