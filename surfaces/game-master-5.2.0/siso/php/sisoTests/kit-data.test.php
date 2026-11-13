<?php
/*
kit-data.test.php — php/code/kit/data.php (canonical JSON, Json::EmptyArray
and list-ness as the array mark), then the kit-api module check on the
real module. Port of lua/sisoTests/kit-data.test.lua, case by case.
  php php/sisoTests/kit-data.test.php      (from the project root)
Expected values come from outside the code, as Lua's:
  Float texts are ECMAScript's Number::toString (Node 22, `String(x)`),
  with ".0" appended to integral values so the float type survives, and
  "-0.0" for negative zero (the Lua suite's table, pasted from Node).
  Everything else is canonical JSON written out by hand.

Kept as Lua's (same inputs, same expected text): the floats, integers and
floats kept apart, NaN and infinities, string escapes, invalid UTF-8, key
order, order-independence, nested structures, the nesting limit, a cheap
deep failure, the same array in two places, every decode case but one,
the round trips.

Turned into PHP's own counterpart (kit-api SUBSTRATE: Lua's array mark
becomes list-ness plus Json::EmptyArray; data.null becomes PHP null):
  - "kit-api check passes on the real data and sha256 modules": the check
    on Data and Json (sha256 and helper are not ported).
  - "exports are exactly the contract's": Data's public methods and
    Json's cases are exactly KitApi::EXPORTS.
  - "the modules require nothing outside code/kit/": data.php requires
    and includes nothing.
  - "null: renders as null, cannot be written to, answers the mark": JSON
    null is PHP null, a value with nothing to write; it encodes as null
    at every depth.
  - "array: marks, is idempotent, rejects a foreign metatable": a
    non-empty list is the array itself (Data::array returns it,
    unchanged), [] gives Json::EmptyArray, a non-list is refused with
    \InvalidArgumentException, a non-array by the type declaration.
  - "array: a table that answers the mark through getmetatable": a list
    and Json::EmptyArray in an Event's (read-only) data are arrays.
  - "a table with holes or mixed keys is rejected": PHP has no hole or
    mixed key that is not data: an array that is not a list is an object
    whose keys are rendered as strings (kit-api), pinned here.
  - "a marked array with a hole or extra keys is rejected": no array mark
    can sit on a non-list: Data::array refuses it.
  - "values that are not data are rejected": a Closure, a resource, a
    Generator and a Fiber (Lua's thread), a stdClass and an ArrayObject
    (Lua's foreign metatable), an Event and an enum case other than
    Json::EmptyArray; Lua's cycle is a recursive array (through a PHP
    reference), refused as too deep.
  - "decode: a non-string input is an error, not a raise": the parameter
    is `string`, so strict_types refuses it with a \TypeError.
  - "encode: does not modify its input": arrays are values; pinned anyway.
  Paths in encode errors name PHP's own (0-based) list indices: Lua's
  "$.x[2]" is "$.x[1]" here, "$.a.b[2].c" is "$.a.b[1].c".
Added, PHP's own: $why is null on success and set on failure; decode's
substrate note ({"1": x} is [1 => x], {"0": x} a list); duplicate keys
compared as strings; an encode error inside a key names ".<key>".

Not applicable (kit-api SUBSTRATE: kit.sha256 is not ported, and the
sha256 probe has no counterpart): "sha256 published vector: empty
message", "sha256 FIPS 180-4: \"abc\"", "sha256 FIPS 180-4: 448-bit
message", "sha256 published vector: 896-bit message", "sha256 FIPS 180-2
B.3 / RFC 6234: one million 'a'", the 14 "sha256 hashlib: <n>-byte
message" cases, "sha256 hashlib: all 256 byte values", "sha256.hex is 64
lowercase hex characters", "sha256 calls are independent", "sha256: a
non-string input raises".
*/
declare(strict_types=1);

namespace Siso\Tests\KitData;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/kit_api.php';
require_once __DIR__ . '/../code/kit/data.php';
require_once __DIR__ . '/../code/core/event.php';

use Siso\Contracts\KitApi as K;
use Siso\Core\Event;
use Siso\Harness\H;
use Siso\Kit\Data;
use Siso\Kit\Json;

/** an enum case that is not the kit's */
enum Other
{
    case Case;
}

function enc(mixed $v): string
{
    $why = 'not set';
    $s = Data::encode($v, $why);
    if ($s === null) {
        H::fail('encode failed: ' . (string)$why);
    }
    H::assertEqual($why, null, '$why on success');
    return $s;
}

function encErr(mixed $v, string $fragment): void
{
    $why = null;
    $s = Data::encode($v, $why);
    H::assertEqual($s, null, 'encode should fail');
    H::assert(is_string($why) && str_contains($why, $fragment), 'error ' . H::show($why) . " lacks '" . $fragment . "'");
}

function dec(string $s): mixed
{
    $why = 'not set';
    $v = Data::decode($s, $why);
    if ($why !== null) {
        H::fail('decode failed: ' . $why);
    }
    return $v;
}

function decErr(string $s, string $fragment): void
{
    $why = null;
    $v = Data::decode($s, $why);
    H::assertEqual($v, null, 'decode of ' . $s . ' should fail');
    H::assert(is_string($why) && str_contains($why, $fragment), 'error ' . H::show($why) . " lacks '" . $fragment . "'");
}

/** typed structural equality (1 !== 1.0, 0.0 differs from -0.0; object keys in any order), for assertions only */
function same(mixed $a, mixed $b): bool
{
    if (is_float($a) && is_float($b)) {
        return $a === $b && ($a !== 0.0 || fdiv(1.0, $a) === fdiv(1.0, $b));
    }
    if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
            return false;
        }
        foreach ($a as $k => $x) {
            if (!array_key_exists($k, $b) || !same($x, $b[$k])) {
                return false;
            }
        }
        return true;
    }
    return $a === $b;
}

function nest(int $n, mixed $v = 1): mixed
{
    for ($i = 0; $i < $n; $i++) {
        $v = [$v];
    }
    return $v;
}

// ── exports and the contract ──────────────────

H::test('kit-api check passes on the real Data and Json', function (): void {
    $p = K::check(['Data' => Data::class, 'Json' => Json::class]);
    H::assert($p === [], implode('; ', $p));
});

H::test("exports are exactly the contract's", function (): void {
    $methods = [];
    foreach ((new \ReflectionClass(Data::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $methods[] = $m->getName();
    }
    sort($methods, SORT_STRING);
    $want = array_keys(K::EXPORTS['Data']);
    sort($want, SORT_STRING);
    H::assertEqual($methods, $want, 'Data public methods');
    H::assertEqual(array_map(static fn (Json $c): string => $c->name, Json::cases()), array_keys(K::EXPORTS['Json']), 'Json cases');
    H::assert((new \ReflectionClass(Data::class))->isFinal(), 'Data is final');
    H::assert(!(new \ReflectionClass(Data::class))->getConstructor()->isPublic(), 'Data cannot be instantiated');
    H::assert(!(new \ReflectionEnum(Json::class))->isBacked(), 'Json is a pure enum');
});

H::test('the module requires nothing', function (): void {
    $src = (string)file_get_contents(__DIR__ . '/../code/kit/data.php');
    foreach (\PhpToken::tokenize($src) as $t) {
        H::assert(!$t->is([T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE]), 'data.php has ' . $t->text);
    }
});

// ── null and arrays ───────────────────────────

H::test('null: JSON null is PHP null, at every depth', function (): void {
    H::assertEqual(enc(null), 'null');
    H::assertEqual(enc([null]), '[null]');
    H::assertEqual(enc(['n' => null]), '{"n":null}');
    $why = 'not set';
    H::assertEqual(Data::decode('null', $why), null);
    H::assertEqual($why, null, 'decode(null) is null with $why null');
});

H::test('array: a list is the array, [] is Json::EmptyArray, a non-list is refused', function (): void {
    $t = Data::array([1]);
    H::assert(Data::isArray($t));
    H::assertEqual($t, [1], 'Data::array returns the list unchanged');
    H::assertEqual(Data::array($t), $t, 'idempotent');
    H::assert(Data::array([]) === Json::EmptyArray, 'Data::array([])');
    H::assert(Data::isArray(Data::array([])));
    H::assert(Data::isArray([1, 2]), 'a list needs no mark');
    H::assert(!Data::isArray([]), 'an empty array is the empty object');
    H::assert(!Data::isArray('x'));
    H::assert(!Data::isArray(null));
    H::assert(!Data::isArray(['k' => 1]));
    H::assert(!Data::isArray([1 => 'a']), 'keys not from 0');
    H::assertThrows(static fn () => Data::array(['k' => 1]), 'not a list');
    H::assertThrows(static fn () => Data::array([0 => 1, 2 => 3]), 'not a list');
    try {
        Data::array(['k' => 1]);
        H::fail('Data::array accepted a non-list');
    } catch (\InvalidArgumentException) {
        // the refusal is an \InvalidArgumentException (kit-api)
    }
    H::assertThrows(static fn () => Data::array(Json::EmptyArray), 'must be of type array');
});

H::test('array: a list and Json::EmptyArray in read-only event data are arrays', function (): void {
    $e = new Event('t', ['l' => [1, 2], 'e' => Data::array([]), 'o' => ['k' => 1]]);
    H::assert(Data::isArray($e->data['l']), 'a list in event data');
    H::assert($e->data['e'] === Json::EmptyArray, 'the event kept the enum case');
    H::assert(Data::isArray($e->data['e']));
    H::assert(!Data::isArray($e->data['o']));
    H::assertEqual(Data::array($e->data['l']), [1, 2]);
});

// ── encoding scalars ──────────────────────────

H::test('encode: booleans and integers', function (): void {
    H::assertEqual(enc(true), 'true');
    H::assertEqual(enc(false), 'false');
    H::assertEqual(enc(0), '0');
    H::assertEqual(enc(-17), '-17');
    H::assertEqual(enc(PHP_INT_MAX), '9223372036854775807');
    H::assertEqual(enc(PHP_INT_MIN), '-9223372036854775808');
});

const FLOATS = [
    // value, Node 22 String(x) (+ ".0" when integral)
    [1.0, '1.0'],
    [0.1, '0.1'],
    [0.1 + 0.2, '0.30000000000000004'],
    [100.0, '100.0'],
    [1.5, '1.5'],
    [-2.5, '-2.5'],
    [1e20, '100000000000000000000.0'],
    [1e21, '1e+21'],
    [1.5e21, '1.5e+21'],
    [1e-6, '0.000001'],
    [1.5e-6, '0.0000015'],
    [1e-7, '1e-7'],
    [1.23e-7, '1.23e-7'],
    [2.0 ** 53, '9007199254740992.0'],
    [5e-324, '5e-324'],
    [1.7976931348623157e308, '1.7976931348623157e+308'],
    [123456789012345680000.0, '123456789012345680000.0'],
    [0.0, '0.0'],
    [-0.0, '-0.0'],
    // exact powers of two: the rounding interval is lopsided, so the
    // shortest form can be a neighbour of the correctly rounded digits
    [2.0 ** -1017, '7.120236347223045e-307'],
    [2.0 ** 896, '5.282945311356653e+269'],
    [2.0 ** -1022, '2.2250738585072014e-308'],
    [2.0 ** 1023, '8.98846567431158e+307'],
    [-(2.0 ** -1000), '-9.332636185032189e-302'],
];

foreach (FLOATS as [$x, $text]) {
    H::test('encode float ' . $text, function () use ($x, $text): void {
        H::assertEqual(enc($x), $text);
        H::assertEqual(enc(Data::array([$x])), '[' . $text . ']');
    });
}

H::test('encode: floats do not depend on precision or serialize_precision', function (): void {
    $saved = [ini_get('precision'), ini_get('serialize_precision')];
    try {
        ini_set('precision', '3');
        ini_set('serialize_precision', '5');
        H::assertEqual(enc(0.1 + 0.2), '0.30000000000000004');
        H::assertEqual(enc(2.0 ** -1017), '7.120236347223045e-307');
    } finally {
        ini_set('precision', (string)$saved[0]);
        ini_set('serialize_precision', (string)$saved[1]);
    }
});

H::test('encode: integers and floats are kept apart', function (): void {
    H::assertEqual(enc(Data::array([1, 1.0, -0.0, 0])), '[1,1.0,-0.0,0]');
});

H::test('encode: NaN and infinities are rejected', function (): void {
    encErr(NAN, 'NaN');
    encErr(INF, 'Infinity');
    encErr(-INF, 'Infinity');
    encErr(['x' => [1.0, INF]], '$.x[1]');
});

H::test('encode: string escapes', function (): void {
    H::assertEqual(enc('a"b\\c'), '"a\\"b\\\\c"');
    H::assertEqual(enc("\x08\x0c\n\r\t"), '"\\b\\f\\n\\r\\t"');
    H::assertEqual(enc("\x00\x01\x1f"), '"\\u0000\\u0001\\u001f"');
    H::assertEqual(enc("\x7f/"), "\"\x7f/\"", "DEL and '/' are not escaped");
    H::assertEqual(enc("h\xC3\xA9 \xF0\x9F\x98\x80"), "\"h\xC3\xA9 \xF0\x9F\x98\x80\"", 'UTF-8 as is');
});

H::test('encode: invalid UTF-8 is rejected', function (): void {
    encErr("\xFF", 'UTF-8');
    encErr("\xC3", 'UTF-8');
    encErr("\xED\xA0\x80", 'UTF-8');     // an encoded surrogate
    encErr(["\xFF" => 1], 'UTF-8');
    encErr(['a' => ["\xFF" => 1]], 'at $.a.<key>');
});

// ── encoding arrays and objects ───────────────

H::test('encode: object keys sorted byte-wise', function (): void {
    H::assertEqual(enc(['b' => 1, 'a' => 2, 'B' => 3, "\xC3\xA9" => 4, 'z' => 5, '' => 6]),
        "{\"\":6,\"B\":3,\"a\":2,\"b\":1,\"z\":5,\"\xC3\xA9\":4}");
    H::assertEqual(enc(['ab' => 1, 'a' => 2, 'abcdefghij' => 3, 'abcdefghi' => 4, 'abcdefghik' => 5]),
        '{"a":2,"ab":1,"abcdefghi":4,"abcdefghij":3,"abcdefghik":5}', 'prefixes and 8-byte chunks');
    H::assertEqual(enc(['b' => 1, 10 => 2, 9 => 3]), '{"10":2,"9":3,"b":1}', 'int keys sort by their string form');
});

H::test('encode: the same object always gives the same text', function (): void {
    $keys = [];
    for ($i = 1; $i <= 200; $i++) {
        $keys[] = 'k' . (($i * 7919) % 1000);
    }
    $a = [];
    $b = [];
    foreach ($keys as $i => $k) {
        $a[$k] = $i;
    }
    for ($i = count($keys) - 1; $i >= 0; $i--) {
        $b[$keys[$i]] = $i;
    }
    H::assertEqual(enc($a), enc($b));
});

H::test('encode: arrays — lists, Data::array, empty', function (): void {
    H::assertEqual(enc([1, 2, 3]), '[1,2,3]');
    H::assertEqual(enc(Data::array(['x', null, 1.0])), '["x",null,1.0]');
    H::assertEqual(enc(Data::array([])), '[]');
    H::assertEqual(enc(Json::EmptyArray), '[]');
    H::assertEqual(enc([]), '{}', 'an empty array is the empty object');
});

H::test('encode: nested structures', function (): void {
    H::assertEqual(enc(['rows' => [[1, 'a'], [2, null]], 'columns' => ['id', 'v'], 'n' => 2]),
        '{"columns":["id","v"],"n":2,"rows":[[1,"a"],[2,null]]}');
});

H::test('encode: an array that is not a list is an object, its keys as strings', function (): void {
    H::assertEqual(enc([0 => 1, 2 => 3]), '{"0":1,"2":3}', 'a hole');
    H::assertEqual(enc([1, 'a' => 2]), '{"0":1,"a":2}', 'mixed keys');
    H::assertEqual(enc([2 => 'x']), '{"2":"x"}', 'keys not from 0');
    H::assertEqual(enc([1 => 'b', 0 => 'a']), '{"0":"a","1":"b"}', 'keys 0..n-1 out of order');
});

H::test('encode: no array mark sits on a non-list (Data::array refuses it)', function (): void {
    H::assertThrows(static fn () => Data::array([0 => 1, 2 => 3]), 'not a list');
    H::assertThrows(static fn () => Data::array([1, 2, 'x' => 1]), 'not a list');
});

H::test('encode: values that are not data are rejected', function (): void {
    encErr(['f' => static fn () => 1], 'Closure');
    encErr(static fn () => 1, 'a Closure is not data');
    encErr(STDOUT, 'resource');
    $gen = (static function () {
        yield 1;
    })();
    encErr($gen, 'Generator');
    encErr(new \Fiber(static function (): void {
    }), 'Fiber');
    encErr(new \stdClass(), 'stdClass');
    encErr(['a' => new \ArrayObject([1])], 'ArrayObject');
    encErr(['e' => new Event('t')], 'an Event is not data');
    encErr(Other::Case, 'enum case');
    $c = [];
    $c['self'] = &$c;
    encErr($c, 'nesting too deep');
});

H::test('encode: nesting up to 512 levels, as decode allows', function (): void {
    $text = enc(nest(512));
    H::assert(Data::decode($text) !== null, '512 levels decode');
    encErr(nest(513), 'nesting too deep');
    encErr(nest(512, Json::EmptyArray), 'nesting too deep');
    H::assertEqual(enc(nest(511, Json::EmptyArray)), str_repeat('[', 512) . str_repeat(']', 512), 'Json::EmptyArray counts as a level');
    decErr(str_repeat('[', 513) . str_repeat(']', 513), 'too deep');
});

H::test('encode: a deep failure costs little (paths are built lazily)', function (): void {
    $v = nest(20000);
    $t0 = microtime(true);
    encErr($v, 'nesting too deep');
    H::assert(microtime(true) - $t0 < 1.0, 'fast failure');
});

H::test('encode: the error names the path', function (): void {
    encErr(['a' => ['b' => [1, ['c' => NAN]]]], 'at $.a.b[1].c');
});

H::test('encode: the same array in two places is fine (not a cycle)', function (): void {
    $shared = [1];
    H::assertEqual(enc(['a' => $shared, 'b' => $shared]), '{"a":[1],"b":[1]}');
});

H::test('encode: does not modify its input', function (): void {
    $v = ['b' => [1, 2], 'a' => 'x', 'e' => []];
    enc($v);
    H::assert($v === ['b' => [1, 2], 'a' => 'x', 'e' => []], 'input unchanged, key order included');
});

// ── decoding ──────────────────────────────────

H::test('decode: scalars and types', function (): void {
    H::assertEqual(dec('true'), true);
    H::assertEqual(dec('false'), false);
    H::assertEqual(dec('null'), null);
    H::assertEqual(dec('12'), 12);
    H::assertEqual(dec('-0'), 0);
    H::assertEqual(dec('1.0'), 1.0);
    H::assertEqual(dec('1e2'), 100.0);
    H::assertEqual(dec('1E-2'), 0.01);
    H::assertEqual(dec('"x"'), 'x');
    $z = dec('-0.0');
    H::assertEqual($z, -0.0);
    H::assert(fdiv(1.0, $z) < 0, '-0.0 keeps its sign');
});

H::test('decode: 64-bit integer limits', function (): void {
    H::assertEqual(dec('9223372036854775807'), PHP_INT_MAX);
    H::assertEqual(dec('-9223372036854775808'), PHP_INT_MIN);
    decErr('9223372036854775808', '64-bit');
    decErr('-9223372036854775809', '64-bit');
    decErr('123456789012345678901234', '64-bit');
});

H::test('decode: arrays come back as lists and Json::EmptyArray; objects as arrays', function (): void {
    $v = dec('{"a":[1,[],{}],"b":{}}');
    H::assert(Data::isArray($v['a']));
    H::assert($v['a'][1] === Json::EmptyArray, '[] is Json::EmptyArray');
    H::assert($v['a'][2] === [] && !Data::isArray($v['a'][2]), '{} is []');
    H::assert(!Data::isArray($v));
    H::assertEqual(count($v['a']), 3);
    H::assertEqual($v['b'], []);
});

H::test('decode: nulls inside arrays keep the length', function (): void {
    $v = dec('[null,null,3]');
    H::assertEqual($v, [null, null, 3]);
});

H::test('decode: string escapes and \\u, including surrogate pairs', function (): void {
    H::assertEqual(dec('"a\\"b\\\\c\\/d\\b\\f\\n\\r\\t"'), "a\"b\\c/d\x08\x0c\n\r\t");
    H::assertEqual(dec('"\\u00e9\\u0041\\u0000"'), "\xC3\xA9A\x00");
    H::assertEqual(dec('"\\ud83d\\ude00"'), "\xF0\x9F\x98\x80");
});

H::test('decode: bad strings are rejected', function (): void {
    decErr('"\\ud83d"', 'surrogate');
    decErr('"\\ude00"', 'surrogate');
    decErr('"\\x"', 'bad escape');
    decErr('"\\u12"', '\\u');
    decErr("\"a\nb\"", 'control character');
    decErr('"abc', 'unterminated');
    decErr("\"\xFF\"", 'UTF-8');
});

H::test('decode: bad numbers are rejected', function (): void {
    decErr('01', 'leading zero');
    decErr('-01', 'leading zero');
    decErr('1.', 'fraction');
    decErr('.5', 'unexpected character');
    decErr('1e', 'exponent');
    decErr('+1', 'unexpected character');
    decErr('-', 'bad number');
    decErr('1E400', 'out of range');
    decErr('NaN', 'unexpected character');
    decErr('Infinity', 'unexpected character');
});

H::test('decode: structure errors', function (): void {
    decErr('', 'end of input');
    decErr('   ', 'end of input');
    decErr('[1,]', 'unexpected character');
    decErr('{"a":1,}', 'string key');
    decErr('{"a":1,"a":2}', 'duplicate key');
    decErr('{"a" 1}', "':'");
    decErr('[1 2]', "','");
    decErr('{1:2}', 'string key');
    decErr('[1] x', 'trailing');
    decErr("// c\n1", 'unexpected character');
    decErr('tru', 'unexpected character');
    decErr(str_repeat('[', 600) . str_repeat(']', 600), 'too deep');
});

H::test('decode: whitespace between tokens is allowed', function (): void {
    $v = dec(" \n\t{ \"a\" : [ 1 , 2 ] ,\r\n \"b\" : null } \n");
    H::assertEqual(enc($v), '{"a":[1,2],"b":null}');
});

H::test('decode: a non-string input is refused by the type declaration', function (): void {
    try {
        Data::decode(null);
        H::fail('decode(null) returned');
    } catch (\TypeError $err) {
        H::assert(str_contains($err->getMessage(), 'must be of type string'), $err->getMessage());
    }
});

H::test('decode: $why is null on success and names the byte on failure', function (): void {
    $why = 'not set';
    Data::decode('[1]', $why);
    H::assertEqual($why, null);
    Data::decode('[1,]', $why);
    H::assertEqual($why, 'Data::decode: unexpected character at byte 4');
    $why = 'not set';
    Data::encode([1], $why);
    H::assertEqual($why, null, 'encode');
});

H::test("decode: PHP's key coercion (substrate): integer-string keys become ints", function (): void {
    H::assert(dec('{"1":"x"}') === [1 => 'x'], '{"1": x} is [1 => x]');
    H::assertEqual(enc(dec('{"1":"x"}')), '{"1":"x"}', 'and encodes back as the object');
    H::assert(dec('{"0":"x"}') === ['x'], '{"0": x} is a list');
    H::assertEqual(enc(dec('{"0":"x"}')), '["x"]', 'which encodes as an array');
    H::assert(dec('{"1":1,"01":2}') === [1 => 1, '01' => 2], '"01" stays a string key, no collision');
    decErr('{"1":1,"1":2}', 'duplicate key "1"');
});

// ── round trips and canonical form ────────────

H::test('round trip: canonical text → value → the same text', function (): void {
    $texts = [
        '{"a":[1,2.5,"x",null,true,false],"b":{"c":{}},"d":[]}',
        '[[],[[]],{"":""}]',
        '{"n":-9223372036854775808,"f":-1.5e-300}',
    ];
    foreach ($texts as $t) {
        $canonical = enc(dec($t));
        H::assertEqual(enc(dec($canonical)), $canonical);
    }
    H::assertEqual(enc(dec($texts[0])), $texts[0]);
});

H::test('canonicalization: key order, whitespace and number spelling', function (): void {
    H::assertEqual(enc(dec('{ "b" : 1E2, "a" : 0.50 }')), '{"a":0.5,"b":100.0}');
});

H::test('round trip: value → text → an equal value (types kept)', function (): void {
    $v = ['rows' => Data::array([Data::array([1, 1.0, '1', null]), Data::array([])]), 'meta' => []];
    H::assert(same(dec(enc($v)), $v), H::show(dec(enc($v))));
});

H::test('round trip: 10,000 floats come back bit-exact', function (): void {
    for ($i = 1; $i <= 10000; $i++) {
        // deterministic bit patterns: the first 8 bytes of sha256("float <i>")
        $x = unpack('e', substr(hash('sha256', 'float ' . $i, true), 0, 8))[1];
        if (is_finite($x)) {
            $y = dec(enc($x));
            H::assert(is_float($y) && pack('e', $y) === pack('e', $x), 'float ' . bin2hex(pack('E', $x)) . ' at ' . $i);
        }
    }
});

H::finish('kit-data');
