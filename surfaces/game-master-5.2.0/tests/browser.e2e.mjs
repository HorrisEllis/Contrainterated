/**
 * Browser end-to-end check (optional; not in run_tests.php).
 *
 * Starts the dev server with the mock AI, opens the page in headless
 * Chromium, and plays: worlds load → start a game → a typed command →
 * a chip. Asserts on the DOM, and that the page logs no errors (the
 * old footer double-load included). Removes the save files it made.
 *
 * Needs Playwright with a Chromium (not a project dependency):
 *   NODE_PATH=$(npm root -g) node tests/browser.e2e.mjs
 */
import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import { readdirSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as H from '../siso/js/code/harness.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
let chromium;
try {
    ({ chromium } = createRequire(import.meta.url)('playwright'));
} catch {
    console.log('browser-e2e: skipped (playwright not installed)');
    console.log('browser-e2e: 0/0 passed');
    process.exit(0);
}

const port = 8000 + Math.floor(Math.random() * 900);
const savesBefore = new Set(readdirSync(join(root, 'saves')));
const server = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: root, env: { ...process.env, GAMEMASTER_MOCK_AI: 'true' }, stdio: 'ignore',
});
await new Promise(r => setTimeout(r, 800));

const browser = await chromium.launch();
const page = await browser.newPage();
const problems = [];
// Off-site resources (Google Fonts) may be unreachable where this runs; only
// the page's own errors and failed same-origin requests count.
page.on('console', m => {
    if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) problems.push(m.text());
});
page.on('requestfailed', r => {
    if (r.url().startsWith(`http://127.0.0.1:${port}/`)) problems.push(`request failed: ${r.url()}`);
    else console.log(`  (off-site request failed, ignored: ${r.url().slice(0, 60)})`);
});
page.on('response', r => {
    if (r.url().startsWith(`http://127.0.0.1:${port}/`) && r.status() >= 400) problems.push(`HTTP ${r.status()}: ${r.url()}`);
});
page.on('pageerror', e => problems.push(String(e)));

const steps = [];
const step = (name, fn) => steps.push([name, fn]);

step('the world picker fills from /api/templates', async () => {
    await page.goto(`http://127.0.0.1:${port}/`);
    await page.waitForFunction(() => document.querySelectorAll('#world-template option').length > 0);
    const options = await page.$$eval('#world-template option', os => os.map(o => o.value));
    H.assert(options.includes('derelict_spaceship'), 'derelict_spaceship listed: ' + options.join(','));
    H.assert(await page.$eval('#error-overlay', e => e.classList.contains('hidden')), 'error overlay hidden');
});

step('starting a game shows the game screen, HUD and narrative', async () => {
    await page.fill('#player-name', 'Captain');
    await page.selectOption('#world-template', 'derelict_spaceship');
    await page.click('#start-btn');
    await page.waitForFunction(() => !document.getElementById('game-screen').classList.contains('hidden'));
    H.assertEqual(await page.textContent('#stat-player'), 'CAPTAIN');
    H.assertEqual(await page.textContent('#stat-zone'), 'AIRLOCK');
    H.assertEqual(await page.textContent('#stat-turn'), '0');
    H.assert((await page.$$('#output .message.narrative')).length === 1, 'opening narrative');
    H.assert((await page.$$('#action-chips button')).length > 0, 'chips');
});

step('a typed command is echoed, answered, and frees the input', async () => {
    await page.fill('#command-input', 'look around');
    await page.press('#command-input', 'Enter');
    await page.waitForFunction(() => document.getElementById('stat-turn').textContent === '1');
    const lines = await page.$$eval('#output .message', ms => ms.map(m => m.className + '|' + m.textContent));
    H.assert(lines.some(l => l.startsWith('message command|> look around')), 'echo: ' + lines.join(' / '));
    H.assertEqual(await page.textContent('#status'), 'CONNECTED');
    H.assertEqual(await page.$eval('#command-input', e => e.disabled), false, 'input enabled');
    H.assertEqual(await page.$eval('#processing-overlay', e => e.classList.contains('active')), false, 'spinner off');
});

step('a chip plays a turn', async () => {
    const chip = await page.textContent('#action-chips button');
    await page.click('#action-chips button');
    await page.waitForFunction(() => document.getElementById('stat-turn').textContent === '2');
    const lines = await page.$$eval('#output .message.command', ms => ms.map(m => m.textContent));
    H.assertEqual(lines[lines.length - 1], '> ' + chip.trim());
});

step('no errors in the console', async () => {
    H.assertEqual(problems.length, 0, problems.join(' | '));
});

for (const [name, fn] of steps) {
    let error = null;
    try {
        await fn();
    } catch (e) {
        error = e;
    }
    H.test(name, () => { if (error) throw error; });
}

await browser.close();
server.kill();
for (const f of readdirSync(join(root, 'saves'))) {
    if (!savesBefore.has(f)) {
        rmSync(join(root, 'saves', f));
    }
}
rmSync(join(root, 'errors.log'), { force: true });
H.finish('browser-e2e');
