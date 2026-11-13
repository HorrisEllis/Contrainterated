// layers — the JavaScript instance's layering inside its one boundary
// (ledger M6, doc/SPEC.md). Port of the LAYER, EXTERNAL_REQUIRE and
// DYNAMIC_REQUIRE rules of lua/code/kit/lint.lua, with the layer map of
// lua/sisoTests/lint_config.lua as M6 states it for JavaScript.
//   node js/sisoTests/layers.test.mjs          (from the project root)
//
// Every .mjs file under js/code/ is read, every static import, every
// export-from and every dynamic import() is extracted, relative
// specifiers are resolved to files, and each is judged against the map:
//
//   layer      lives at                admits
//   core       js/code/core/           core                (no node:)
//   observer   js/code/observer/       observer            (no node:)
//   contracts  js/code/contracts/      contracts, node:
//   kit        js/code/kit/            kit, core, contracts, harness, node:
//   fixtures   js/code/fixtures/       fixtures, core, observer, contracts,
//                                      kit, harness, node:
//   harness    js/code/harness.mjs     node:
//
// Findings, each reported as "<rule> <file> → <specifier>":
//   LAYER          an import the importing file's layer does not admit (a
//                  project file of another layer or of no layer, or node:
//                  in core or observer)
//   EXTERNAL       a specifier that is neither relative nor node: (a bare
//                  package, an absolute path or a URL); EXTERNAL_REQUIRE
//   DYNAMIC        an import() whose first argument is not a lone string
//                  literal ('…' or "…"; a template literal counts as
//                  dynamic); DYNAMIC_REQUIRE. The specifier shown is the
//                  argument's source text.
//   UNRESOLVED     a relative specifier that names no file, or a node:
//                  specifier that names no builtin
//   OUTSIDE        a relative specifier that resolves outside js/code/
//   UNKNOWN_LAYER  a file under js/code/ in no layer (specifier "-")
//   AMBIGUOUS_LAYER a file in more than one layer (specifier: the layers)
//   SYNTAX         the source cannot be read as tokens (specifier: why)
//
// Ported as JavaScript's counterpart (SUBSTRATE: there is no bytecode
// listing to read, so imports are read from the source text by a small
// lexer that knows comments, strings, template literals and regular
// expression literals; only import syntax counts):
//   - LAYER: "x.*" admits a whole layer; the Lua kit's narrower
//     core.event / core.gate entry is M6's "kit: core" here.
//   - EXTERNAL_REQUIRE → EXTERNAL, plus node: as the host (M6: core and
//     observer import nothing from node:).
//   - DYNAMIC_REQUIRE → DYNAMIC; "require used as a value" has no
//     counterpart (import() is syntax, not a value).
//   - UNRESOLVED and OUTSIDE are new: a Lua module name always maps into
//     source, a relative path need not.
// Advisory, with known blind spots (as the Lua lint's): a regular
// expression literal right after `)` or `}` is read as division; code
// built from strings (eval, Function) is not seen; createRequire (from
// node:module) loads without import syntax; a method named `import` is
// judged as a dynamic import. A clean result is not proof.
import * as H from "../code/harness.mjs";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join, relative, sep, posix } from "node:path";
import { fileURLToPath } from "node:url";
import { isBuiltin } from "node:module";

const { test, assert, assertEqual } = H;

const HERE = dirname(fileURLToPath(import.meta.url));
const CODE = join(HERE, "..", "code");

// --------------------------------------------------------------------------
// the map (M6)

const LAYERS = [
  { name: "core", path: "core/", admits: ["core"], host: false },
  { name: "observer", path: "observer/", admits: ["observer"], host: false },
  { name: "contracts", path: "contracts/", admits: ["contracts"], host: true },
  { name: "kit", path: "kit/", admits: ["kit", "core", "contracts", "harness"], host: true },
  {
    name: "fixtures",
    path: "fixtures/",
    admits: ["fixtures", "core", "observer", "contracts", "kit", "harness"],
    host: true,
  },
  { name: "harness", path: "harness.mjs", admits: [], host: true },
];

// the layers a path relative to js/code/ belongs to
function layersOf(rel) {
  return LAYERS.filter((l) => (l.path.endsWith("/") ? rel.startsWith(l.path) : rel === l.path));
}

// --------------------------------------------------------------------------
// the lexer

const PUNCT = [
  ">>>=", "...", "===", "!==", "**=", "<<=", ">>=", ">>>", "&&=", "||=", "??=",
  "=>", "==", "!=", "<=", ">=", "&&", "||", "??", "?.", "++", "--", "+=", "-=",
  "*=", "%=", "&=", "|=", "^=", "**", "<<", ">>",
];
// identifiers after which a `/` starts a regular expression
const BEFORE_EXPR = new Set([
  "return", "typeof", "instanceof", "in", "of", "new", "delete", "void", "throw",
  "case", "do", "else", "yield", "await", "extends",
]);
const ID_START = /[\p{ID_Start}$_]/u;
const ID_PART = /[\p{ID_Continue}$\u200c\u200d]/u;

class LexError extends Error {}

// The census reads import syntax by regex over raw text, so the keywords are built from parts and sample sources spell them %I / %E.
const IMPORT = "imp" + "ort";
const EXPORT = "exp" + "ort";
const js = (src) => src.replaceAll("%I", IMPORT).replaceAll("%E", EXPORT);

function unescape(raw) {
  return raw.replace(/\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|\r\n|[\s\S])/g, (_, e) => {
    if (e[0] === "u" && e[1] === "{") return String.fromCodePoint(parseInt(e.slice(2, -1), 16));
    if (e[0] === "u" && e.length === 5) return String.fromCharCode(parseInt(e.slice(1), 16));
    if (e[0] === "x" && e.length === 3) return String.fromCharCode(parseInt(e.slice(1), 16));
    const simple = { n: "\n", t: "\t", r: "\r", b: "\b", f: "\f", v: "\v", 0: "\0" };
    if (e in simple) return simple[e];
    if (e === "\n" || e === "\r" || e === "\r\n" || e === "\u2028" || e === "\u2029") return "";
    return e;
  });
}

// source → tokens { t, v, start, end }; t is "id", "num", "str", "tpl"
// (a template that ends here), "tplopen" (a template part ending in ${),
// "regex", "priv" or "punct". Comments and whitespace produce nothing.
export function lex(src) {
  const toks = [];
  const stack = []; // "{" or "tpl" for each open brace
  let i = 0;
  const n = src.length;
  const prev = () => toks[toks.length - 1];
  const regexAllowed = () => {
    const p = prev();
    if (!p) return true;
    if (p.t === "id") return BEFORE_EXPR.has(p.v);
    if (p.t === "tplopen") return true;
    if (p.t === "punct") return !(p.v === ")" || p.v === "]" || p.v === "}" || p.v === "++" || p.v === "--");
    return false; // num, str, tpl, regex, priv end an expression
  };
  // scan template characters from i to its end or its next ${
  const template = (start) => {
    while (i < n) {
      const c = src[i];
      if (c === "\\") i += 2;
      else if (c === "`") {
        i += 1;
        toks.push({ t: "tpl", v: null, start, end: i });
        return;
      } else if (c === "$" && src[i + 1] === "{") {
        i += 2;
        stack.push("tpl");
        toks.push({ t: "tplopen", v: null, start, end: i });
        return;
      } else i += 1;
    }
    throw new LexError("unterminated template literal");
  };

  if (src.startsWith("#!")) {
    while (i < n && src[i] !== "\n" && src[i] !== "\r") i += 1;
  }
  while (i < n) {
    const c = src[i];
    const start = i;
    if (/\s/.test(c) || c === "\ufeff") {
      i += 1;
    } else if (c === "/" && src[i + 1] === "/") {
      while (i < n && !/[\n\r\u2028\u2029]/.test(src[i])) i += 1;
    } else if (c === "/" && src[i + 1] === "*") {
      const e = src.indexOf("*/", i + 2);
      if (e < 0) throw new LexError("unterminated comment");
      i = e + 2;
    } else if (c === "'" || c === '"') {
      i += 1;
      while (true) {
        if (i >= n || src[i] === "\n" || src[i] === "\r") throw new LexError("unterminated string");
        if (src[i] === "\\") i += 2;
        else if (src[i] === c) break;
        else i += 1;
      }
      i += 1;
      toks.push({ t: "str", v: unescape(src.slice(start + 1, i - 1)), start, end: i });
    } else if (c === "`") {
      i += 1;
      template(start);
    } else if (c === "/" && regexAllowed()) {
      i += 1;
      let inClass = false;
      while (true) {
        if (i >= n || /[\n\r\u2028\u2029]/.test(src[i])) throw new LexError("unterminated regular expression");
        const d = src[i];
        if (d === "\\") i += 2;
        else if (d === "[") (inClass = true), (i += 1);
        else if (d === "]") (inClass = false), (i += 1);
        else if (d === "/" && !inClass) break;
        else i += 1;
      }
      i += 1;
      while (i < n && ID_PART.test(src[i])) i += 1;
      toks.push({ t: "regex", v: null, start, end: i });
    } else if (/[0-9]/.test(c) || (c === "." && /[0-9]/.test(src[i + 1] || ""))) {
      while (i < n && /[0-9A-Za-z_.]/.test(src[i])) {
        if ((src[i] === "e" || src[i] === "E") && (src[i + 1] === "+" || src[i + 1] === "-")) i += 1;
        i += 1;
      }
      toks.push({ t: "num", v: src.slice(start, i), start, end: i });
    } else if (c === "#" && ID_START.test(src[i + 1] || "")) {
      i += 1;
      while (i < n && ID_PART.test(src[i])) i += 1;
      toks.push({ t: "priv", v: src.slice(start, i), start, end: i });
    } else if (ID_START.test(c) || c === "\\") {
      i += 1;
      while (i < n && (ID_PART.test(src[i]) || src[i] === "\\")) i += 1;
      toks.push({ t: "id", v: src.slice(start, i), start, end: i });
    } else if (c === "}" && stack.length > 0 && stack[stack.length - 1] === "tpl") {
      stack.pop();
      i += 1;
      template(start);
    } else {
      let p = PUNCT.find((q) => src.startsWith(q, i));
      if (p === "?." && /[0-9]/.test(src[i + 2] || "")) p = undefined;
      if (!p && c === "/" && src[i + 1] === "=") p = "/=";
      if (!p) p = c;
      if (p === "{") stack.push("{");
      else if (p === "}" && stack.length > 0) stack.pop();
      i += p.length;
      toks.push({ t: "punct", v: p, start, end: i });
    }
  }
  if (stack.includes("tpl")) throw new LexError("unterminated template literal");
  return toks;
}

// --------------------------------------------------------------------------
// extraction

const isP = (tok, v) => tok !== undefined && tok.t === "punct" && tok.v === v;
const isId = (tok, v) => tok !== undefined && tok.t === "id" && tok.v === v;

// index of the token closing the bracket opened at toks[i]
function closing(toks, i) {
  const open = toks[i].v;
  const close = { "(": ")", "{": "}", "[": "]" }[open];
  let depth = 0;
  for (let j = i; j < toks.length; j += 1) {
    if (isP(toks[j], open)) depth += 1;
    else if (isP(toks[j], close)) {
      depth -= 1;
      if (depth === 0) return j;
    }
  }
  throw new LexError(`unclosed ${open}`);
}

// from toks[i] on, the string after the clause's `from` (an import or
// export-from clause); braces are skipped whole, `as "name"` too
function fromClause(toks, i) {
  while (i < toks.length) {
    const tok = toks[i];
    if (isP(tok, "{")) i = closing(toks, i) + 1;
    else if (isId(tok, "from") && toks[i + 1] && toks[i + 1].t === "str") return toks[i + 1].v;
    else if (isId(tok, "as")) i += 2;
    else if (isP(tok, ";") || tok.t === "str") break;
    else i += 1;
  }
  throw new LexError("an import or export clause without its from \"…\"");
}

// source → { imports: [specifier], dynamics: [argument text] }
// (throws LexError when the source cannot be read)
export function extract(src) {
  const toks = lex(src);
  const imports = [];
  const dynamics = [];
  for (let i = 0; i < toks.length; i += 1) {
    const tok = toks[i];
    const before = toks[i - 1];
    if (tok.t !== "id" || (before && before.t === "punct" && (before.v === "." || before.v === "?."))) continue;
    const next = toks[i + 1];
    if (tok.v === IMPORT) {
      if (next === undefined || isP(next, ".") || isP(next, ":")) continue; // import.meta, a key
      if (isP(next, "(")) {
        const close = closing(toks, i + 1);
        const arg = toks[i + 2];
        const after = toks[i + 3];
        if (arg && arg.t === "str" && (i + 3 === close || isP(after, ","))) imports.push(arg.v);
        else {
          const comma = toks.slice(i + 2, close).findIndex((t) => isP(t, ","));
          const end = comma < 0 ? toks[close].start : toks[i + 2 + comma].start;
          dynamics.push(src.slice(toks[i + 1].end, end).trim() || "(nothing)");
        }
      } else if (next.t === "str") imports.push(next.v); // the side-effect form: keyword, then the string
      else imports.push(fromClause(toks, i + 1));
    } else if (tok.v === EXPORT && (isP(next, "*") || isP(next, "{"))) {
      if (isP(next, "{")) {
        const close = closing(toks, i + 1);
        if (isId(toks[close + 1], "from") && toks[close + 2] && toks[close + 2].t === "str") {
          imports.push(toks[close + 2].v);
        }
      } else imports.push(fromClause(toks, i + 1));
    }
  }
  return { imports, dynamics };
}

// --------------------------------------------------------------------------
// the check

// tree: { files: [path relative to js/code/, "/"-separated] (every file),
//         sources: Map path → source text (the .mjs files) }
// → { findings: ["<rule> <file> → <specifier>"], edges: imports judged }
export function check(tree) {
  const exists = new Set(tree.files);
  const findings = [];
  let edges = 0;
  const shown = (rel) => `js/code/${rel}`;
  const add = (rule, file, spec) => findings.push(`${rule} ${shown(file)} → ${spec}`);
  for (const [file, src] of [...tree.sources].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))) {
    const mine = layersOf(file);
    if (mine.length === 0) add("UNKNOWN_LAYER", file, "-");
    if (mine.length > 1) add("AMBIGUOUS_LAYER", file, mine.map((l) => l.name).join(", "));
    const layer = mine.length === 1 ? mine[0] : null;
    let found;
    try {
      found = extract(src);
    } catch (e) {
      if (!(e instanceof LexError)) throw e;
      add("SYNTAX", file, e.message);
      continue;
    }
    for (const d of found.dynamics) add("DYNAMIC", file, d);
    for (const spec of found.imports) {
      edges += 1;
      if (spec.startsWith("node:")) {
        if (!isBuiltin(spec)) add("UNRESOLVED", file, spec);
        else if (layer && !layer.host) add("LAYER", file, spec);
        continue;
      }
      if (!(spec.startsWith("./") || spec.startsWith("../"))) {
        add("EXTERNAL", file, spec);
        continue;
      }
      const target = posix.normalize(posix.join(posix.dirname(file), spec.replace(/[?#].*$/s, "")));
      if (target === ".." || target.startsWith("../")) {
        add("OUTSIDE", file, spec);
        continue;
      }
      if (!exists.has(target)) {
        add("UNRESOLVED", file, spec);
        continue;
      }
      const theirs = layersOf(target);
      if (layer && !(theirs.length === 1 && layer.admits.includes(theirs[0].name))) add("LAYER", file, spec);
    }
  }
  return { findings, edges };
}

// every file under dir, relative to it, "/"-separated
function walk(dir, base = dir, out = []) {
  for (const d of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, d.name);
    if (d.isDirectory()) walk(p, base, out);
    else out.push(relative(base, p).split(sep).join("/"));
  }
  return out;
}

function realTree() {
  const files = walk(CODE).sort();
  const sources = new Map();
  for (const f of files) if (f.endsWith(".mjs")) sources.set(f, readFileSync(join(CODE, ...f.split("/")), "utf8"));
  return { files, sources };
}

// an inline tree: { path: source }; every key exists, .mjs keys are read
function tree(spec) {
  const files = Object.keys(spec);
  return { files, sources: new Map(files.filter((f) => f.endsWith(".mjs")).map((f) => [f, spec[f]])) };
}

// the base every inline case adds to: one file per layer, importing nothing
const BASE = {
  "core/a.mjs": "%E const a = 1;\n",
  "observer/o.mjs": "%E const o = 1;\n",
  "contracts/c.mjs": "%E const c = 1;\n",
  "kit/k.mjs": "%E const k = 1;\n",
  "fixtures/f.mjs": "%E const f = 1;\n",
  "harness.mjs": "%E const h = 1;\n",
};
const run = (extra) =>
  check(tree(Object.fromEntries(Object.entries({ ...BASE, ...extra }).map(([f, src]) => [f, js(src)])))).findings;
const same = (got, want, label) => assertEqual(got.join(" | "), want.join(" | "), label);

// --------------------------------------------------------------------------
// the real tree

test("the real tree: every .mjs file under js/code/ is in one layer and imports only what its layer admits", () => {
  const t = realTree();
  const { findings, edges } = check(t);
  console.log(`  layers: ${t.sources.size} files, ${edges} imports, ${findings.length} findings`);
  for (const f of findings) console.log(`    ${f}`);
  assertEqual(findings.length, 0, `findings:\n    ${findings.join("\n    ")}\n  count`);
});

test("the real tree is not empty: every layer has a file and imports were read", () => {
  const t = realTree();
  for (const l of LAYERS) assert([...t.sources.keys()].some((f) => layersOf(f)[0] === l), `no file in layer ${l.name}`);
  assert(check(t).edges > 0, "no imports were read");
});

// --------------------------------------------------------------------------
// each rule fires

test("LAYER: a core file importing the kit", () => {
  same(run({ "core/b.mjs": '%I { k } from "../kit/k.mjs";\n' }), ["LAYER js/code/core/b.mjs → ../kit/k.mjs"]);
});

test("LAYER: a core file importing node:fs (static, dynamic and export-from)", () => {
  same(run({ "core/b.mjs": '%I { readFileSync } from "node:fs";\n' }), ["LAYER js/code/core/b.mjs → node:fs"]);
  same(run({ "core/b.mjs": '%E const f = () => %I("node:fs");\n' }), ["LAYER js/code/core/b.mjs → node:fs"]);
  same(run({ "core/b.mjs": '%E * from "node:fs";\n' }), ["LAYER js/code/core/b.mjs → node:fs"]);
});

test("DYNAMIC: a dynamic import of a variable, of an expression and of a template", () => {
  same(run({ "kit/b.mjs": "%E const load = (name) => %I(name);\n" }), ["DYNAMIC js/code/kit/b.mjs → name"]);
  same(run({ "kit/b.mjs": '%E const load = (n) => %I("./" + n);\n' }), [
    'DYNAMIC js/code/kit/b.mjs → "./" + n',
  ]);
  same(run({ "kit/b.mjs": "%E const load = () => %I(`./k.mjs`);\n" }), ["DYNAMIC js/code/kit/b.mjs → `./k.mjs`"]);
  same(run({ "kit/b.mjs": "%E const load = (n) => %I(n, { with: {} });\n" }), ["DYNAMIC js/code/kit/b.mjs → n"]);
});

test("an import inside a comment, a string, a template or a regular expression does not count", () => {
  const src = [
    '// %I { k } from "../kit/k.mjs";',
    '/* %I fs from "node:fs";',
    '   %E * from "left-pad"; %I(name) */',
    "%E const s = '%I \"node:fs\"';",
    '%E const d = "%E { x } from \\"left-pad\\"";',
    "%E const t = `%I(${'\"node:fs\"'.length}) from \"left-pad\"`;",
    '%E const r = /%I("node:fs")|"/g;',
    "%E const q = 4 / 2 / 1; // %I(name)",
    "%E const m = %I.meta.url;",
    "%E const o = { %I: 1 }.%I;",
    '%I { a } from "./a.mjs"; // the one real %I',
    "",
  ].join("\n");
  assertEqual(JSON.stringify(extract(js(src))), JSON.stringify({ imports: ["./a.mjs"], dynamics: [] }), "extracted");
  same(run({ "core/b.mjs": src }), [], "findings");
});

test("an import inside a template's ${} does count", () => {
  same(run({ "core/b.mjs": "%E const t = async () => `${await %I(\"node:fs\")}`;\n" }), [
    "LAYER js/code/core/b.mjs → node:fs",
  ]);
});

test("EXTERNAL: a bare package (and a bare builtin, an absolute path, a URL)", () => {
  same(run({ "kit/b.mjs": '%I pad from "left-pad";\n' }), ["EXTERNAL js/code/kit/b.mjs → left-pad"]);
  same(run({ "core/b.mjs": '%I "@scope/pkg/sub.mjs";\n' }), ["EXTERNAL js/code/core/b.mjs → @scope/pkg/sub.mjs"]);
  same(run({ "kit/b.mjs": '%I fs from "fs";\n' }), ["EXTERNAL js/code/kit/b.mjs → fs"]);
  same(run({ "kit/b.mjs": '%I a from "/abs/a.mjs";\n' }), ["EXTERNAL js/code/kit/b.mjs → /abs/a.mjs"]);
  same(run({ "kit/b.mjs": '%I a from "file:///a.mjs";\n' }), ["EXTERNAL js/code/kit/b.mjs → file:///a.mjs"]);
});

test("UNRESOLVED and OUTSIDE: an import naming no file, or a file outside js/code/", () => {
  same(run({ "kit/b.mjs": '%I x from "./missing.mjs";\n' }), ["UNRESOLVED js/code/kit/b.mjs → ./missing.mjs"]);
  same(run({ "kit/b.mjs": '%I x from "../kit";\n' }), ["UNRESOLVED js/code/kit/b.mjs → ../kit"]);
  same(run({ "kit/b.mjs": '%I x from "node:nosuch";\n' }), ["UNRESOLVED js/code/kit/b.mjs → node:nosuch"]);
  same(run({ "kit/b.mjs": '%I * as H from "../../sisoTests/x.mjs";\n' }), [
    "OUTSIDE js/code/kit/b.mjs → ../../sisoTests/x.mjs",
  ]);
  same(run({ "harness.mjs": '%E { x } from "../lua/x.mjs";\n' }), ["OUTSIDE js/code/harness.mjs → ../lua/x.mjs"]);
});

test("UNKNOWN_LAYER and SYNTAX: a file in no layer, a file that cannot be read", () => {
  same(run({ "misc/m.mjs": "%E {};\n", "extra.mjs": "%E {};\n" }), [
    "UNKNOWN_LAYER js/code/extra.mjs → -",
    "UNKNOWN_LAYER js/code/misc/m.mjs → -",
  ]);
  same(run({ "core/b.mjs": '%I x from "./a.mjs\n' }), ["SYNTAX js/code/core/b.mjs → unterminated string"]);
  same(run({ "core/b.mjs": "/* never closed\n" }), ["SYNTAX js/code/core/b.mjs → unterminated comment"]);
});

test("each layer admits exactly its map entry (M6)", () => {
  const imp = { core: "../core/a.mjs", observer: "../observer/o.mjs", contracts: "../contracts/c.mjs",
    kit: "../kit/k.mjs", fixtures: "../fixtures/f.mjs", harness: "../harness.mjs", node: "node:path" };
  const from = { core: "core/x.mjs", observer: "observer/x.mjs", contracts: "contracts/x.mjs",
    kit: "kit/x.mjs", fixtures: "fixtures/x.mjs", harness: "harness.mjs" };
  const admitted = {
    core: ["core"],
    observer: ["observer"],
    contracts: ["contracts", "node"],
    kit: ["kit", "core", "contracts", "harness", "node"],
    fixtures: ["fixtures", "core", "observer", "contracts", "kit", "harness", "node"],
    harness: ["node"],
  };
  for (const [layer, file] of Object.entries(from)) {
    for (const [target, spec0] of Object.entries(imp)) {
      // the harness sits in js/code/ itself, one level up from the layers
      const spec = file === "harness.mjs" && spec0.startsWith("../") ? `./${spec0.slice(3)}` : spec0;
      const got = run({ [file]: `%I "${spec}";\n` });
      const want = admitted[layer].includes(target) ? [] : [`LAYER js/code/${file} → ${spec}`];
      same(got, want, `${layer} → ${target}`);
    }
  }
});

test("every import form is read: default, named, namespace, side effect, export-from, attributes", () => {
  const src = [
    '%I a from "./1.mjs";',
    '%I { b as c, "d e" as f } from "./2.mjs";',
    '%I * as g from "./3.mjs";',
    '%I h, { i } from "./4.mjs";',
    '%I from from "./5.mjs";',
    '%I "./6.mjs";',
    '%E * from "./7.mjs";',
    '%E * as "j k" from "./8.mjs";',
    '%E { l, m as n } from "./9.mjs";',
    '%I data from "./10.json" with { type: "json" };',
    "%E { a, c };",
    "%E const p = await %I('./11.mjs', { with: { type: 'json' } });",
    "",
  ].join("\n");
  const want = ["./1.mjs", "./2.mjs", "./3.mjs", "./4.mjs", "./5.mjs", "./6.mjs", "./7.mjs", "./8.mjs", "./9.mjs",
    "./10.json", "./11.mjs"];
  assertEqual(JSON.stringify(extract(js(src)).imports), JSON.stringify(want), "imports");
});

H.finish("layers");
