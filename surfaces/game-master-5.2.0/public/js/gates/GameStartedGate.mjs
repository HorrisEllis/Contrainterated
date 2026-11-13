/**
 * GameStartedGate — the new game arrived: switch to the game screen.
 *
 * Signature: game_create_replied {http, playerName, gameId, previous}
 * Emits:     session {gameId, playerName, busy: false}, ui_screen {screen: 'game'},
 *            ui_hud, ui_log (the opening narrative)
 * Rejects:   (abort) the game was not created → session (previous, not busy),
 *            ui_start_button {busy: false}, ui_log (the error)
 *
 * The game id is the server's when it sends one, else the one asked for.
 */
import { session, ui } from '../events/io.mjs';
import { apiResult } from '../pure/api.mjs';
import { hud, narrative, line } from '../pure/view.mjs';
import { text } from '../pure/text.mjs';

export const signature = 'game_create_replied';
export const emits = ['session', 'ui_screen', 'ui_hud', 'ui_log'];

export function transform(event) {
    const d = event.data;
    const r = apiResult(d.http);
    if (!r.ok) {
        return [
            session(d.previous.gameId, d.previous.playerName, false),
            ui('ui_start_button', { busy: false }),
            ui('ui_log', line('error', 'ERROR: ' + r.error)),
        ];
    }
    const gameId = text(r.data?.gameId) || d.gameId;
    return [
        session(gameId, d.playerName, false),
        ui('ui_screen', { screen: 'game' }),
        ui('ui_hud', hud(r.data, d.playerName)),
        ui('ui_log', narrative(r.data)),
    ];
}
