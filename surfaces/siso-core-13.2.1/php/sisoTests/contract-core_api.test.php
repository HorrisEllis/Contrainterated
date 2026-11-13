<?php
// contract test: core-api (php/code/contracts/core_api.php).
// Shows the contract's checks pass on an implementation that honours it
// and catch each departure 11.1, 12.0 and 13.0.0-rc1 had, and the PHP
// departures a check exists for. The core itself is checked against the
// contract by the stream phases' tests.
declare(strict_types=1);

namespace Siso\Tests\ContractCoreApi;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/core_api.php';
require_once __DIR__ . '/contract-fake-core.php';

use Siso\Contracts\CoreApi as C;
use Siso\Harness\H;
use Siso\Tests\Fake\Fake;
use Siso\Tests\Fake\Rejection;

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

H::test('doors hold on an implementation that honours the contract', function (): void {
    $p = C::checkDoors(Fake::core());
    H::assert($p === [], joined($p));
});

H::test('read-only holds on an implementation that honours the contract', function (): void {
    $p = C::checkReadonly(Fake::core());
    H::assert($p === [], joined($p));
});

H::test('11.1 X1: skipping non-Event outputs is caught', function (): void {
    $p = C::checkDoors(Fake::core('skipNonEvents'));
    H::assert(has($p, 'output not an Event'), joined($p));
});

H::test('11.1: an entry door that takes any value is caught', function (): void {
    $p = C::checkDoors(Fake::core('acceptAnyEntry'));
    H::assert(has($p, 'entry not an Event'), joined($p));
});

H::test('11.1 R1: ids counted across propagations are caught', function (): void {
    $p = C::checkDoors(Fake::core('countIds'));
    H::assert(has($p, 'gate threw, again'), joined($p));
});

H::test('11.1 R1: an event counter in the sample is caught', function (): void {
    $p = C::checkDoors(Fake::core('sampleCount'));
    H::assert(has($p, 'sampleHere carries'), joined($p));
});

H::test('11.1 R3: writable events are caught', function (): void {
    $p = C::checkReadonly(Fake::core('noFreeze'));
    H::assert(has($p, 'was accepted'), joined($p));
});

H::test('12.0: an event writable until it enters the stream is caught', function (): void {
    $p = C::checkReadonly(Fake::core('writableAtBirth'));
    H::assert(has($p, 'a new event'), joined($p));
    H::assert(has($p, 'array passed to Event'), joined($p));
});

H::test('12.0.1 and 13.0.0-rc1: events that reach writable static state are caught', function (): void {
    $p = C::checkReadonly(Fake::core('tableMeta'));
    H::assert(has($p, 'static state'), joined($p));
    H::assert(has($p, 'carried a count'), joined($p));
});

H::test("13.0.0-rc1: an entry that keeps the entering code's own event is caught", function (): void {
    $p = C::checkOwnership(Fake::core('entryAlias'));
    H::assert(has($p, "entering code's own event"), joined($p));
});

H::test('ownership holds on an implementation that honours the contract', function (): void {
    $p = C::checkOwnership(Fake::core());
    H::assert($p === [], joined($p));
});

H::test("12.0 X6: samples that share the stream's objects are caught", function (): void {
    $p = C::checkOwnership(Fake::core('shareFrozen'));
    H::assert(has($p, 'reached the stream'), joined($p));
});

H::test('PHP: an object in event data is caught', function (): void {
    $p = C::checkReadonly(Fake::core('objectData'));
    H::assert(has($p, 'Event accepted a stdClass'), joined($p));
});

H::test('PHP: data that keeps the caller\'s reference slots is caught', function (): void {
    $p = C::checkReadonly(Fake::core('keepReferences'));
    H::assert(has($p, 'array passed to Event'), joined($p));
    H::assert(has($p, "a write to a copy of a new event's data reached the event"), joined($p));
});

H::test('PHP: an unserialize that skips validation is caught', function (): void {
    $p = C::checkReadonly(Fake::core('forgeable'));
    H::assert(has($p, 'unserialize built an event'), joined($p));
});

H::test('each switch leaves the doors check it does not target passing', function (): void {
    foreach (['noFreeze', 'tableMeta', 'entryAlias', 'shareFrozen', 'objectData', 'keepReferences', 'forgeable', 'writableAtBirth'] as $sw) {
        $p = C::checkDoors(Fake::core($sw));
        H::assert($p === [], $sw . ': ' . joined($p));
    }
});

H::test('rejectionProblems: a well-formed rejection passes', function (): void {
    $r = new Rejection('SISO_CYCLE', 'm', 3, 2, 'a', 3);
    H::assertEqual(C::rejectionProblems($r, ['code' => 'SISO_CYCLE', 'depth' => 3]), []);
    $t = new \RuntimeException('x');
    H::assertEqual(C::rejectionProblems(new Rejection('SISO_GATE_THREW', 'm', 1, null, 'a', null, $t), ['cause' => $t, 'entry' => true]), []);
    H::assertEqual(C::rejectionProblems(new Rejection('SISO_NOT_EVENT', 'm'), ['noDelivery' => true]), []);
});

H::test('rejectionProblems: shape errors are named', function (): void {
    $noString = new class ('m') extends \RuntimeException {
        public $code = 'SISO_CYCLE';
        public $message = 'm';
        public $eventId = 1;
        public $causeId = null;
        public $eventType = 'a';
        public $depth = 1;
        public $cause = null;
    };
    H::assert(has(C::rejectionProblems($noString), '(string)'), 'a rejection whose (string) is not its message');
    H::assert(has(C::rejectionProblems(new Rejection('X', 'm')), 'unknown code'), 'unknown code');
    $extra = new class ('m') extends \RuntimeException {
        public $code = 'SISO_SELF_LOOP';
        public $message = 'm';
        public $eventId = null;
        public $causeId = null;
        public $eventType = null;
        public $depth = null;
        public $cause = null;
        public $extra = 1;

        public function __toString(): string
        {
            return $this->message;
        }
    };
    H::assert(has(C::rejectionProblems($extra), 'unexpected field'), 'extra field');
    $missing = new class ('m') extends \RuntimeException {
        public $code = 'SISO_SELF_LOOP';
        public $message = 'm';

        public function __toString(): string
        {
            return $this->message;
        }
    };
    H::assert(has(C::rejectionProblems($missing), "missing field 'eventId'"), 'missing field');
    H::assert(has(C::rejectionProblems(new Rejection('SISO_SELF_LOOP', 'm', depth: 2)), 'only by SISO_CYCLE'), 'depth off a cycle');
    H::assert(has(C::rejectionProblems(new Rejection('SISO_CYCLE', 'm', 1)), 'integer depth'), 'a cycle without depth');
    H::assert(has(C::rejectionProblems(new Rejection('SISO_NOT_EVENT', 'm', 1), ['noDelivery' => true]), 'names no delivery'), 'refused entry with an id');
    H::assert(has(C::rejectionProblems(new Rejection('SISO_SELF_LOOP', 'm', 1, 1), ['entry' => true]), 'no cause'), 'entry with a cause');
    H::assert(has(C::rejectionProblems(new Rejection('SISO_GATE_THREW', 'm', 1, null, 'a', null, 'boom')), 'carries the \Throwable'), 'a cause that is not a Throwable');
    H::assert(has(C::rejectionProblems(new Rejection('SISO_CYCLE', 'm', 3, 2, 'a', 3), ['eventId' => 2]), 'eventId: expected 2, got 3'), 'a pinned field');
    H::assert(has(C::rejectionProblems('boom'), 'is an object'), 'a string');
});

H::finish('contract core-api');
