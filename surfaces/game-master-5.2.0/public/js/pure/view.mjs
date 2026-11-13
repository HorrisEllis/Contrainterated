/**
 * pure/view.mjs — what the HUD and the log show for a game reply.
 * Pure: no I/O, no state, never throws (pure/text.mjs). The boundary
 * draws exactly what these say.
 */
import { text, texts } from './text.mjs';

/**
 * The HUD for a game reply: zone and turn when the reply has them (null
 * leaves that field as it is), the player's name, and the suggested
 * actions when the reply has them (null leaves the chips as they are).
 */
export function hud(data, playerName) {
    const zone = text(data?.currentZone);
    return {
        zone: zone ? zone.toUpperCase().replace(/_/g, ' ') : null,
        turn: typeof data?.turnCount === 'number' && Number.isFinite(data.turnCount) ? data.turnCount : null,
        player: text(playerName).toUpperCase(),
        chips: Array.isArray(data?.suggestedActions) ? texts(data.suggestedActions) : null,
    };
}

/** The narrative line for a game reply, with the player's inventory after it. */
export function narrative(data) {
    const player = data?.player;
    const inventory = player !== null && typeof player === 'object' ? texts(player.inventory) : [];
    return { kind: 'narrative', text: text(data?.narrative), inventory };
}

/** A plain log line (a command echo or an error). */
export function line(kind, text) {
    return { kind, text, inventory: [] };
}
