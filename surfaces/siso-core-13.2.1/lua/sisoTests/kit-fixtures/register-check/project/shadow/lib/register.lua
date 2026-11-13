-- Fixture: a second `register`, which shadow/fx-echo.test.lua puts ahead
-- of the project's on package.path. It lists the same gate, so the test
-- passes; only the file it resolved to differs.
return {
  "gates.fx_echo",
}
