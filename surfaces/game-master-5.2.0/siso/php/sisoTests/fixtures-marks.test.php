<?php
/*
fixtures-marks.test.php — the runner (php/code/fixtures/run.php) turns
fixture data into PHP data as php-fixture-format SUBSTRATE says: a list
stays a list, an object an array with its keys, JSON null is PHP null,
and every Json::EmptyArray becomes [] before a value is used as an
entry, event data or a raw return. Port of
lua/sisoTests/fixtures-marks.test.lua, case by case.
  php php/sisoTests/fixtures-marks.test.php   (from the project root)

The runner runs against each decoding the contract accepts: the
workspace's kit (Siso\Kit\Data::decode, where [] is Json::EmptyArray)
and json_decode($text, true) (where both [] and {} are []). A recording
stream (a test-local wrapper over the real Siso\Core\Stream, handed to
the runner as its Stream factory) sees exactly what the runner gives the
core: the value entered, and each gate's return before the core judges
it. Lua wraps Event and Gate instead; PHP's Event and Gate are final, and
what the stream is given is what Event was given (Event keeps the data
as it stands).

Turned into PHP's own counterpart (kit-api / php-fixture-format
SUBSTRATE: Lua's array mark becomes list-ness plus Json::EmptyArray,
data.null becomes PHP null):
  - Lua's three kits (the workspace's, marks answering strings, marks
    answering tables) become the two decodings above: the workspace's
    kit, and a decoding with no mark at all.
  - "copied through the marks": the entered data, each gate's emitted
    data and a raw return equal (===) the PHP data the JSON means: lists
    stay lists, objects keep their keys and are not lists, null is null,
    and no Json::EmptyArray and no object is left in them.
  - "a raw return is copied": also pinned for empty arrays, which only a
    raw return or an entry can hold (event data holds none, by the
    format): raw [] is consumed, raw [[]] returns [[]], and an emitRaw []
    enters [].
Inapplicable:
  - "the data is the fixture's own table, not a copy", "two calls share
    one copy", "the data has a metatable": PHP arrays are values, so a
    copy is the language's, and nothing in PHP data carries a metatable.
  - "marks answering tables" (the kit before stateless-marks): PHP has
    one form of mark, an enum case recognised by name; a stand-in
    Siso\Kit\Json cannot share a process with the real kit.
*/
declare(strict_types=1);

namespace Siso\Tests\FixturesMarks;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';
require_once __DIR__ . '/../code/kit/data.php';
require_once __DIR__ . '/../code/fixtures/run.php';

use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Fixtures\Runner;
use Siso\Harness\H;
use Siso\Kit\Data;
use Siso\Kit\Json;

function joined(array $list): string
{
    return implode('; ', $list);
}

/** a stream that records what it is given, over the real one */
final class Recording
{
    private Stream $real;
    /** @var list<mixed> every value given to emit */
    public array $entered = [];
    /** @var array<string, list<mixed>> every return of each gate, by signature */
    public array $returned = [];
    /** @var array<string, Gate> each gate the runner built, by signature */
    public array $gates = [];

    public function __construct()
    {
        $this->real = new Stream();
    }

    public function register(Gate $g): void
    {
        $this->gates[$g->signature] = $g;
        $this->returned[$g->signature] = [];
        $this->real->register(new Gate($g->signature, function (Event $e) use ($g): mixed {
            $r = ($g->transform)($e);
            $this->returned[$g->signature][] = $r;
            return $r;
        }));
    }

    public function emit(mixed $e): void
    {
        $this->entered[] = $e;
        $this->real->emit($e);
    }

    public function takeResidue(): array
    {
        return $this->real->takeResidue();
    }

    public function sampleHere(): array
    {
        return $this->real->sampleHere();
    }

    public function attach(mixed $f): void
    {
        $this->real->attach($f);
    }

    public function detach(mixed $f): void
    {
        $this->real->detach($f);
    }
}

/** run $f on a Recording; → [the result, the Recording] */
function recorded(array $f): array
{
    $made = [];
    $r = Runner::run($f, ['Stream' => static function () use (&$made): Recording {
        $made[] = new Recording();
        return $made[count($made) - 1];
    }]);
    H::assertEqual(count($made), 1, 'streams made');
    return [$r, $made[0]];
}

// ── the decodings ───────────────────────────────────────────────

$decoders = [
    "the workspace's Data::decode" => static function (string $t): mixed {
        $why = null;
        $v = Data::decode($t, $why);
        if ($why !== null) {
            throw new \RuntimeException('decode: ' . $why);
        }
        return $v;
    },
    'json_decode($text, true)' => static fn (string $t): mixed => json_decode($t, true, 512, JSON_THROW_ON_ERROR),
];

// data with every kind of value the fixture JSON decodes to
const SAMPLE = '{"list":[1,"x",null],"obj":{"k":"v","inner":[2]},"n":null}';
const EXPECTED = ['list' => [1, 'x', null], 'obj' => ['k' => 'v', 'inner' => [2]], 'n' => null];

/** the first Json::EmptyArray or object in $v, as a path; null when none */
function markIn(mixed $v, string $where = '$'): ?string
{
    if ($v === Json::EmptyArray) {
        return $where . ' is Json::EmptyArray';
    }
    if (is_object($v)) {
        return $where . ' is an object (' . get_class($v) . ')';
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            $why = markIn($x, $where . '[' . var_export($k, true) . ']');
            if ($why !== null) {
                return $why;
            }
        }
    }
    return null;
}

/** the whole of the runner's PHP data for SAMPLE */
function copyProblems(mixed $got): array
{
    $p = [];
    if (!is_array($got)) {
        return ['the data is ' . get_debug_type($got)];
    }
    if ($got !== EXPECTED) {
        $p[] = 'the data is ' . H::show($got) . ', expected ' . H::show(EXPECTED);
    }
    if (!array_is_list($got['list'] ?? null)) {
        $p[] = 'list is not a list';
    }
    if (is_array($got['obj'] ?? null) && array_is_list($got['obj'])) {
        $p[] = 'obj, an object, is a list';
    }
    $mark = markIn($got);
    if ($mark !== null) {
        $p[] = $mark;
    }
    return $p;
}

// ── cases ───────────────────────────────────────────────────────

foreach ($decoders as $kit => $decode) {
    H::test($kit . ': an entry\'s data is PHP data', function () use ($decode): void {
        $f = [
            'format' => 1, 'name' => 'an entry with marks', 'covers' => ['Rule 4'], 'gates' => [],
            'steps' => [['emit' => ['type' => 'q', 'data' => $decode(SAMPLE)], 'expect' => 'settled']],
        ];
        [$r, $s] = recorded($f);
        H::assertEqual(count($s->entered), 1, 'entries');
        $e = $s->entered[0];
        H::assert($e instanceof Event && $e->type === 'q', 'the entry is ' . get_debug_type($e));
        $p = copyProblems($e->data);
        H::assert($p === [], joined($p));
        H::assert($r['passed'] === $r['total'], $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']));
    });

    H::test($kit . ": a gate's emitted data is PHP data, every call", function () use ($decode): void {
        $f = [
            'format' => 1, 'name' => 'a gate emitting marks', 'covers' => ['Rule 1'],
            'gates' => [['signature' => 'a', 'does' => ['emit' => [['type' => 'out', 'data' => $decode(SAMPLE)]]]]],
            'steps' => [['emit' => ['type' => 'a'], 'expect' => 'settled'], ['emit' => ['type' => 'a'], 'expect' => 'settled']],
        ];
        [$r, $s] = recorded($f);
        $outs = $s->returned['a'] ?? [];
        H::assertEqual(count($outs), 2, 'calls');
        foreach ($outs as $i => $list) {
            H::assert(is_array($list) && count($list) === 1 && ($list[0] ?? null) instanceof Event && $list[0]->type === 'out',
                'call ' . ($i + 1) . ' returned ' . H::show($list));
            $p = copyProblems($list[0]->data);
            H::assert($p === [], 'call ' . ($i + 1) . ': ' . joined($p));
        }
        H::assert($r['passed'] === $r['total'], $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']));
    });

    H::test($kit . ': a raw return is PHP data', function () use ($decode): void {
        $f = [
            'format' => 1, 'name' => 'a raw array', 'covers' => ['Rule 1'],
            'gates' => [['signature' => 'a', 'does' => ['raw' => $decode('[1,null]')]]],
            'steps' => [['emit' => ['type' => 'a'], 'expect' => ['rejected' => ['code' => 'SISO_NOT_EVENT']]]],
        ];
        [$r, $s] = recorded($f);
        H::assert($r['passed'] === $r['total'], $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']));
        H::assertEqual($s->returned['a'] ?? null, [[1, null]], 'what the gate returned in the stream');
        // the transform the runner built, called directly: only it runs
        H::assert(isset($s->gates['a']), 'the runner built no gate a');
        $returned = ($s->gates['a']->transform)(new Event('a'));
        H::assertEqual($returned, [1, null], 'the raw return');
    });

    H::test($kit . ': empty arrays in a raw return or an entry are []', function () use ($decode): void {
        $f = [
            'format' => 1, 'name' => 'raw empties', 'covers' => ['Rule 1'],
            'gates' => [
                ['signature' => 'e', 'does' => ['raw' => $decode('[]')]],
                ['signature' => 'o', 'does' => ['raw' => $decode('{}')]],
                ['signature' => 'n', 'does' => ['raw' => $decode('[[],{"l":[]}]')]],
            ],
            'steps' => [
                ['emit' => ['type' => 'e'], 'expect' => ['settled' => true, 'trace' => [['type' => 'e', 'claimed' => true]]]],
                ['emit' => ['type' => 'o'], 'expect' => ['settled' => true, 'trace' => [['type' => 'o', 'claimed' => true]]]],
                ['emit' => ['type' => 'n'], 'expect' => ['rejected' => ['code' => 'SISO_NOT_EVENT', 'at' => ['n']]]],
                ['emitRaw' => $decode('[]'), 'expect' => ['rejected' => ['code' => 'SISO_NOT_EVENT']]],
                ['take' => $decode('[]')],
            ],
        ];
        [$r, $s] = recorded($f);
        H::assert($r['passed'] === $r['total'] && $r['problems'] === [], $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']));
        H::assertEqual($s->returned['e'] ?? null, [[]], 'raw [] is the empty list');
        H::assertEqual($s->returned['o'] ?? null, [[]], 'raw {} is the empty list');
        H::assertEqual($s->returned['n'] ?? null, [[[], ['l' => []]]], 'nested empties');
        H::assertEqual($s->entered[3] ?? 'none', [], 'emitRaw [] enters []');
    });

    H::test($kit . ': a decoded fixture with marks passes end to end', function () use ($decode): void {
        $f = $decode('{"format":1,"name":"decoded marks","covers":["Rule 4"],
            "gates":[{"signature":"a","does":{"emitOne":{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}}}],
            "steps":[{"emit":{"type":"a","data":{"l":[3]}},"expect":"settled"},
                     {"take":[{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}]}]}');
        $r = Runner::run($f);
        H::assert($r['passed'] === $r['total'] && $r['problems'] === [], $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']));
    });
}

H::finish('fixtures-marks');
