/**
 * StartGameGate — the player starts a game.
 *
 * Signature: start_submitted {playerName, worldTemplateId, gameId, session}
 *            playerName as typed; gameId made by the boundary
 *            ('game-' + its clock); session {gameId, playerName, busy}
 * Emits:     session (busy), ui_start_button {busy: true},
 *            api_call POST /game/new {worldTemplateId, playerName, gameId}
 *            → game_create_replied {playerName, gameId, previous}
 * Rejects:   (drop) a request is already in flight (session.busy)
 *
 * An empty name becomes 'Explorer', as before.
 */
import { api, session, ui } from '../events/io.mjs';
import { text } from '../pure/text.mjs';

export const signature = 'start_submitted';
export const emits = ['session', 'ui_start_button', 'api_call'];

export function transform(event) {
    const d = event.data;
    const previous = d.session;
    if (previous.busy) {
        return null;
    }
    const playerName = text(d.playerName).trim() || 'Explorer';
    const worldTemplateId = text(d.worldTemplateId);
    const gameId = text(d.gameId);
    return [
        session(previous.gameId, previous.playerName, true),
        ui('ui_start_button', { busy: true }),
        api('POST', '/game/new', { worldTemplateId, playerName, gameId }, 'game_create_replied',
            { playerName, gameId, previous }),
    ];
}
