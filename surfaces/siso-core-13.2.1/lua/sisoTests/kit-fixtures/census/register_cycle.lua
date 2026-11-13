-- Fixture register: fx:cyc_a emits fx:cyc_b, which emits fx:cyc_a. Each
-- gate's own test (cycle/) passes; the type graph has a cycle.
return {
  "kit-fixtures.census.gates.fx_cyc_a",
  "kit-fixtures.census.gates.fx_cyc_b",
}
