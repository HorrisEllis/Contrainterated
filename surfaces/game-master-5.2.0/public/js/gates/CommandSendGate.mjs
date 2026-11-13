/**
 * CommandSendGate — the player enters a command (typed, or a chip).
 *
 * Signature: command_entered {action, session}
 * Emits:     session (busy), ui_busy {busy: true, clearInput: true},
 *            ui_status {text: 'PROCESSING...', kind: 'processing'},
 *            ui_log (the echoed command),
 *            api_call POST /game/{gameId}/action {action} → command_replied {session}
 * Rejects:   (drop) an empty command; a request already in flight
 *            (session.busy); no game started (session.gameId null)
 */
import { api, session, ui } from '../events/io.mjs';
import { line } from '../pure/view.mjs';
import { text } from '../pure/text.mjs';

export const signature = 'command_entered';
export const emits = ['session', 'ui_busy', 'ui_status', 'ui_log', 'api_call'];

export function transform(event) {
    const s = event.data.session;
    const action = text(event.data.action).trim();
    if (action === '' || s.busy || !s.gameId) {
        return null;
    }
    const busy = { gameId: s.gameId, playerName: s.playerName, busy: true };
    return [
        session(busy.gameId, busy.playerName, true),
        ui('ui_busy', { busy: true, clearInput: true }),
        ui('ui_status', { text: 'PROCESSING...', kind: 'processing' }),
        ui('ui_log', line('command', '> ' + action)),
        api('POST', `/game/${encodeURIComponent(s.gameId)}/action`, { action }, 'command_replied', { session: busy }),
    ];
}
