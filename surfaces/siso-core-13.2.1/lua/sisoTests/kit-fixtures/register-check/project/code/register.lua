-- Fixture project for sisoTests/kit-register-check.test.lua: its register
-- (module `register`, the default name), read by kit.helper and by
-- code/kit/census.lua run from this directory. fx: signatures, not project
-- gates.
--   code/register_other.lua  another register listing the same gate
--   sisoTests/               a clean per-gate test and a plain suite
--   shadow/                  the gate's test puts shadow/lib/ (another
--                            `register`) ahead on package.path
--   rerun/                   the gate's test re-runs itself with
--                            KIT_REGISTER=register_other
--   noline/                  the gate's test hides the helper's register line
--   require-run.lua          requires kit.run as a module
return {
  "gates.fx_echo",
}
