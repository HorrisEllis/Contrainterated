<?php
// contract test: observer-seam (php/code/contracts/observer_seam.php).
// Shows the contract's checks pass on an implementation that honours it
// and catch 11.1's observer (called during the propagation) and the
// other departures the contract rules out.
declare(strict_types=1);

namespace Siso\Tests\ContractObserverSeam;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/contract-fake-core.php';

use Siso\Contracts\ObserverSeam as C;
use Siso\Harness\H;
use Siso\Tests\Fake\Event;
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

/** a thrown value with public fields of its own */
final class CauseProbe extends \RuntimeException
{
    public string $x = 'x';
    public array $n = [1];
    public mixed $e = null;
}

H::test('the seam holds on an implementation that honours it', function (): void {
    $p = C::checkSeam(Fake::core());
    H::assert($p === [], joined($p));
});

H::test('11.1: an observer called during the propagation is caught', function (): void {
    $p = C::checkSeam(Fake::core('observeDuring'));
    H::assert(has($p, 'crossings reached the propagation'), joined($p));
    H::assert(has($p, 'batches'), joined($p));
});

H::test("11.1: an observer's throw escaping emit is caught", function (): void {
    $p = C::checkSeam(Fake::core('unprotected'));
    H::assert(has($p, 'raising observer'), joined($p));
});

H::test('one batch variable handed to every observer is caught', function (): void {
    $p = C::checkSeam(Fake::core('sharedBatch'));
    H::assert(has($p, 'reached another observer'), joined($p));
});

H::test('11.1 R1: ids counted across propagations break the batch', function (): void {
    $p = C::checkSeam(Fake::core('countIds'));
    H::assert(has($p, 'eventId is'), joined($p));
});

H::test('ownership holds on an implementation that honours it', function (): void {
    $p = C::checkOwnership(Fake::core());
    H::assert($p === [], joined($p));
});

H::test("12.0 X6: records that share the residue's objects are caught", function (): void {
    $p = C::checkOwnership(Fake::core('shareFrozen'));
    H::assert(has($p, 'reached the residue'), joined($p));
    H::assert(has($p, 'another observer'), joined($p));
});

H::test('12.0 C-2: observers shown the very rejection raised are caught', function (): void {
    $f = Fake::core('sharedRejection');
    $p = C::checkSeam($f);
    H::assert(has($p, 'very object raised'), joined($p));
    $p = C::checkOwnership($f);
    H::assert(has($p, 'reached the rejection emit raised'), joined($p));
});

H::test("13.0.0: a rejection copy that is an object of the host's class is caught", function (): void {
    $f = Fake::core('richRejection');
    $p = C::checkSeam($f);
    H::assert(has($p, 'not plain data'), joined($p));
    $p = C::checkOwnership($f);
    H::assert(has($p, 'through its copy'), joined($p));
});

H::test('PHP: a rejection copy whose cause is the thrown object is caught', function (): void {
    $f = Fake::core('shallowRejection');
    $p = C::checkSeam($f);
    H::assert(has($p, 'raised object itself'), joined($p));
    $p = C::checkOwnership($f);
    H::assert(has($p, 'reached what the gate raised'), joined($p));
});

H::test('rejectionCopyProblem: a copy, the same object, a changed field', function (): void {
    $raised = new Rejection('SISO_GATE_THREW', 'm', 1, null, 'a', null, new CauseProbe('c'));
    $good = ['code' => 'SISO_GATE_THREW', 'message' => 'm', 'eventId' => 1, 'eventType' => 'a', 'cause' => ['x' => 'x', 'n' => [1], 'e' => null]];
    H::assertEqual(C::rejectionCopyProblem($good, $raised), null);
    H::assert(str_contains((string)C::rejectionCopyProblem($raised, $raised), 'very object'), 'the same object');
    $off = $good;
    $off['eventId'] = 2;
    H::assert(str_contains((string)C::rejectionCopyProblem($off, $raised), 'eventId'), 'an unequal field');
    $deep = $good;
    $deep['cause']['n'] = [2];
    H::assert(str_contains((string)C::rejectionCopyProblem($deep, $raised), 'cause'), 'an unequal cause');
    $extra = $good;
    $extra['depth'] = 3;
    H::assert(str_contains((string)C::rejectionCopyProblem($extra, $raised), 'lacks'), 'an added field');
    $lost = $good;
    unset($lost['eventType']);
    H::assert(str_contains((string)C::rejectionCopyProblem($lost, $raised), 'eventType'), 'a missing field');
    $object = $good;
    $object['cause'] = (object)['x' => 'x', 'n' => [1], 'e' => null];
    H::assert(str_contains((string)C::rejectionCopyProblem($object, $raised), 'not plain data'), 'a copy holding an object');
    $rich = new Rejection('SISO_GATE_THREW', 'm', 1, null, 'a', null, ['x' => 'x', 'n' => [1], 'e' => null]);
    H::assert(str_contains((string)C::rejectionCopyProblem($rich, $raised), 'not plain data'), 'a copy that is a rejection object');
    $sharedCause = $good;
    $sharedCause['cause'] = $raised->cause;
    H::assert(str_contains((string)C::rejectionCopyProblem($sharedCause, $raised), 'raised object itself'), 'a copy sharing the raised cause');
});

H::test('rejectionCopyProblem: an Event in the cause is copied fresh', function (): void {
    $cause = new CauseProbe('c');
    $cause->e = new Event('in', ['w' => 1]);
    $raised = new Rejection('SISO_GATE_THREW', 'm', 1, null, 'a', null, $cause);
    $fresh = ['code' => 'SISO_GATE_THREW', 'message' => 'm', 'eventId' => 1, 'eventType' => 'a',
        'cause' => ['x' => 'x', 'n' => [1], 'e' => new Event('in', ['w' => 1])]];
    H::assertEqual(C::rejectionCopyProblem($fresh, $raised, Event::class), null);
    $shared = $fresh;
    $shared['cause']['e'] = $cause->e;
    H::assert(str_contains((string)C::rejectionCopyProblem($shared, $raised, Event::class), 'raised object itself'), 'the raised Event itself');
    H::assert(str_contains((string)C::rejectionCopyProblem($fresh, $raised), 'not plain data'), 'an Event when no Event class is named');
});

H::test('batchProblems: a consistent batch passes', function (): void {
    $b = ['outcome' => 'settled', 'rejection' => null, 'records' => [
        ['eventId' => 1, 'causeId' => null, 'eventType' => 'a', 'claimed' => true, 'data' => []],
        ['eventId' => 2, 'causeId' => 1, 'eventType' => 'b', 'claimed' => false, 'data' => []],
    ]];
    H::assertEqual(C::batchProblems($b), []);
    $r = ['outcome' => 'rejected', 'rejection' => ['code' => 'SISO_SELF_LOOP', 'message' => 'm', 'eventId' => 1, 'eventType' => 'a'], 'records' => [
        ['eventId' => 1, 'causeId' => null, 'eventType' => 'a', 'claimed' => true, 'data' => []],
    ]];
    H::assertEqual(C::batchProblems($r), []);
});

H::test('batchProblems: inconsistencies are named', function (): void {
    $rec = static fn (int $id, ?int $cause, bool $claimed): array => ['eventId' => $id, 'causeId' => $cause, 'eventType' => 't', 'claimed' => $claimed, 'data' => []];
    $settled = static fn (array $records): array => ['outcome' => 'settled', 'rejection' => null, 'records' => $records];
    H::assert(has(C::batchProblems($settled([$rec(1, null, true), $rec(3, 1, false)])), 'eventId is 3'), 'gap in ids');
    H::assert(has(C::batchProblems($settled([$rec(1, null, false), $rec(2, 1, false)])), 'not claimed'), 'cause unclaimed');
    H::assert(has(C::batchProblems($settled([$rec(1, 1, true)])), 'has a cause'), 'entry with cause');
    H::assert(has(C::batchProblems(['outcome' => 'rejected', 'rejection' => null, 'records' => []]), 'carries no rejection'), 'rejected without rejection');
    H::assert(has(C::batchProblems(['outcome' => 'rejected', 'rejection' => ['eventId' => 2], 'records' => [$rec(1, null, true)]]), 'do not end'), 'records past the rejection');
    H::assert(has(C::batchProblems(['outcome' => 'settled', 'rejection' => null, 'records' => [], 'extra' => 1]), 'unexpected field'), 'extra field');
    H::assert(has(C::batchProblems(['outcome' => 'settled', 'records' => []]), "missing field 'rejection'"), 'missing field');
    H::assert(has(C::batchProblems($settled([1 => $rec(1, null, true)])), 'holes or named keys'), 'records not a list');
    H::assert(has(C::batchProblems((object)['outcome' => 'settled']), 'is an array'), 'an object');
});

H::finish('contract observer-seam');
