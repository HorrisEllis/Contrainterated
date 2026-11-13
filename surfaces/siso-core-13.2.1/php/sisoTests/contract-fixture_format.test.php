<?php
// contract test: fixture-format (php/code/contracts/fixture_format.php).
// The shape check accepts well-formed fixtures and names malformed
// ones; the matching rules are order-free (K1). The shared files
// themselves are the fixtures boundary's: php/sisoTests/fixtures.test.php
// checks each one's shape, as the Lua fixtures.test.lua does.
declare(strict_types=1);

namespace Siso\Kit {
    // A tiny stand-in for the kit's empty-array enum, so this test does
    // not need php/code/kit/data.php (a later phase writes it). The
    // contract recognises the case by name, never by loading the kit.
    if (!\enum_exists(Json::class)) {
        enum Json
        {
            case EmptyArray;
        }
    }
}

namespace Siso\Tests\ContractFixtureFormat {

    require_once __DIR__ . '/../code/harness.php';
    require_once __DIR__ . '/../code/contracts/fixture_format.php';
    require_once __DIR__ . '/contract-fake-core.php';

    use Siso\Contracts\FixtureFormat as F;
    use Siso\Harness\H;
    use Siso\Kit\Json;
    use Siso\Tests\Fake\Event;

    function joined(array $list): string
    {
        return implode('; ', $list);
    }

    function has(array $list, string $needle): bool
    {
        foreach ($list as $s) {
            if (str_contains($s, $needle)) {
                return true;
            }
        }
        return false;
    }

    // a decoded fixture, as a JSON decoder would give it
    $cycle = [
        'format' => 1,
        'name' => 'an indirect cycle is rejected at the first claimed delivery deeper than the gate count',
        'covers' => ['Rule 3', 'K5'],
        'gates' => [
            ['signature' => 'a', 'does' => ['emitOne' => ['type' => 'b']]],
            ['signature' => 'b', 'does' => ['emit' => [['type' => 'a', 'data' => ['n' => 1]], ['raw' => 42]]]],
        ],
        'steps' => [
            ['emit' => ['type' => 'a'], 'expect' => ['rejected' => ['code' => 'SISO_CYCLE', 'eventType' => 'a', 'depth' => 3, 'at' => ['a', 'b', 'a']],
                'trace' => [['type' => 'a', 'claimed' => true, 'then' => [['type' => 'b', 'claimed' => true, 'then' => [['type' => 'a', 'claimed' => true]]]]]]]],
            ['sample' => ['residue' => [], 'gateCount' => 2]],
            ['register' => ['signature' => 'a', 'does' => ['nothing' => true]], 'refused' => true],
            ['emitRaw' => 'x', 'expect' => ['rejected' => ['code' => 'SISO_NOT_EVENT']]],
            ['emitRaw' => null, 'expect' => ['rejected' => ['code' => 'SISO_NOT_EVENT']]],
            ['take' => []],
        ],
    ];

    H::test('a well-formed fixture passes', function () use ($cycle): void {
        $p = F::problems($cycle);
        H::assert($p === [], joined($p));
    });

    H::test('Json::EmptyArray is an empty list wherever the format expects a list', function () use ($cycle): void {
        $f = $cycle;
        $f['gates'][] = ['signature' => 'e', 'does' => ['emit' => Json::EmptyArray]];
        $f['steps'][1] = ['sample' => ['residue' => Json::EmptyArray, 'gateCount' => 3]];
        $f['steps'][5] = ['take' => Json::EmptyArray];
        $f['steps'][0]['expect']['trace'][0]['then'][0]['then'][0]['then'] = Json::EmptyArray;
        $p = F::problems($f);
        H::assert($p === [], joined($p));
        H::assert(F::residueMatch(Json::EmptyArray, []), 'an empty take');
        H::assert(F::traceMatch(Json::EmptyArray, []), 'an empty trace');
        H::assert(F::isList(Json::EmptyArray) && !F::isList(['k' => 1]), 'isList');
        H::assert(has(F::problems(['format' => 1, 'name' => 'x', 'covers' => Json::EmptyArray, 'gates' => [], 'steps' => [['take' => []]]]), 'covers'), 'empty covers');
        H::assert(has(F::problems(['format' => 1, 'name' => 'x', 'covers' => ['K1'], 'gates' => [], 'steps' => [['emit' => ['type' => 'a', 'data' => ['l' => Json::EmptyArray]], 'expect' => 'settled']]]), 'empty array'), 'an empty array in event data');
    });

    H::test('malformed fixtures are named', function (): void {
        $base = static fn (array $over): array => array_replace(['format' => 1, 'name' => 'x', 'covers' => ['K1'], 'gates' => [], 'steps' => [['take' => []]]], $over);
        H::assert(has(F::problems($base(['format' => 2])), 'format'), 'format');
        H::assert(has(F::problems($base(['covers' => []])), 'covers'), 'covers');
        H::assert(has(F::problems($base(['gates' => [['signature' => 'a', 'does' => ['emit' => [], 'throw' => 'x']]]])), 'exactly one'), 'two behaviours');
        H::assert(has(F::problems($base(['steps' => [['emit' => ['type' => 'a', 'data' => ['f' => 1.5]], 'expect' => 'settled']]])), 'float'), 'float data');
        H::assert(has(F::problems($base(['steps' => [['emit' => ['type' => 'a'], 'expect' => ['rejected' => ['code' => 'NOPE']]]]])), 'known code'), 'unknown code');
        H::assert(has(F::problems($base(['steps' => [['emit' => ['type' => 'a'], 'expect' => ['settled' => true, 'rejected' => ['code' => 'SISO_CYCLE']]]]])), 'exactly one of'), 'both outcomes');
        H::assert(has(F::problems($base(['steps' => [['wait' => 1]]])), 'a step is one of'), 'unknown step');
        H::assert(has(F::problems($base(['steps' => []])), 'non-empty'), 'no steps');
        H::assert(has(F::problems($base(['steps' => [['emit' => ['type' => 'a', 'extra' => 1], 'expect' => 'settled']]])), 'unexpected field'), 'event field');
        H::assert(has(F::problems($base(['steps' => [['emit' => ['type' => 'a']]]])), 'expect is'), 'no expectation');
        H::assert(has(F::problems($base(['steps' => [['sample' => ['residue' => [], 'gateCount' => '2']]]])), 'sample is'), 'sample gateCount');
        H::assert(has(F::problems('x'), 'a fixture is an object'), 'not an object');
    });

    H::test('residue matches as a multiset', function (): void {
        $a = [['type' => 'x', 'data' => ['v' => 1]], ['type' => 'y'], ['type' => 'x', 'data' => ['v' => 1]]];
        $b = [new Event('y'), new Event('x', ['v' => 1]), ['type' => 'x', 'data' => ['v' => 1]]];
        $why = 'unset';
        H::assert(F::residueMatch($a, $b, $why), 'same multiset in another order');
        H::assertEqual($why, null, 'why on a match');
        H::assert(!F::residueMatch($a, [$b[0], $b[1]], $why), 'a missing copy');
        H::assert(is_string($why) && str_contains($why, 'residue: expected'), 'why on a mismatch');
        H::assert(!F::residueMatch([['type' => 'x', 'data' => ['v' => 1]]], [new Event('x', ['v' => '1'])]), 'an int and a string differ');
    });

    H::test('canon is keyed, sorted and integers-only', function (): void {
        H::assertEqual(F::canon(['b' => 1, 'a' => [true, null, 'q"']]), '{"a":{0:true,1:null,2:"q\\""},"b":1}');
        H::assertThrows(static fn () => F::canon(1.0), 'float', 'a float');
        H::assertThrows(static fn () => F::canon(new \stdClass()), 'not plain data', 'an object');
    });

    H::test('a trace matches whatever the delivery order', function (): void {
        $expected = [['type' => 'root', 'claimed' => true, 'then' => [
            ['type' => 'a', 'claimed' => true, 'then' => [['type' => 'a1', 'claimed' => false]]],
            ['type' => 'b', 'claimed' => false]]]];
        $bfs = [
            ['eventId' => 1, 'causeId' => null, 'eventType' => 'root', 'claimed' => true],
            ['eventId' => 2, 'causeId' => 1, 'eventType' => 'a', 'claimed' => true],
            ['eventId' => 3, 'causeId' => 1, 'eventType' => 'b', 'claimed' => false],
            ['eventId' => 4, 'causeId' => 2, 'eventType' => 'a1', 'claimed' => false],
        ];
        $dfs = [
            ['eventId' => 1, 'causeId' => null, 'eventType' => 'root', 'claimed' => true],
            ['eventId' => 2, 'causeId' => 1, 'eventType' => 'a', 'claimed' => true],
            ['eventId' => 3, 'causeId' => 2, 'eventType' => 'a1', 'claimed' => false],
            ['eventId' => 4, 'causeId' => 1, 'eventType' => 'b', 'claimed' => false],
        ];
        H::assert(F::traceMatch($expected, $bfs), 'breadth-first');
        H::assert(F::traceMatch($expected, $dfs), 'depth-first');
        $wrong = [$bfs[0], $bfs[1], $bfs[2], ['eventId' => 4, 'causeId' => 3, 'eventType' => 'a1', 'claimed' => false]];
        H::assert(!F::traceMatch($expected, $wrong), 'a1 caused by the wrong delivery');
        H::assertEqual(count(F::forest($bfs)), 1, 'one root');
        H::assertThrows(static fn () => F::forest([['eventId' => 1, 'causeId' => 2, 'eventType' => 'a', 'claimed' => true]]), 'not an earlier record', 'a cause that is not earlier');
    });

    H::test('recordAt finds a delivery by its path of types, in any order', function (): void {
        $recs = [
            ['eventId' => 1, 'causeId' => null, 'eventType' => 'a', 'claimed' => true],
            ['eventId' => 2, 'causeId' => 1, 'eventType' => 'c', 'claimed' => false],
            ['eventId' => 3, 'causeId' => 1, 'eventType' => 'b', 'claimed' => true],
            ['eventId' => 4, 'causeId' => 3, 'eventType' => 'a', 'claimed' => true],
        ];
        $r = F::recordAt($recs, ['a', 'b', 'a']);
        H::assert(is_array($r) && $r['eventId'] === 4, 'the a caused by b');
        $why = null;
        H::assert(F::recordAt($recs, ['a', 'x'], $why) === null, 'no such path');
        H::assertEqual($why, '0 deliveries match the path', 'why');
    });

    H::finish('contract fixture-format');
}
