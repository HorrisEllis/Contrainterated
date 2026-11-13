/**
 * RejectionShownGate — a client propagation was rejected: say so and
 * free the page.
 *
 * Signature: client_rejected {code, message, entryType, session}
 *            entered by the driver when the core rejected a propagation
 *            (a gate threw, looped, or returned something that is not an
 *            event); nothing that propagation returned was committed, so
 *            a busy flag it would have cleared is still set
 * Emits:     session (not busy), ui_busy {busy: false, clearInput: false},
 *            ui_start_button {busy: false},
 *            ui_error_overlay {visible: true, endpoint: 'client', code, message}
 */
import { session, ui } from '../events/io.mjs';
import { text } from '../pure/text.mjs';

export const signature = 'client_rejected';
export const emits = ['session', 'ui_busy', 'ui_start_button', 'ui_error_overlay'];

export function transform(event) {
    const d = event.data;
    const s = d.session;
    return [
        session(s.gameId, s.playerName, false),
        ui('ui_busy', { busy: false, clearInput: false }),
        ui('ui_start_button', { busy: false }),
        ui('ui_error_overlay', {
            visible: true, endpoint: 'client', code: text(d.code, 'SISO_REJECTED'),
            message: `${text(d.message)} (while handling ${text(d.entryType)})`,
        }),
    ];
}
