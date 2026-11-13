/**
 * gates/index.mjs — every client gate, keyed by signature.
 *
 * Each gate module exports `signature`, `emits` (a declaration for
 * tooling; the stream never reads it) and a pure `transform`. gates()
 * builds the core gates the driver registers; registration order
 * carries no meaning. definition(sig) is how tests obtain a real gate.
 */
import { Gate } from '../../../siso/js/code/core/index.mjs';
import * as TemplatesRequestGate from './TemplatesRequestGate.mjs';
import * as WorldOptionsGate from './WorldOptionsGate.mjs';
import * as StartGameGate from './StartGameGate.mjs';
import * as GameStartedGate from './GameStartedGate.mjs';
import * as CommandSendGate from './CommandSendGate.mjs';
import * as TurnShownGate from './TurnShownGate.mjs';
import * as RejectionShownGate from './RejectionShownGate.mjs';

const modules = {
    TemplatesRequestGate, WorldOptionsGate, StartGameGate,
    GameStartedGate, CommandSendGate, TurnShownGate, RejectionShownGate,
};

/** signature → {name, module} */
export const definitions = Object.freeze(Object.fromEntries(
    Object.entries(modules).map(([name, m]) => [m.signature, Object.freeze({ name, module: m })])
));

export function gate(signature) {
    const d = definitions[signature];
    if (!d) {
        throw new Error(`No client gate registered for '${signature}'`);
    }
    return Gate(d.module.signature, d.module.transform, { emits: d.module.emits });
}

export function gates() {
    return Object.keys(definitions).map(gate);
}
