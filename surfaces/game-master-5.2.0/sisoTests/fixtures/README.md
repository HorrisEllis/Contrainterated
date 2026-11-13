# sisoTests fixtures

`*_system.txt` / `*_user.txt` are reference prompts produced by the
**pre-port gates** (the Phase 5 codebase, before the core-10 port):
`ContextBuildGate::buildSystemPrompt/buildUserPrompt`,
`PortlandContextGate::buildPortlandSystemPrompt/buildPortlandUserPrompt`
and `ZoneContextGate::buildSystemPrompt/buildUserPrompt`, called on the
inputs below. They are the independent expected values for the
prompt-building gates: the ported gates must produce the same text.

| Fixture | Inputs |
|---|---|
| `generic_*` | state `generic_state.json`, template `data/worlds/derelict_spaceship.json`, action `examine the terminal` |
| `portland_*` | state `portland_state.json`, template `data/worlds/portland_scavenger.json`, action `walk to powells` |
| `zone_*` | world context `generic_state.json`, zone `engine_room` from parent `bridge` ("Command Bridge" / "Consoles flicker in the dark."), direction `down`, constraints `{lighting: dim, hazards: [steam, sparks]}` |

If a world template in `data/worlds/` changes, the Portland fixture
must be regenerated from a reviewed prompt, not from the gate under test.
