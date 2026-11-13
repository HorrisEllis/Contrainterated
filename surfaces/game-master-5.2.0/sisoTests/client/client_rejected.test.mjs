// RejectionShownGate — client_rejected → session (free) + ui_busy + ui_start_button + ui_error_overlay
import { gate, run, test, same, finish } from './helper.mjs';

const g = gate('client_rejected');

function expected(s, code, message) {
    return [
        { type: 'session', data: { gameId: s.gameId, playerName: s.playerName, busy: false } },
        { type: 'ui_busy', data: { busy: false, clearInput: false } },
        { type: 'ui_start_button', data: { busy: false } },
        { type: 'ui_error_overlay', data: { visible: true, endpoint: 'client', code, message } },
    ];
}

test('accepted: a turn reply rejected mid-game frees the input and shows the code', () => {
    const s = { gameId: 'game-1', playerName: 'Captain', busy: true };
    same(run(g, { code: 'SISO_GATE_THREW', message: "Gate 'command_replied' threw: boom", entryType: 'command_replied', session: s }),
        expected(s, 'SISO_GATE_THREW', "Gate 'command_replied' threw: boom (while handling command_replied)"));
});

test('accepted: a start rejected before any game restores the start button', () => {
    const s = { gameId: null, playerName: '', busy: true };
    same(run(g, { code: 'SISO_NOT_EVENT', message: 'Not an Event', entryType: 'game_create_replied', session: s }),
        expected(s, 'SISO_NOT_EVENT', 'Not an Event (while handling game_create_replied)'));
});

test('accepted: a code or message that is not text', () => {
    const s = { gameId: 'g', playerName: 'P', busy: false };
    same(run(g, { code: null, message: { toString: 1 }, entryType: 'app_started', session: s }),
        expected(s, 'SISO_REJECTED', ' (while handling app_started)'));
});

finish('client_rejected');
