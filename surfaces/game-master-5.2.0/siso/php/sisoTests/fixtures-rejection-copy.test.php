<?php
/*
fixtures-rejection-copy.test.php — the runner (php/code/fixtures/run.php)
judges each batch's rejection with observer-seam's rejectionCopyProblem,
not by identity (core 13, THE REJECTION). Port of
lua/sisoTests/fixtures-rejection-copy.test.lua, case by case.
  php php/sisoTests/fixtures-rejection-copy.test.php   (from the project root)

It must hold whatever the core in the workspace shows its observers, so
the fixtures run on a test-local wrapper over Siso\Core\Stream (handed to
the runner as its Stream factory) that decides what its observers are
shown: it collects the real stream's batches, lets emit return or throw,
then hands each wrapped observer the batch with a rejection built from
the one thrown (a plain copy, the thrown object itself, or a faulty
copy), and rethrows the same object. The copy it shows is plain, as THE
REJECTION requires since core 13.0.1: an array holding the thrown one's
non-null public fields, a `cause` as an array of its public properties,
and no object anywhere in it.

Turned into PHP's own counterpart (observer-seam SUBSTRATE: the read-only
copy becomes value ownership, and "getmetatable answers false" becomes
"no object in the copy but fresh Events and enum cases"):
  - "accepted …": Lua checks the shown copy refuses assignment and
    answers getmetatable with false; here each shown copy is an array (a
    value) that rejectionCopyProblem accepts, so it holds no object.
  - "rejected: a copy that accepts assignment": an array always accepts
    assignment, changing only itself; what would let a write reach
    something else is an object in the copy, so the faulty copy holds
    one (its message as a stdClass), refused as "not plain data".
  - "rejected: the raised table itself": an object is not an array, so
    besides rejectionCopyProblem's "very object raised", observer-seam's
    batchProblems also names the batch ("a rejected batch carries no
    rejection"); both are the same fault, and both are allowed.
  - The inline fixture also pins a copy whose cause is the very
    \Throwable the gate threw (an object of the thrown one's): refused.
No case is inapplicable.
*/
declare(strict_types=1);

namespace Siso\Tests\FixturesRejectionCopy;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/observer_seam.php';
require_once __DIR__ . '/../code/core/event.php';
require_once __DIR__ . '/../code/core/gate.php';
require_once __DIR__ . '/../code/core/stream.php';
require_once __DIR__ . '/../code/kit/data.php';
require_once __DIR__ . '/../code/fixtures/run.php';

use Siso\Contracts\ObserverSeam as SEAM;
use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Fixtures\Runner;
use Siso\Harness\H;
use Siso\Kit\Data;

function joined(array $list): string
{
    return implode('; ', $list);
}

// ── what an observer may be shown ───────────────────────────────

/** a plain copy of a value: arrays copied, Events fresh, enum cases kept, other objects as their public properties */
function plainValue(mixed $v, array $seen = []): mixed
{
    if (is_array($v)) {
        $t = [];
        foreach ($v as $k => $x) {
            $t[$k] = plainValue($x, $seen);
        }
        return $t;
    }
    if (!is_object($v) || $v instanceof \UnitEnum) {
        return $v;
    }
    if ($v instanceof Event) {
        return new Event($v->type, plainValue($v->data, $seen));
    }
    $id = spl_object_id($v);
    if (isset($seen[$id])) {
        return null;
    }
    $seen[$id] = true;
    return plainValue(get_object_vars($v), $seen);
}

/** the observer's copy (observer-seam THE REJECTION): the thrown one's non-null public fields */
function plainCopy(object $raised): array
{
    $c = [];
    foreach (get_object_vars($raised) as $k => $v) {
        if ($v !== null) {
            $c[$k] = plainValue($v);
        }
    }
    return $c;
}

function shows(): array
{
    return [
        'copy' => static fn (object $raised): mixed => plainCopy($raised),
        'itself' => static fn (object $raised): mixed => $raised,
        'changedMessage' => static function (object $raised): mixed {
            $c = plainCopy($raised);
            $c['message'] = $c['message'] . ' (changed)';
            return $c;
        },
        'holdsObject' => static function (object $raised): mixed {
            $c = plainCopy($raised);
            $c['message'] = (object)['text' => $c['message']];
            return $c;
        },
        'causeItself' => static function (object $raised): mixed {
            $c = plainCopy($raised);
            if (isset($c['cause'])) {
                $c['cause'] = get_object_vars($raised)['cause'];
            }
            return $c;
        },
    ];
}

/** a stream over Siso\Core\Stream whose observers are shown $show(thrown) as the rejection */
final class Wrapped
{
    private Stream $real;
    /** @var list<array> the real stream's batches for the entry in progress */
    private array $pending = [];
    /** @var list<\Closure> */
    private array $observers = [];

    public function __construct(private \Closure $show, private ?\Closure $onShown = null)
    {
        $this->real = new Stream();
        $this->real->attach(function (array $b): void {
            $this->pending[] = $b;
        });
    }

    public function register(Gate $g): void
    {
        $this->real->register($g);
    }

    public function takeResidue(): array
    {
        return $this->real->takeResidue();
    }

    public function sampleHere(): array
    {
        return $this->real->sampleHere();
    }

    public function attach(\Closure $f): void
    {
        $this->observers[] = $f;
    }

    public function detach(\Closure $f): void
    {
        foreach ($this->observers as $i => $g) {
            if ($g === $f) {
                unset($this->observers[$i]);
                $this->observers = array_values($this->observers);
                return;
            }
        }
        throw new \LogicException('detach: this observer is not attached');
    }

    public function emit(mixed $e): void
    {
        $this->pending = [];
        $err = null;
        try {
            $this->real->emit($e);
        } catch (\Throwable $t) {
            $err = $t;
        }
        foreach ($this->pending as $b) {
            foreach ($this->observers as $f) {
                $shown = ['outcome' => $b['outcome'], 'rejection' => null, 'records' => $b['records']];
                if ($b['outcome'] === 'rejected' && $err !== null) {
                    $shown['rejection'] = ($this->show)($err);
                    if ($this->onShown !== null) {
                        ($this->onShown)($shown['rejection'], $err);
                    }
                }
                $f($shown);
            }
        }
        if ($err !== null) {
            throw $err;
        }
    }
}

/** the runner's $core with a Stream factory making Wrapped streams */
function wrapped(\Closure $show, ?\Closure $onShown = null): array
{
    return ['Stream' => static fn (): Wrapped => new Wrapped($show, $onShown)];
}

// ── the suite on disk ───────────────────────────────────────────

const DIR = __DIR__ . '/../../fixtures';

$files = [];
foreach (is_dir(DIR) ? (scandir(DIR) ?: []) : [] as $name) {
    if (str_ends_with($name, '.json')) {
        $files[] = $name;
    }
}
sort($files, SORT_STRING);

$decoded = [];
foreach ($files as $name) {
    $why = null;
    $text = file_get_contents(DIR . '/' . $name);
    $v = Data::decode($text === false ? '' : $text, $why);
    if ($why !== null || !is_array($v)) {
        throw new \RuntimeException($name . ': ' . ($why ?? 'not a fixture'));
    }
    $decoded[$name] = $v;
}

/** the indices (counted from 1) of a fixture's steps that expect a rejection */
function rejectedSteps(array $f): array
{
    $list = [];
    foreach (is_array($f['steps'] ?? null) ? $f['steps'] : [] as $i => $st) {
        if (is_array($st['expect'] ?? null) && array_key_exists('rejected', $st['expect'])) {
            $list[] = $i + 1;
        }
    }
    return $list;
}

$totalRejected = 0;
foreach ($decoded as $f) {
    $totalRejected += count(rejectedSteps($f));
}

/** every rejected step fails with $needles[0], every other step passes, and every problem holds one of $needles */
function failsEachRejection(array $decoded, \Closure $show, array $needles): array
{
    $bad = [];
    $holds = static function (string $s) use ($needles): bool {
        foreach ($needles as $n) {
            if (str_contains($s, $n)) {
                return true;
            }
        }
        return false;
    };
    foreach ($decoded as $name => $f) {
        $rej = rejectedSteps($f);
        $r = Runner::run($f, wrapped($show));
        if ($r['passed'] !== $r['total'] - count($rej)) {
            $bad[] = $name . ': ' . $r['passed'] . '/' . $r['total'] . ' passed, expected ' . ($r['total'] - count($rej));
        }
        foreach ($rej as $i) {
            $found = false;
            foreach ($r['problems'] as $s) {
                if (str_starts_with($s, 'steps[' . $i . ']') && str_contains($s, $needles[0])) {
                    $found = true;
                }
            }
            if (!$found) {
                $bad[] = $name . ' step ' . $i . ": no '" . $needles[0] . "' in " . joined($r['problems']);
            }
        }
        foreach ($r['problems'] as $s) {
            if (!$holds($s)) {
                $bad[] = $name . ': unrelated problem ' . $s;
            }
        }
    }
    return $bad;
}

H::test('the suite has rejections to judge', function () use ($files, $totalRejected): void {
    H::assert(count($files) >= 15, 'fixtures/ holds ' . count($files) . ' files');
    H::assert($totalRejected > 0, 'no fixture step expects a rejection');
});

H::test('accepted: every fixture passes when observers are shown a plain copy', function () use ($decoded, $totalRejected): void {
    $seen = [];
    $bad = [];
    $onShown = static function (mixed $shown, \Throwable $raised) use (&$seen): void {
        $seen[] = ['shown' => $shown, 'raised' => $raised];
    };
    foreach ($decoded as $name => $f) {
        $r = Runner::run($f, wrapped(shows()['copy'], $onShown));
        if ($r['passed'] !== $r['total'] || $r['problems'] !== []) {
            $bad[] = $name . ' ' . $r['passed'] . '/' . $r['total'] . ': ' . joined($r['problems']);
        }
    }
    H::assert($bad === [], joined($bad));
    // the runner was really shown copies, one per rejected step
    H::assertEqual(count($seen), $totalRejected, 'rejections shown');
    foreach ($seen as $x) {
        H::assert(is_array($x['shown']), 'the wrapper showed ' . get_debug_type($x['shown']));
        $why = SEAM::rejectionCopyProblem($x['shown'], $x['raised'], Event::class);
        H::assert($why === null, "the wrapper's copy: " . $why);
    }
});

H::test('rejected: a batch carrying the thrown object itself fails each rejected step', function () use ($decoded): void {
    $bad = failsEachRejection($decoded, shows()['itself'], ['very object raised', 'a rejected batch carries no rejection']);
    H::assert($bad === [], joined($bad));
});

H::test('rejected: a copy whose message differs fails each rejected step', function () use ($decoded): void {
    $bad = failsEachRejection($decoded, shows()['changedMessage'], ["differs from the one raised at 'message'"]);
    H::assert($bad === [], joined($bad));
});

H::test('rejected: a copy holding an object fails each rejected step', function () use ($decoded): void {
    $bad = failsEachRejection($decoded, shows()['holdsObject'], ['not plain data']);
    H::assert($bad === [], joined($bad));
});

H::test('an inline fixture: the copy is accepted, the thrown object and its cause refused', function (): void {
    $f = [
        'format' => 1, 'name' => 'inline', 'covers' => ['K5'],
        'gates' => [['signature' => 'a', 'does' => ['emitOne' => ['type' => 'b']]], ['signature' => 'b', 'does' => ['throw' => 'b failed']]],
        'steps' => [['emit' => ['type' => 'a'], 'expect' => ['rejected' => ['code' => 'SISO_GATE_THREW', 'cause' => 'b failed', 'at' => ['a', 'b']]]]],
    ];
    $r = Runner::run($f, wrapped(shows()['copy']));
    H::assert($r['passed'] === 1 && $r['problems'] === [], joined($r['problems']));
    $r = Runner::run($f, wrapped(shows()['itself']));
    H::assert($r['passed'] === 0, 'the thrown object itself was accepted');
    $r = Runner::run($f, wrapped(shows()['causeItself']));
    H::assert($r['passed'] === 0, 'a copy holding the thrown cause itself was accepted');
    H::assert(str_contains(joined($r['problems']), 'raised object itself'), joined($r['problems']));
});

H::finish('fixtures-rejection-copy');
