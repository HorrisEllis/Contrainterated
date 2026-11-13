// WorldOptionsGate — templates_replied → ui_worlds + ui_error_overlay (hidden)
import { gate, run, test, same, http, networkError, finish } from './helper.mjs';

const g = gate('templates_replied');
const hidden = { type: 'ui_error_overlay', data: { visible: false, endpoint: null, code: null, message: null } };

// The body GET /api/templates sends (SPEC REST contract).
const body = {
    success: true,
    data: [
        { id: 'derelict_spaceship', name: 'Derelict Spaceship', theme: 'sci-fi horror', description: '' },
        { id: 'the_matrix', name: 'The Matrix', theme: 'cyberpunk', description: '' },
    ],
};

test('accepted: the catalog becomes the picker options, in the order sent', () => {
    same(run(g, { http: http(200, body) }), [
        { type: 'ui_worlds', data: { options: [
            { id: 'derelict_spaceship', name: 'Derelict Spaceship' },
            { id: 'the_matrix', name: 'The Matrix' },
        ] } },
        hidden,
    ]);
});

test('accepted: an older {data: {templates}} body is read too', () => {
    same(run(g, { http: http(200, { success: true, data: { templates: [{ id: 'a', name: 'A' }] } }) }), [
        { type: 'ui_worlds', data: { options: [{ id: 'a', name: 'A' }] } },
        hidden,
    ]);
});

test('accepted: an empty catalog is an empty picker', () => {
    same(run(g, { http: http(200, { success: true, data: [] }) }), [
        { type: 'ui_worlds', data: { options: [] } },
        hidden,
    ]);
});

test('accepted: hostile entries (objects for id or name, a bare string) never throw', () => {
    same(run(g, { http: http(200, { success: true, data: [{ id: { toString: 1 }, name: 'X' }, { id: 'b', name: { toString: 1 } }, 'c'] }) }), [
        { type: 'ui_worlds', data: { options: [{ id: '', name: 'X' }, { id: 'b', name: 'b' }, { id: '', name: '' }] } },
        hidden,
    ]);
});

const overlay = message => [{ type: 'ui_error_overlay', data: { visible: true, endpoint: './api/templates', code: 'API_ERROR', message } }];

test('rejected (abort): server error body', () => {
    same(run(g, { http: http(500, { success: false, error: 'Internal server error (fatal)' }) }), overlay('Internal server error (fatal)'));
});

test('rejected (abort): not JSON (a PHP error page)', () => {
    same(run(g, { http: http(502, null, 'text/html') }), overlay('Unexpected response type (502)'));
});

test('rejected (abort): network failure', () => {
    same(run(g, { http: networkError('Failed to fetch') }), overlay('Failed to fetch'));
});

test('rejected (abort): success false without an error message', () => {
    same(run(g, { http: http(200, { success: false }) }), overlay('HTTP 200'));
});

finish('templates_replied');
