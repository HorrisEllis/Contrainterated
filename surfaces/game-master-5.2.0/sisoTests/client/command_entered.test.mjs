// CommandSendGate — command_entered → session (busy) + ui_busy + ui_status + ui_log + api_call
import { gate, run, test, same, finish } from './helper.mjs';

const g = gate('command_entered');
const ready = { gameId: 'game-1', playerName: 'Captain', busy: false };

function expected(action, s = ready) {
    const busy = { gameId: s.gameId, playerName: s.playerName, busy: true };
    return [
        { type: 'session', data: busy },
        { type: 'ui_busy', data: { busy: true, clearInput: true } },
        { type: 'ui_status', data: { text: 'PROCESSING...', kind: 'processing' } },
        { type: 'ui_log', data: { kind: 'command', text: '> ' + action, inventory: [] } },
        { type: 'api_call', data: {
            method: 'POST', path: `/game/${s.gameId}/action`, body: { action },
            reply: 'command_replied', carry: { session: busy },
        } },
    ];
}

test('accepted: a typed command is echoed and sent', () => {
    same(run(g, { action: 'examine the control panel', session: ready }), expected('examine the control panel'));
});

test('accepted: surrounding spaces are trimmed (a chip\'s text is sent the same way)', () => {
    same(run(g, { action: '  Look around ', session: ready }), expected('Look around'));
});

test('accepted: the game id is path-encoded', () => {
    const s = { gameId: 'my-game_123', playerName: 'N', busy: false };
    same(run(g, { action: 'wait', session: s }), expected('wait', s));
});

test('accepted: a non-string action (never from the page) is read as text, not thrown on', () => {
    same(run(g, { action: 42, session: ready }), expected('42'));
});

test('rejected (drop): an action that is not text (an object) is empty', () => {
    same(run(g, { action: { toString: 1 }, session: ready }), []);
});

test('rejected (drop): an empty command', () => {
    same(run(g, { action: '   ', session: ready }), []);
});

test('rejected (drop): a turn already in flight', () => {
    same(run(g, { action: 'look', session: { ...ready, busy: true } }), []);
});

test('rejected (drop): no game started', () => {
    same(run(g, { action: 'look', session: { gameId: null, playerName: '', busy: false } }), []);
});

finish('command_entered');
