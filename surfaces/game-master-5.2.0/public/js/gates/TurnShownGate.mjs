/**
 * TurnShownGate — the turn's reply arrived: show it and free the input.
 *
 * Signature: command_replied {http, session}
 * Emits:     session (not busy), ui_busy {busy: false, clearInput: false},
 *            ui_status {text: 'CONNECTED', kind: 'connected'}, ui_hud,
 *            ui_log (the narrative)
 * Rejects:   (abort) the turn failed → session (not busy), ui_busy {busy: false},
 *            ui_status {text: 'ERROR', kind: 'error'}, ui_log (the error)
 */
import { session, ui } from '../events/io.mjs';
import { apiResult } from '../pure/api.mjs';
import { hud, narrative, line } from '../pure/view.mjs';

export const signature = 'command_replied';
export const emits = ['session', 'ui_busy', 'ui_status', 'ui_hud', 'ui_log'];

export function transform(event) {
    const s = event.data.session;
    const r = apiResult(event.data.http);
    const free = [session(s.gameId, s.playerName, false), ui('ui_busy', { busy: false, clearInput: false })];
    if (!r.ok) {
        return [
            ...free,
            ui('ui_status', { text: 'ERROR', kind: 'error' }),
            ui('ui_log', line('error', 'ERROR: ' + r.error)),
        ];
    }
    return [
        ...free,
        ui('ui_status', { text: 'CONNECTED', kind: 'connected' }),
        ui('ui_hud', hud(r.data, s.playerName)),
        ui('ui_log', narrative(r.data)),
    ];
}
