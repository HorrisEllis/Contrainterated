// TemplatesRequestGate — app_started → api_call GET /templates
import { gate, run, test, same, finish } from './helper.mjs';

const g = gate('app_started');

test('accepted: asks for the world list', () => {
    same(run(g, {}), [
        { type: 'api_call', data: { method: 'GET', path: '/templates', body: null, reply: 'templates_replied', carry: {} } },
    ]);
});

test('accepted: anything on the entry is ignored (the retry button enters the same event)', () => {
    same(run(g, { from: 'retry' }), [
        { type: 'api_call', data: { method: 'GET', path: '/templates', body: null, reply: 'templates_replied', carry: {} } },
    ]);
});

finish('app_started');
