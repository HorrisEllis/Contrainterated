/**
 * Client gate census — every client gate has exactly one test.
 *
 *   1. Lists the gates public/js/gates/index.mjs registers, and checks
 *      every *Gate.mjs module in public/js/gates is registered.
 *   2. For each signature, finds sisoTests/client/<signature>.test.mjs
 *      and checks it obtains the gate through the helper, gate('<signature>').
 *   3. Runs every per-gate test file in its own Node process.
 *   4. Checks each file's accepted cases share one output shape (event
 *      types; for api_call its method, path pattern and reply type).
 *      Cases named "rejected …" are rejections and are excluded.
 *
 * Exit code 0 only when the census is clean and every test passes.
 * Final line: census (client): {passed}/{total} passed[, {failed} FAILED]
 *
 * Run: node sisoTests/client/census.mjs
 */
import { readdirSync, readFileSync, existsSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { definitions } from '../../public/js/gates/index.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const gatesDir = join(here, '../../public/js/gates');
const problems = [];
const rows = [];

const signatures = Object.keys(definitions);
const registered = new Set(Object.values(definitions).map(d => d.name));
for (const f of readdirSync(gatesDir).filter(f => f.endsWith('Gate.mjs')).sort()) {
    if (!registered.has(f.replace(/\.mjs$/, ''))) {
        problems.push(`gate module ${f} is not registered`);
    }
}

const tmp = mkdtempSync(join(tmpdir(), 'siso-client-shapes-'));
let passed = 0;
for (const sig of signatures) {
    const name = definitions[sig].name;
    const file = join(here, `${sig}.test.mjs`);
    if (!existsSync(file)) {
        problems.push(`${sig} (${name}): no test file`);
        rows.push(`  ✗ ${sig.padEnd(22)} ${name.padEnd(22)} missing`);
        continue;
    }
    if (!readFileSync(file, 'utf8').includes(`gate('${sig}')`)) {
        problems.push(`${sig}: test does not obtain the gate through gate('${sig}')`);
    }
    const shapes = join(tmp, `${sig}.jsonl`);
    writeFileSync(shapes, '');
    const r = spawnSync(process.execPath, [file], { env: { ...process.env, SISO_SHAPES: shapes }, encoding: 'utf8' });
    const lines = (r.stdout + r.stderr).trim().split('\n');
    const last = r.stdout.trim().split('\n').pop() ?? '';
    const accepted = new Set();
    let rejections = 0;
    for (const line of readFileSync(shapes, 'utf8').split('\n').filter(Boolean)) {
        const rec = JSON.parse(line);
        if (rec.case.startsWith('rejected')) {
            rejections++;
        } else {
            accepted.add(JSON.stringify([...rec.types].sort()));
        }
    }
    if (accepted.size > 1) {
        problems.push(`${sig}: accepted cases have different output shapes: ${[...accepted].join(' | ')}`);
    }
    const ok = r.status === 0;
    if (ok) {
        passed++;
    } else {
        problems.push(`${sig}: test failed — ${last}`);
        console.log(lines.join('\n'));
    }
    const shape = accepted.size ? JSON.parse([...accepted][0]).join(',') : '';
    rows.push(`  ${ok ? '✓' : '✗'} ${sig.padEnd(22)} ${name.padEnd(22)} ${last.padEnd(36)} ${rejections} rejection case(s); shape [${shape}]`);
}
rmSync(tmp, { recursive: true, force: true });

console.log(`Client gate census — ${signatures.length} registered gates`);
console.log(rows.join('\n') + '\n');
if (problems.length) {
    console.log('Problems:\n  - ' + problems.join('\n  - ') + '\n');
}
const total = signatures.length;
const untested = signatures.filter(s => !existsSync(join(here, `${s}.test.mjs`)));
console.log(`gates: ${total}, tested: ${total - untested.length}, untested: ${untested.length ? untested.join(', ') : 'none'}`);
const clean = passed === total && problems.length === 0;
console.log(clean ? `census (client): ${passed}/${total} passed` : `census (client): ${passed}/${total} passed, ${Math.max(total - passed, problems.length)} FAILED`);
process.exit(clean ? 0 : 1);
