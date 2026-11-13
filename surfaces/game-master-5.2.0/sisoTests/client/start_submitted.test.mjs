// StartGameGate — start_submitted → session (busy) + ui_start_button + api_call POST /game/new
import { gate, run, test, same, finish } from './helper.mjs';

const g = gate('start_submitted');
const idle = { gameId: null, playerName: '', busy: false };

function expected(playerName, worldTemplateId, gameId, previous = idle) {
    return [
        { type: 'session', data: { gameId: previous.gameId, playerName: previous.playerName, busy: true } },
        { type: 'ui_start_button', data: { busy: true } },
        { type: 'api_call', data: {
            method: 'POST', path: '/game/new', body: { worldTemplateId, playerName, gameId },
            reply: 'game_create_replied', carry: { playerName, gameId, previous },
        } },
    ];
}

test('accepted: name trimmed, request carries what the reply needs', () => {
    same(run(g, { playerName: '  Captain ', worldTemplateId: 'derelict_spaceship', gameId: 'game-1700000000000', session: idle }),
        expected('Captain', 'derelict_spaceship', 'game-1700000000000'));
});

test('accepted: an empty name becomes Explorer', () => {
    same(run(g, { playerName: '   ', worldTemplateId: 'the_matrix', gameId: 'game-1', session: idle }),
        expected('Explorer', 'the_matrix', 'game-1'));
});

test('accepted: starting again after a failed start keeps the previous session to restore', () => {
    const previous = { gameId: null, playerName: '', busy: false };
    same(run(g, { playerName: 'Neo', worldTemplateId: 'the_matrix', gameId: 'game-2', session: previous }),
        expected('Neo', 'the_matrix', 'game-2', previous));
});

test('accepted: a name that is not text becomes Explorer', () => {
    same(run(g, { playerName: { toString: 1 }, worldTemplateId: 'the_matrix', gameId: 'game-4', session: idle }),
        expected('Explorer', 'the_matrix', 'game-4'));
});

test('rejected (drop): a start already in flight (double click, Enter twice)', () => {
    same(run(g, { playerName: 'Neo', worldTemplateId: 'the_matrix', gameId: 'game-3', session: { gameId: null, playerName: '', busy: true } }), []);
});

finish('start_submitted');
