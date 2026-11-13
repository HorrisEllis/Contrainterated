<?php
/*
kit-data-stateless.test.php — nothing php/code/kit/data.php hands out gives
a gate something it could write and later read back (kit-api; core-api
STATELESS THROUGH THE EVENT, IMMUTABLE SENTINELS, core 13.0.0): arrays are
values, JSON null is PHP null, and the one sentinel, Json::EmptyArray, is
an enum case. Port of lua/sisoTests/kit-data-stateless.test.lua, case by
case.
  php php/sisoTests/kit-data-stateless.test.php   (from the project root)
Expected values: the names are the kit-api contract's
(php/code/contracts/kit_api.php); the counting probe is core-api's
checkReadonly "count" gate (public statics reachable from the input, plus
a write to every object reached), run here on what Data hands out
directly, without a stream.

Turned into PHP's own counterpart (kit-api SUBSTRATE: Lua's "marks hand
out strings, never a table" becomes "Data::array([]) is an enum case,
never an object that could hold state"; data.null becomes PHP null):
  - getmetatable(null) == "readonly:kit.data.null": JSON null is PHP
    null, no object at all.
  - getmetatable(array(…)) == "kit.data.array": Data::array([]) is the
    enum case Json::EmptyArray; Data::array of a list is that list (an
    array, no object).
  - "decoded arrays answer the string mark, decoded null is data.null":
    decoded arrays are lists and Json::EmptyArray, decoded null is null.
  - "nothing reachable … answers getmetatable with a table": every object
    reachable from what Data hands out is Json::EmptyArray.
  - "a gate cannot carry a count … through the marks": the count probe
    over the objects reached and their classes' public statics, and Data
    and Json hold no static state (CoreApi::staticStateOf).
  - "the marks cannot be replaced or stripped": the enum case refuses a
    new property, a Reflection write, clone and `new`.
  - "data.null still refuses assignment": PHP null is a value; writing a
    copy of decoded data changes only that copy.
  - "data.array on a table that answers the mark (a frozen marked
    array)": Data::array on a list read from an Event's data returns it.
  - "rejection: data.array raises on any other metatable": Data::array
    refuses a non-list (\InvalidArgumentException) and a non-array (the
    type declaration: an enum case, the sentinel itself, a string).
  - "is_array: true exactly for …": isArray is true exactly for a
    non-empty list and Json::EmptyArray.
  - "the mark carries no identity: a second load of kit.data agrees": a
    PHP class loads once per process, so the counterpart is a second
    process: an array encoded in one and decoded in another is an array,
    and Json::EmptyArray survives serialize/unserialize as itself.
  - "encode reads a table answering \"kit.data.array\" as an array":
    Json::EmptyArray and lists, also from event data.
  - "rejection: encode refuses a table answering any other metatable":
    other enum cases (pure and backed), an ArrayObject, a stdClass.
  - "rejection: a marked array with extra keys is still refused":
    Data::array refuses it; the array itself is an object.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\KitDataStateless;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/../code/kit/data.php';
require_once __DIR__ . '/../code/core/event.php';

use Siso\Contracts\CoreApi as API;
use Siso\Core\Event;
use Siso\Harness\H;
use Siso\Kit\Data;
use Siso\Kit\Json;

enum Other
{
    case EmptyArray;
}

enum Backed: string
{
    case EmptyArray = '[]';
}

/** every object reachable from $v (arrays walked; enum cases hold nothing) */
function objects(mixed $v): array
{
    $out = [];
    $todo = [$v];
    while ($todo !== []) {
        $x = array_pop($todo);
        if (is_array($x)) {
            foreach ($x as $y) {
                $todo[] = $y;
            }
        } elseif (is_object($x)) {
            $out[] = $x;
            if (!$x instanceof \UnitEnum) {
                foreach (get_object_vars($x) as $y) {
                    $todo[] = $y;
                }
            }
        }
    }
    return $out;
}

/** what the kit hands out: built, marked and decoded values */
function handed(): array
{
    return [
        null, Data::array([]), Data::array([1, Data::array([null])]),
        Data::decode('{"a":[1,[],{"b":null}],"n":null,"o":{"l":[[true]]}}'),
        Data::decode('[]'), Data::decode('null'),
    ];
}

// ── what the marks are ────────────────────────

H::test('JSON null is PHP null: no object', function (): void {
    H::assert(Data::decode('null') === null);
    H::assertEqual(Data::encode(null), 'null');
});

H::test('Data::array([]) is the enum case Json::EmptyArray; a list stays an array', function (): void {
    H::assert(Data::array([]) instanceof \UnitEnum, 'an enum case');
    H::assert(Data::array([]) === Json::EmptyArray, 'the case itself');
    H::assert(Data::array([]) === Data::array([]), 'one identity');
    H::assert(is_array(Data::array([1])), 'a list is an array, no object');
});

H::test('decoded arrays are lists and Json::EmptyArray, decoded null is null', function (): void {
    $v = Data::decode('{"a":[1,[],{"b":null}],"n":null}');
    H::assert(is_array($v['a']) && array_is_list($v['a']));
    H::assert($v['a'][1] === Json::EmptyArray);
    H::assert($v['a'][2] === ['b' => null], 'an object is a plain array');
    H::assert($v['n'] === null && $v['a'][2]['b'] === null, 'decoded null is null');
});

H::test('nothing reachable from what Data hands out is an object other than Json::EmptyArray', function (): void {
    foreach (handed() as $i => $h) {
        foreach (objects($h) as $o) {
            H::assert($o === Json::EmptyArray, 'value ' . $i . ' reaches a ' . get_debug_type($o));
        }
    }
});

/**
 * core-api checkReadonly's probe: a gate that counts through what its
 * input reaches: a property written on every object reached, and every
 * public static of their classes. Run twice on fresh inputs, a second run
 * sees 0 when nothing handed out is writable state.
 */
function countThroughMarks(mixed $input): int
{
    $n = 0;
    foreach (objects($input) as $o) {
        API::attempt(static function () use ($o, &$n): void {
            $o->n = ($o->n ?? 0) + 1;
            $n += $o->n;
        });
        foreach ((new \ReflectionClass($o))->getProperties(\ReflectionProperty::IS_STATIC) as $p) {
            API::attempt(static function () use ($p, &$n): void {
                $v = $p->getValue();
                $p->setValue(null, is_int($v) ? $v + 1 : 1);
                $n += $p->getValue();
            });
        }
    }
    return $n;
}

H::test('a gate cannot carry a count from one call to the next through what Data hands out', function (): void {
    $input = static fn (): array => ['l' => Data::array([null, Data::array([])]), 'd' => Data::decode('[null,[1]]'), 'n' => null, 'h' => handed()];
    countThroughMarks($input());
    H::assertEqual(countThroughMarks($input()), 0, 'second call');
    H::assertEqual(API::staticStateOf(Data::class), [], 'Data static state');
    H::assertEqual(API::staticStateOf(Json::class), [], 'Json static state');
});

H::test('the sentinel cannot be written, cloned or made', function (): void {
    $e = Json::EmptyArray;
    H::assertThrows(static function () use ($e): void {
        $e->x = 1;
    }, '', 'a new property');
    H::assertThrows(static function () use ($e): void {
        (new \ReflectionProperty($e, 'name'))->setValue($e, 'Other');
    }, '', 'a Reflection write');
    H::assertThrows(static fn () => clone $e, '', 'clone');
    H::assertThrows(static fn () => (new \ReflectionClass(Json::class))->newInstanceWithoutConstructor(), '', 'a new instance');
    H::assertEqual($e->name, 'EmptyArray', 'still itself after the attempts');
    H::assert(Data::array([]) === $e, 'Data::array([]) still gives it');
});

// ── null is a value ───────────────────────────

H::test('null is a value: a write to a copy of decoded data reaches only that copy', function (): void {
    $why = 'not set';
    $v = Data::decode('{"n":null}', $why);
    H::assertEqual($why, null);
    $w = $v;
    $w['n'] = 1;
    H::assert($v === ['n' => null], 'the decoded value kept its null');
    H::assertEqual(Data::encode($v), '{"n":null}');
    H::assert(Data::decode('null') === null, 'a second decode is still null');
});

// ── Data::array and isArray ───────────────────

H::test('Data::array on an array returns that array', function (): void {
    $t = Data::array([1]);
    H::assert(Data::array($t) === $t);
    $d = Data::decode('[1]');
    H::assert(Data::array($d) === $d, 'a decoded array');
});

H::test('Data::array on a list read from event data returns it', function (): void {
    $f = (new Event('t', ['l' => [1, 2]]))->data['l'];
    H::assert(Data::array($f) === $f);
});

H::test('rejection: Data::array refuses a non-list and a non-array', function (): void {
    H::assertThrows(static fn () => Data::array(['k' => 1]), 'not a list');
    H::assertThrows(static fn () => Data::array([1 => 'a']), 'not a list');
    H::assertThrows(static fn () => Data::array([0 => 1, 'x' => 2]), 'not a list');
    H::assertThrows(static fn () => Data::array(Json::EmptyArray), 'must be of type array');
    H::assertThrows(static fn () => Data::array(Other::EmptyArray), 'must be of type array');
    H::assertThrows(static fn () => Data::array('[]'), 'must be of type array');
});

H::test('isArray: true exactly for a non-empty list or Json::EmptyArray', function (): void {
    H::assert(Data::isArray(Data::array([])));
    H::assert(Data::isArray([1, 2]), 'a list');
    H::assert(Data::isArray((new Event('t', ['l' => [1]]))->data['l']), 'a list from event data');
    H::assert(!Data::isArray([]), 'the empty object');
    H::assert(!Data::isArray([1 => 1]), 'a list not from 0');
    H::assert(!Data::isArray(['a' => 1]), 'an object');
    H::assert(!Data::isArray(Other::EmptyArray), 'another enum case of the same name');
    H::assert(!Data::isArray(Backed::EmptyArray), 'a backed enum case');
    H::assert(!Data::isArray(null), 'null');
    H::assert(!Data::isArray('EmptyArray'), "the case's name is not the case");
    H::assert(!Data::isArray(new \ArrayObject([1])), 'an ArrayObject');
});

H::test('the sentinel carries no state: another process and unserialize agree on arrays', function (): void {
    H::assert(unserialize(serialize(Json::EmptyArray)) === Json::EmptyArray, 'unserialize gives the case itself');
    H::assert(unserialize(serialize(['e' => Json::EmptyArray, 'l' => [1]])) === ['e' => Json::EmptyArray, 'l' => [1]]);
    $text = (string)Data::encode(['e' => Data::array([]), 'l' => [1]]);
    $script = 'require ' . var_export(realpath(__DIR__ . '/../code/kit/data.php'), true) . ';'
        . ' $v = \Siso\Kit\Data::decode(' . var_export($text, true) . ');'
        . ' echo \Siso\Kit\Data::isArray($v["e"]) && $v["e"] === \Siso\Kit\Json::EmptyArray ? "agree " : "differ ";'
        . ' echo \Siso\Kit\Data::encode($v);';
    $h = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    H::assert(is_resource($h), 'a second process');
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    H::assertEqual(proc_close($h), 0, 'exit code: ' . $out);
    H::assertEqual($out, 'agree ' . $text);
});

// ── encode reads only the kit's sentinel as an array ─

H::test('encode reads Json::EmptyArray and lists as arrays', function (): void {
    H::assertEqual(Data::encode(Json::EmptyArray), '[]');
    H::assertEqual(Data::encode([1, 'x', null]), '[1,"x",null]');
    H::assertEqual(Data::encode(['a' => (new Event('t', ['l' => [2.5]]))->data['l']]), '{"a":[2.5]}');
});

H::test('rejection: encode refuses any other enum case or object', function (): void {
    foreach ([Other::EmptyArray, Backed::EmptyArray, new \ArrayObject([]), new \stdClass()] as $v) {
        $why = null;
        $s = Data::encode(['v' => $v], $why);
        H::assertEqual($s, null, get_debug_type($v));
        H::assert(is_string($why) && str_contains($why, 'is not data') && str_contains($why, 'at $.v'), H::show($why));
    }
});

H::test('rejection: a list with an extra key is no list', function (): void {
    $t = Data::array([1]);
    $t['x'] = 2;
    H::assert(!Data::isArray($t));
    H::assertThrows(static fn () => Data::array($t), 'not a list');
    H::assertEqual(Data::encode($t), '{"0":1,"x":2}', 'it is an object');
});

H::finish('kit-data-stateless');
