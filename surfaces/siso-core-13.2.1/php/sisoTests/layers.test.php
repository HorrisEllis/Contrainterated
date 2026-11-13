<?php
/*
layers.test.php — the PHP instance's layering (ledger M6), held as a
suite. Port of the layer half of lua/code/kit/lint.lua (LAYER,
EXTERNAL_REQUIRE, DYNAMIC_REQUIRE, UNKNOWN_LAYER, SYNTAX) with the layer
map of lua/sisoTests/lint_config.lua, plus the PHP counterpart of its
GLOBAL_WRITE / RANDOM rules for the pure layer.
  php php/sisoTests/layers.test.php   (from the project root)

Every .php file under php/code/ (found from __DIR__) is tokenised with
token_get_all(TOKEN_PARSE), so a comment, a docblock, a string or a
heredoc that only looks like an inclusion never counts, and a method
named `require` (`$o->require(...)`) is an identifier, not an inclusion.

THE MAP (a file belongs to exactly one layer)

  core       php/code/core/        may include core
  observer   php/code/observer/    may include observer
  contracts  php/code/contracts/   may include contracts
  kit        php/code/kit/         may include kit, core, contracts, harness
  fixtures   php/code/fixtures/    may include fixtures, core, observer,
                                   contracts, kit, harness
  harness    php/code/harness.php  may include nothing

RULES (finding: "<rule> <file> → <detail>", file from the project root)

  Every file:
    UNKNOWN_LAYER     under php/code/ but in no layer (or in more than one)
    SYNTAX            token_get_all(TOKEN_PARSE) rejects it
    DYNAMIC_REQUIRE   a require / require_once / include / include_once
                      whose argument is not exactly `__DIR__ . '<literal>'`
                      or a plain string literal (parentheses, a variable,
                      dirname(__FILE__), a second concatenation: all
                      dynamic)
    EXTERNAL_REQUIRE  an inclusion that resolves to no .php file under
                      php/code/, or to a path outside php/code/
    LAYER             an inclusion of a file whose layer the including
                      file's layer does not admit
  core/ and observer/ (state):
    STATIC_STATE      `static` followed (after modifiers and a type) by a
                      variable: a static property, or a method's static
                      variable. `static function`, `static fn`,
                      `static::`, `new static` and a `static` return type
                      are not state.
    GLOBAL_STATEMENT  a `global` statement
  core/ only (host; observer may call time()):
    HOST_CALL         echo, print or `<?=`; a backtick shell command; or a
                      call of fopen, file_get_contents, file_put_contents,
                      printf, error_log, getenv, putenv, time, microtime,
                      rand, mt_rand, random_int, exec, shell_exec, system,
                      passthru, proc_open, header, session_start (any
                      case, `\name(` included; a method call, a static
                      call, a declaration and a namespaced name are not
                      the host's function)

A plain relative literal is resolved against the including file's
directory (PHP's last fallback after include_path); the real tree uses
only the `__DIR__ . '…'` form. Advisory, with known blind spots (as the
Lua lint's): a host function reached as a string callable
(`call_user_func('time')`, `$f = 'rand'; $f()`), Reflection, and a class
named across layers without an inclusion (`instanceof \Siso\Kit\…`
needs no file loaded). A clean run is not proof; conformance cites the
tests.

The cases after the real tree run the same checker on small inline
sources (a virtual php/code/ root) to show each rule fires and each
look-alike does not.
*/
declare(strict_types=1);

namespace Siso\Tests\Layers;

require_once __DIR__ . '/../code/harness.php';

use Siso\Harness\H;

const LAYERS = [
    'core'      => ['path' => 'core/',       'admits' => ['core']],
    'observer'  => ['path' => 'observer/',   'admits' => ['observer']],
    'contracts' => ['path' => 'contracts/',  'admits' => ['contracts']],
    'kit'       => ['path' => 'kit/',        'admits' => ['kit', 'core', 'contracts', 'harness']],
    'fixtures'  => ['path' => 'fixtures/',   'admits' => ['fixtures', 'core', 'observer', 'contracts', 'kit', 'harness']],
    'harness'   => ['path' => 'harness.php', 'admits' => []],
];

const STATE_LAYERS = ['core', 'observer'];
const HOST_LAYERS = ['core'];

const HOST_FUNCTIONS = [
    'fopen', 'file_get_contents', 'file_put_contents', 'printf', 'error_log',
    'getenv', 'putenv', 'time', 'microtime', 'rand', 'mt_rand', 'random_int',
    'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'header',
    'session_start',
];

const INCLUSIONS = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];

/** The layers whose path matches $rel (relative to php/code/). */
function layersOf(string $rel): array
{
    $hits = [];
    foreach (LAYERS as $name => $layer) {
        $p = $layer['path'];
        if (str_ends_with($p, '/') ? str_starts_with($rel, $p) : $rel === $p) {
            $hits[] = $name;
        }
    }
    return $hits;
}

/** Lexical normalisation of an absolute path ('.' and '..' folded). */
function normalise(string $path): string
{
    $out = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $part;
    }
    return '/' . implode('/', $out);
}

/** The value of a T_CONSTANT_ENCAPSED_STRING token. */
function literal(string $text): string
{
    $text = ltrim($text, 'bB');
    $inner = substr($text, 1, -1);
    if ($text[0] === "'") {
        return (string)preg_replace('/\\\\([\\\\\'])/', '$1', $inner);
    }
    return stripcslashes($inner);
}

/**
 * Significant tokens of $src as [id, text, line]; a one-character token
 * has the character as its id. Whitespace, comments and docblocks dropped.
 */
function tokens(string $src): array
{
    $out = [];
    $line = 1;
    foreach (token_get_all($src, TOKEN_PARSE) as $t) {
        if (is_array($t)) {
            [$id, $text, $line] = $t;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $out[] = [$id, $text, $line];
        } else {
            $out[] = [$t, $t, $line];
        }
    }
    return $out;
}

/**
 * The inclusions in $toks: [line, form, path] where form is 'dir' or
 * 'plain' and path the literal, or form 'dynamic' and path the
 * argument's source text.
 */
function inclusions(array $toks): array
{
    $found = [];
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        if (!in_array($toks[$i][0], INCLUSIONS, true)) {
            continue;
        }
        $arg = [];
        $depth = 0;
        for ($j = $i + 1; $j < $n; $j++) {
            $id = $toks[$j][0];
            if ($depth === 0 && ($id === ';' || $id === ')' || $id === ']' || $id === ',' || $id === T_CLOSE_TAG)) {
                break;
            }
            if ($id === '(' || $id === '[' || $id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === ')' || $id === ']' || $id === '}') {
                $depth--;
            }
            $arg[] = $toks[$j];
        }
        $ids = array_map(static fn (array $t): mixed => $t[0], $arg);
        if ($ids === [T_DIR, '.', T_CONSTANT_ENCAPSED_STRING]) {
            $found[] = [$toks[$i][2], 'dir', literal($arg[2][1])];
        } elseif ($ids === [T_CONSTANT_ENCAPSED_STRING]) {
            $found[] = [$toks[$i][2], 'plain', literal($arg[0][1])];
        } else {
            $text = implode(' ', array_map(static fn (array $t): string => $t[1], $arg));
            $found[] = [$toks[$i][2], 'dynamic', $text];
        }
    }
    return $found;
}

/** The previous / next significant token's id, or null. */
function idAt(array $toks, int $k): mixed
{
    return $k >= 0 && $k < count($toks) ? $toks[$k][0] : null;
}

/** STATIC_STATE and GLOBAL_STATEMENT findings: [rule, line, detail]. */
function stateFindings(array $toks): array
{
    $found = [];
    $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE];
    $skip = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE,
        T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_VAR, '?', '|', '&',
        T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG];
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        $id = $toks[$i][0];
        if ($id === T_GLOBAL) {
            $found[] = ['GLOBAL_STATEMENT', $toks[$i][2], 'global statement'];
            continue;
        }
        if ($id !== T_STATIC || in_array(idAt($toks, $i - 1), [T_NEW, T_INSTANCEOF], true)) {
            continue;
        }
        // modifiers and a type; a `?` counts as a nullable type only before
        // a name (`instanceof static ? $a : $b` is a ternary)
        $j = $i + 1;
        while ($j < $n && in_array($toks[$j][0], $skip, true)
            && ($toks[$j][0] !== '?' || in_array(idAt($toks, $j + 1), $names, true))) {
            $j++;
        }
        if (idAt($toks, $j) === T_VARIABLE) {
            $found[] = ['STATIC_STATE', $toks[$i][2], 'static ' . $toks[$j][1]];
        }
    }
    return $found;
}

/** HOST_CALL findings: [rule, line, detail]. */
function hostFindings(array $toks): array
{
    $found = [];
    $notCall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
        T_FUNCTION, T_NEW, T_CONST];
    $n = count($toks);
    for ($i = 0; $i < $n; $i++) {
        [$id, $text, $line] = $toks[$i];
        if ($id === T_ECHO || $id === T_PRINT || $id === T_OPEN_TAG_WITH_ECHO) {
            $found[] = ['HOST_CALL', $line, strtolower(trim($text))];
            continue;
        }
        if ($id === '`') {
            $found[] = ['HOST_CALL', $line, 'backtick shell command'];
            for ($i++; $i < $n && $toks[$i][0] !== '`'; $i++) {
            }
            continue;
        }
        if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }
        $name = strtolower(ltrim($text, '\\'));
        if (!in_array($name, HOST_FUNCTIONS, true) || idAt($toks, $i + 1) !== '('
            || in_array(idAt($toks, $i - 1), $notCall, true)) {
            continue;
        }
        $found[] = ['HOST_CALL', $line, $name . '()'];
    }
    return $found;
}

/**
 * The checker. $sources maps a path relative to php/code/ to its source;
 * $root is php/code/'s absolute path (real or virtual). Returns
 * ['findings' => list<string>, 'inclusions' => list<[from, line, to]>].
 */
function check(array $sources, string $root): array
{
    $root = normalise($root);
    $findings = [];
    $resolved = [];
    ksort($sources, SORT_STRING);
    foreach ($sources as $rel => $src) {
        $rel = (string)$rel;
        $file = 'php/code/' . $rel;
        $add = static function (string $rule, string $detail) use (&$findings, $file): void {
            $findings[] = $rule . ' ' . $file . ' → ' . $detail;
        };
        $own = layersOf($rel);
        $layer = count($own) === 1 ? $own[0] : null;
        if ($layer === null) {
            $add('UNKNOWN_LAYER', $own === [] ? 'in no layer' : 'in layers ' . implode(', ', $own));
        }
        try {
            $toks = tokens($src);
        } catch (\ParseError $e) {
            $add('SYNTAX', 'line ' . $e->getLine() . ': ' . $e->getMessage());
            continue;
        }
        $dir = dirname($root . '/' . $rel);
        foreach (inclusions($toks) as [$line, $form, $path]) {
            if ($form === 'dynamic') {
                $add('DYNAMIC_REQUIRE', 'line ' . $line . ': ' . $path);
                continue;
            }
            $abs = $form === 'dir' ? $dir . $path : (str_starts_with($path, '/') ? $path : $dir . '/' . $path);
            $abs = normalise($abs);
            if (!str_starts_with($abs, $root . '/')) {
                $add('EXTERNAL_REQUIRE', 'line ' . $line . ': ' . $path . ' resolves outside php/code/');
                continue;
            }
            $to = substr($abs, strlen($root) + 1);
            if (!array_key_exists($to, $sources)) {
                $add('EXTERNAL_REQUIRE', 'line ' . $line . ': ' . $path . ' resolves to no file (php/code/' . $to . ')');
                continue;
            }
            $resolved[] = [$rel, $line, $to];
            $theirs = layersOf($to);
            $target = count($theirs) === 1 ? $theirs[0] : null;
            if ($layer !== null && ($target === null || !in_array($target, LAYERS[$layer]['admits'], true))) {
                $add('LAYER', 'line ' . $line . ': ' . $layer . ' includes php/code/' . $to
                    . ' (' . ($target ?? 'no layer') . ')');
            }
        }
        $more = [];
        if (in_array($layer, STATE_LAYERS, true)) {
            $more = array_merge($more, stateFindings($toks));
        }
        if (in_array($layer, HOST_LAYERS, true)) {
            $more = array_merge($more, hostFindings($toks));
        }
        foreach ($more as [$rule, $line, $detail]) {
            $add($rule, 'line ' . $line . ': ' . $detail);
        }
    }
    return ['findings' => $findings, 'inclusions' => $resolved];
}

/** Every .php file under the real php/code/, relative to it. */
function realTree(): array
{
    $root = (string)realpath(__DIR__ . '/../code');
    $sources = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            $sources[$rel] = (string)file_get_contents($f->getPathname());
        }
    }
    return [$sources, $root];
}

const VROOT = '/virtual/php/code';

/**
 * Run the checker on inline sources; return its findings. The samples spell
 * the inclusion keywords as placeholders, expanded here, because the
 * project's census reads inclusion syntax by regex over this file's raw text.
 */
function on(array $sources): array
{
    $words = ['@REQ1@' => 'requ' . 'ire_once', '@INC1@' => 'incl' . 'ude_once',
        '@REQ@' => 'requ' . 'ire', '@INC@' => 'incl' . 'ude'];
    return check(array_map(static fn (string $s): string => strtr($s, $words), $sources), VROOT)['findings'];
}

/** Assert the findings are exactly $want (order-free). */
function expect(array $got, array $want, string $label): void
{
    sort($got);
    sort($want);
    H::assertEqual($got, $want, $label);
}

// --------------------------------------------------------------- the real tree

[$realSources, $realRoot] = realTree();
$real = check($realSources, $realRoot);

H::test('the real tree: every file in exactly one layer, 0 findings', function () use ($realSources, $real): void {
    foreach (array_keys($realSources) as $rel) {
        H::assertEqual(count(layersOf((string)$rel)), 1, 'layers of php/code/' . $rel);
    }
    foreach (array_keys(LAYERS) as $name) {
        $n = count(array_filter(array_keys($realSources), static fn ($r): bool => layersOf((string)$r) === [$name]));
        H::assert($n >= 1, 'layer ' . $name . ' has no file');
    }
    H::assert($real['findings'] === [], count($real['findings']) . " findings:\n     " . implode("\n     ", $real['findings']));
});

H::test('the real tree: inclusions are extracted and resolved, comment examples are not', function () use ($real): void {
    $from = static fn (string $rel): array => array_values(array_map(
        static fn (array $r): string => $r[2],
        array_filter($real['inclusions'], static fn (array $r): bool => $r[0] === $rel)
    ));
    expect($from('fixtures/run.php'), [
        'core/event.php', 'core/gate.php', 'core/stream.php', 'contracts/fixture_format.php',
        'contracts/observer_seam.php', 'contracts/core_api.php', 'kit/data.php',
    ], 'fixtures/run.php');
    expect($from('core/stream.php'), ['core/event.php', 'core/gate.php', 'core/rejection.php', 'core/freeze.php'], 'core/stream.php');
    // the header comments of these show a require_once usage line; it is no inclusion
    H::assertEqual($from('harness.php'), [], 'harness.php');
    H::assertEqual($from('core/gate.php'), [], 'core/gate.php');
    H::assertEqual($from('observer/streamlog.php'), [], 'observer/streamlog.php');
    H::assertEqual($from('core/event.php'), ['core/freeze.php'], 'core/event.php');
});

// ---------------------------------------------------------- the rules, inline

H::test('LAYER: core including kit', function (): void {
    expect(on([
        'core/a.php' => "<?php\n@REQ1@ __DIR__ . '/../kit/data.php';\n",
        'kit/data.php' => "<?php\n",
    ]), ['LAYER php/code/core/a.php → line 2: core includes php/code/kit/data.php (kit)'], 'findings');
});

H::test('LAYER: each layer admits its map and refuses the rest', function (): void {
    $all = ['core/c.php', 'observer/o.php', 'contracts/k.php', 'kit/t.php', 'fixtures/f.php', 'harness.php'];
    $layerOf = static fn (string $rel): string => layersOf($rel)[0];
    foreach ($all as $from) {
        foreach ($all as $to) {
            if ($from === $to) {
                continue;
            }
            $sources = array_fill_keys($all, "<?php\n");
            $prefix = str_repeat('/..', substr_count($from, '/'));
            $sources[$from] = "<?php\n@INC@ __DIR__ . '" . $prefix . '/' . $to . "';\n";
            $admitted = in_array($layerOf($to), LAYERS[$layerOf($from)]['admits'], true);
            $want = $admitted ? [] : ['LAYER php/code/' . $from . ' → line 2: ' . $layerOf($from)
                . ' includes php/code/' . $to . ' (' . $layerOf($to) . ')'];
            expect(on($sources), $want, $from . ' → ' . $to);
        }
    }
});

H::test('DYNAMIC_REQUIRE: any argument but __DIR__ . literal or a literal', function (): void {
    $src = "<?php\n"
        . "@REQ@ \$path;\n"
        . "@REQ1@ __DIR__ . '/b' . \$x;\n"
        . "@INC@ dirname(__FILE__) . '/b.php';\n"
        . "@INC1@(__DIR__ . '/b.php');\n"
        . "@REQ@ \"/virtual/php/code/core/\$name.php\";\n"
        . "@REQ@ __DIR__ . '/b.php';\n"
        . "@REQ@ 'b.php';\n";
    expect(on(['core/a.php' => $src, 'core/b.php' => "<?php\n"]), [
        'DYNAMIC_REQUIRE php/code/core/a.php → line 2: $path',
        "DYNAMIC_REQUIRE php/code/core/a.php → line 3: __DIR__ . '/b' . \$x",
        "DYNAMIC_REQUIRE php/code/core/a.php → line 4: dirname ( __FILE__ ) . '/b.php'",
        "DYNAMIC_REQUIRE php/code/core/a.php → line 5: ( __DIR__ . '/b.php' )",
        'DYNAMIC_REQUIRE php/code/core/a.php → line 6: " /virtual/php/code/core/ $name .php "',
    ], 'findings');
});

H::test('an inclusion inside a comment, a string or a heredoc does not count', function (): void {
    $src = "<?php\n"
        . "// @REQ@ \$x; @REQ@ __DIR__ . '/../kit/data.php';\n"
        . "# @INC1@ \$y;\n"
        . "/* @INC@ __DIR__ . '/../kit/data.php'; */\n"
        . "/** @REQ1@ __DIR__ . '/../observer/x.php'; */\n"
        . "\$s = \"@REQ@ \\\$x;\";\n"
        . "\$t = '@INC1@ __DIR__ . \"/../kit/data.php\";';\n"
        . "\$h = <<<TXT\n@REQ@ __DIR__ . '/../kit/data.php';\nTXT;\n"
        . "\$o->@REQ@(\$x);\n";
    expect(on(['core/a.php' => $src, 'kit/data.php' => "<?php\n"]), [], 'findings');
});

H::test('EXTERNAL_REQUIRE: no such file, or outside php/code/', function (): void {
    $src = "<?php\n"
        . "@REQ@ __DIR__ . '/missing.php';\n"
        . "@REQ@ __DIR__ . '/../../../lua/code/core/event.lua';\n"
        . "@REQ@ '/etc/passwd';\n"
        . "@REQ@ 'b.php';\n";
    expect(on(['kit/a.php' => $src, 'kit/b.php' => "<?php\n"]), [
        'EXTERNAL_REQUIRE php/code/kit/a.php → line 2: /missing.php resolves to no file (php/code/kit/missing.php)',
        'EXTERNAL_REQUIRE php/code/kit/a.php → line 3: /../../../lua/code/core/event.lua resolves outside php/code/',
        'EXTERNAL_REQUIRE php/code/kit/a.php → line 4: /etc/passwd resolves outside php/code/',
    ], 'findings');
});

H::test('UNKNOWN_LAYER and SYNTAX', function (): void {
    $got = on([
        'misc/x.php' => "<?php\n",
        'core/bad.php' => "<?php\nfunction (\n",
    ]);
    H::assertEqual(count($got), 2, 'two findings');
    H::assertEqual($got[1], 'UNKNOWN_LAYER php/code/misc/x.php → in no layer', 'unknown layer');
    // the parser's own message varies by PHP version
    H::assert(str_starts_with($got[0], 'SYNTAX php/code/core/bad.php → line '), 'syntax: ' . $got[0]);
});

H::test('STATIC_STATE and GLOBAL_STATEMENT: core and observer', function (): void {
    $bad = "<?php\nfinal class C {\n"
        . "    private static int \$count = 0;\n"
        . "    public static ?array \$cache;\n"
        . "    static \$plain;\n"
        . "    public function f(): void { static \$memo = []; global \$g; }\n"
        . "}\n";
    expect(on(['core/a.php' => $bad, 'observer/a.php' => $bad, 'kit/a.php' => $bad]), [
        'STATIC_STATE php/code/core/a.php → line 3: static $count',
        'STATIC_STATE php/code/core/a.php → line 4: static $cache',
        'STATIC_STATE php/code/core/a.php → line 5: static $plain',
        'STATIC_STATE php/code/core/a.php → line 6: static $memo',
        'GLOBAL_STATEMENT php/code/core/a.php → line 6: global statement',
        'STATIC_STATE php/code/observer/a.php → line 3: static $count',
        'STATIC_STATE php/code/observer/a.php → line 4: static $cache',
        'STATIC_STATE php/code/observer/a.php → line 5: static $plain',
        'STATIC_STATE php/code/observer/a.php → line 6: static $memo',
        'GLOBAL_STATEMENT php/code/observer/a.php → line 6: global statement',
    ], 'findings');
    $fine = "<?php\nclass D {\n"
        . "    public static function make(int \$x): static { return new static(\$x); }\n"
        . "    private static function g(): void { \$f = static fn (\$y) => \$y; \$h = static function (\$z) {}; }\n"
        . "    public function h(): self|static { return static::\$cfg ?? \$this instanceof static ? \$this : \$this; }\n"
        . "    public function k(\$a): bool { return \$a instanceof static ? \$a->x : \$this->x; }\n"
        . "    public function __construct(public int \$x = 0) {}\n"
        . "}\n// static \$x; global \$y;\n";
    expect(on(['core/d.php' => $fine]), [], 'look-alikes');
});

H::test('HOST_CALL: rand() and the deny list in core, not in observer', function (): void {
    $bad = "<?php\nnamespace Siso\\Core;\n"
        . "function f(): int { return rand(1, 6); }\n"
        . "function g(): void { echo 'x'; print 'y'; \$t = \\time(); \$m = MT_RAND(); }\n"
        . "function h(): void { \$e = getenv('HOME'); \$o = `ls`; file_put_contents('/tmp/x', 'y'); }\n";
    expect(on(['core/a.php' => $bad]), [
        'HOST_CALL php/code/core/a.php → line 3: rand()',
        'HOST_CALL php/code/core/a.php → line 4: echo',
        'HOST_CALL php/code/core/a.php → line 4: print',
        'HOST_CALL php/code/core/a.php → line 4: time()',
        'HOST_CALL php/code/core/a.php → line 4: mt_rand()',
        'HOST_CALL php/code/core/a.php → line 5: getenv()',
        'HOST_CALL php/code/core/a.php → line 5: backtick shell command',
        'HOST_CALL php/code/core/a.php → line 5: file_put_contents()',
    ], 'findings');
    $fine = "<?php\nnamespace Siso\\Core;\n"
        . "final class K { public function time(): int { return 1; } const RAND = 1; }\n"
        . "function u(K \$k): array { return [\$k->time(), \$k?->time(), K::rand(), 'rand', \\Siso\\Core\\time(), K::RAND]; }\n"
        . "// rand(); echo 'x';\n";
    expect(on(['core/k.php' => $fine]), [], 'look-alikes');
    expect(on(['observer/t.php' => "<?php\nfunction now(): int { return time(); }\n"]), [], 'observer may call time()');
});

echo '  real tree: ' . count($realSources) . ' files, ' . count($real['inclusions']) . ' inclusions, '
    . count($real['findings']) . " findings\n";

H::finish('layers');
