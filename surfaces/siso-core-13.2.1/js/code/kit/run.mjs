/*
kit/run.mjs — every suite in the tests directory, with totals (kit-api).
Port of the suite half of lua/code/kit/run.lua (generalised from jää
2.14.4 sisoTests/run.lua). There is no gate group and no digest line
(kit-api SUBSTRATE: kit.helper and kit.digest are not ported).

  node js/code/kit/run.mjs [tests-dir]     (run from the project root;
                                            tests-dir defaults to
                                            js/sisoTests; further
                                            arguments are ignored)

  import * as R from "./kit/run.mjs";      the same file, as a module

Each suite runs in its own process: this Node (process.execPath) with the
suite's path, from the current directory. A suite is any `*.test.mjs`
file under the tests directory, at any depth, except under a fixtures
directory: a directory named `fixtures` or ending in `-fixtures` /
`_fixtures` (`kit-fixtures/`, `substrate/fixtures/`) is never entered.
Symbolic links are not followed. Suites run in byte-wise (UTF-8) path
order; their order carries no meaning.

A suite passes when it exits 0 and its last "<suite>: P/T passed" line
(on its standard output) has P = T and no FAILED. Each suite gets one
line; one without a result line has its output shown. Final line:
  run: S suites, P/T passed            (", F FAILED (files…)" otherwise)
Exit 0 only when every suite passes; a missing tests directory prints
"run: no tests directory <dir>" and exits 1.
*/

import { spawnSync } from "node:child_process";
import { readdirSync, statSync, realpathSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

export const DEFAULT_DIR = "js/sisoTests";
export const SUFFIX = ".test.mjs";

// A path component that names a fixtures directory.
export function isFixtureDir(name) {
  return name === "fixtures" || /[-_]fixtures$/.test(name);
}

// Whether a path relative to the tests directory is under a fixtures
// directory (its last component, the file, is not judged).
export function underFixtures(rel) {
  const parts = rel.split("/").filter((p) => p !== "");
  for (let i = 0; i < parts.length - 1; i += 1) {
    if (isFixtureDir(parts[i])) return true;
  }
  return false;
}

// Byte-wise order on the UTF-8 of two paths.
export function byteCompare(a, b) {
  return Buffer.compare(Buffer.from(a, "utf8"), Buffer.from(b, "utf8"));
}

// Every suite file under dir, relative to it, sorted byte-wise. Fixtures
// directories are never entered.
export function suiteFiles(dir) {
  const files = [];
  const walk = (abs, rel) => {
    let entries;
    try {
      entries = readdirSync(abs, { withFileTypes: true });
    } catch {
      return;
    }
    for (const e of entries) {
      const r = rel === "" ? e.name : `${rel}/${e.name}`;
      if (e.isDirectory()) {
        if (!isFixtureDir(e.name)) walk(join(abs, e.name), r);
      } else if (e.isFile() && e.name.endsWith(SUFFIX)) {
        files.push(r);
      }
    }
  };
  walk(dir, "");
  return files.sort(byteCompare);
}

// The last result line of a suite's output, and its counts:
// → [line, passed, total], or [null, 0, 0] when there is none.
export function result(out) {
  let last = null;
  for (const line of String(out).split("\n")) {
    if (/^.*?: \d+\/\d+ passed/.test(line)) last = line;
  }
  if (last === null) return [null, 0, 0];
  const m = /: (\d+)\/(\d+) passed/.exec(last);
  return [last, Number(m[1]), Number(m[2])];
}

/*
run(dir, emit) → { suites, passed, total, failed }
Runs every suite under dir and prints the report through emit (one line
per call; console.log by default). `failed` lists the failing files.
*/
export function run(dir, emit = (line) => console.log(line)) {
  const base = `${String(dir).replace(/\/+$/, "")}/`;
  const files = suiteFiles(base);
  let passed = 0;
  let total = 0;
  const failed = [];
  for (const file of files) {
    const r = spawnSync(process.execPath, [base + file], { encoding: "utf8", maxBuffer: 256 * 1024 * 1024 });
    const stdout = r.stdout || "";
    const stderr = r.stderr || "";
    const [last, p, t] = result(stdout);
    const good = r.status === 0 && last !== null && p === t && !last.includes("FAILED");
    passed += p;
    total += t;
    emit(`  ${(good ? "ok" : "FAIL").padEnd(4)} ${file.padEnd(45)} ${last === null ? "(no result line)" : last}`);
    if (!good) {
      failed.push(file);
      if (last === null) {
        const shown = `${stdout}${stderr}${r.error ? String(r.error) : ""}`.replace(/\n+$/, "");
        if (shown !== "") emit(shown);
      } else if (r.status !== 0 && p === t && !last.includes("FAILED")) {
        emit(`       exited with ${r.status === null ? `signal ${r.signal}` : `code ${r.status}`}`);
      }
    }
  }
  emit("");
  if (failed.length === 0) {
    emit(`run: ${files.length} suites, ${passed}/${total} passed`);
  } else {
    emit(`run: ${files.length} suites, ${passed}/${total} passed, ${failed.length} FAILED (${failed.join(", ")})`);
  }
  return { suites: files.length, passed, total, failed };
}

// ── command ───────────────────────────────────

function isMain() {
  if (!process.argv[1]) return false;
  try {
    return realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
}

if (isMain()) {
  const dir = process.argv[2] === undefined ? DEFAULT_DIR : process.argv[2];
  let isDir = false;
  try {
    isDir = statSync(dir).isDirectory();
  } catch {
    isDir = false;
  }
  if (!isDir) {
    console.log(`run: no tests directory ${dir}`);
    process.exit(1);
  }
  const { failed } = run(dir);
  process.exitCode = failed.length === 0 ? 0 : 1;
}
