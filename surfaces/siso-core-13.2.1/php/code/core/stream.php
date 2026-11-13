<?php
/*
core/stream.php — Siso\Core\Stream, the place where events propagate.

Contracts: core-api (the doors, ids, rejections, ownership) and
observer-seam (what an observer is shown), core 13.0.0, PHP instance.
Port of lua/code/core/stream.lua.

  require_once __DIR__ . '/stream.php';
  use Siso\Core\Stream;
  $s = new Stream();       no gates, no residue, no observers; it takes
                           no options (an argument is ignored)
  $s->register($gate)      building; a duplicate signature throws
                           \LogicException("Signature collision: '<sig>'")
  $s->emit($event)         entry; one propagation until it settles,
                           returns nothing, or throws a Rejection
  $s->takeResidue()        take; → the residue list, stream emptied
  $s->sampleHere()         sample; → ['residue' => <the sampler's own
                           copies>, 'gateCount' => <registered gates>]
  $s->attach($fn) / ->detach($fn)   observe; an observer is a \Closure

The stream's state (gates, residue, observers) is private: the doors are
the only public surface (Lua R5 vanishes, core-api SUBSTRATE).

Delivery is an iterative FIFO queue (breadth-first): a gate's outputs
join the back of the queue, so no call-stack depth is spent and chain
length is bounded by acyclicity alone. The order is an implementation
choice (K1), not semantics. Each queue entry carries its id, cause id and
depth beside the event (K4), in parallel arrays local to emit; none of
them is ever put on the event.

Ids are per propagation: each emit numbers its deliveries 1, 2, … in a
local, so nothing is counted across propagations and no id outlives the
propagation except in a rejection or an observer's batch.

A gate's return is judged before anything it returned is queued:
  null             consumed
  an Event         one follow-up
  an array         members judged in key order 0, 1, … while the key
                   exists; the first that is not an Event (SISO_NOT_EVENT)
                   or is the gate's own type (SISO_SELF_LOOP) decides;
                   then keys that are not exactly 0..n-1 are SISO_NOT_EVENT
  anything else    SISO_NOT_EVENT
Nothing a gate returns is dropped or repaired (Drift 7): it is carried or
the propagation is rejected. The stream queues exactly the members judge
counted (SPEC X1's fix, applied here: judge hands back the list it
judged, not the value it was given).

Read-only events: Event makes every event read-only from construction,
so the stream queues a returned event as it is (keeping its identity);
nothing here freezes.

Ownership (core-api OWNERSHIP, observer-seam RECORD data and THE
REJECTION): entry takes the stream's own copy of the event
(Freeze::copy), so nothing the entering code holds is in the residue. A
take hands the residue over. A sample's residue holds Freeze::copy of
each event (one memo per sample); each observer's records carry
Freeze::copyData of the data (one memo per observer), and each observer's
rejection is Freeze::plain of the one thrown. Inside the propagation
nothing is copied: a gate shares its input with the stream. No copy is
made for observers when none is attached.

Release (core-api SUBSTRATE): what a gate returned is released (its last
reference dropped) inside the stream's protection, right after it is
judged. A throw while releasing it (an object's __destruct, a started
generator's finally) adds nothing: the rejection the return was judged to
be stands, and emit throws only that rejection. The propagation ends in
one place: a rejection is held in a local, the observers are called from
emit's own frame (no frame passes the observers list, the records or the
rejection as arguments while user code runs), and only then is it thrown.
An observer's thrown value, its batch and the observers list are released
under protection too, so an observer's release cannot reach emit.

Rejections (K5) commit nothing: residue is collected in a local list and
joins the stream's only when the propagation settles. The stream is
exactly as it was before the entry. An entry the stream cannot copy is
SISO_NOT_EVENT ("an Event too deep to copy"); nothing else is thrown.

Observers (observer-seam): the observers attached when the entry arrives
are each called once, after the propagation ended (settled: after the
residue is committed; rejected: just before the rejection is thrown),
each with its own batch. Every observer's batch is built, each under its
own protection, before any observer is called: a batch that cannot be
built fails that observer alone, and nothing an observer does (taking
the residue and writing to it included) reaches another's batch. An
observer that throws (any \Throwable) fails alone; emit returns or throws
exactly as with no observer.

No static state: no static property, no method with `static` variables.
*/
declare(strict_types=1);

namespace Siso\Core;

require_once __DIR__ . '/event.php';
require_once __DIR__ . '/gate.php';
require_once __DIR__ . '/rejection.php';
require_once __DIR__ . '/freeze.php';

final class Stream
{
    /** @var array<string, Gate> signature → gate */
    private array $gates = [];
    /** @var list<Event> */
    private array $residue = [];
    /** @var list<\Closure> */
    private array $observers = [];

    public function __construct()
    {
    }

    public function register(Gate $gate): void
    {
        $sig = $gate->signature;
        if (array_key_exists($sig, $this->gates)) {
            throw new \LogicException("Signature collision: '" . $sig . "'");
        }
        $this->gates[$sig] = $gate;
    }

    public function emit(mixed $event): void
    {
        $observers = $this->observers;
        $keepRecords = $observers !== [];
        $records = [];
        $rejection = null;
        $settled = [];

        if (!$event instanceof Event) {
            $rejection = Rejection::notEvent(null, null, self::describe($event));
        } else {
            // the stream's own copy: nothing the entering code keeps reaches it
            // ($event stays the entering code's own; the copy is a local)
            $entry = null;
            try {
                $memo = [];
                $entry = Freeze::copy($event, $memo);
            } catch (\Throwable) {
                $rejection = Rejection::notEvent(null, null, 'an Event too deep to copy');
            }
            unset($memo);
        }

        if ($rejection === null) {
            $gates = $this->gates;
            $gateCount = count($gates);
            $qEvent = [$entry];
            $qCause = [null];
            $qDepth = [1];
            unset($entry);
            $head = 0;
            $tail = 1;
            $lastId = 0;

            while ($head < $tail) {
                $current = $qEvent[$head];
                $causeId = $qCause[$head];
                $depth = $qDepth[$head];
                unset($qEvent[$head], $qCause[$head], $qDepth[$head]);
                $head++;
                $lastId++;
                $type = $current->type;
                $gate = $gates[$type] ?? null;

                if ($keepRecords) {
                    $records[] = [
                        'eventId' => $lastId,
                        'causeId' => $causeId,
                        'eventType' => $type,
                        'claimed' => $gate !== null,
                        'data' => $current->data,
                    ];
                }

                if ($gate === null) {
                    $settled[] = $current;
                    continue;
                }
                $delivery = ['id' => $lastId, 'causeId' => $causeId, 'type' => $type, 'depth' => $depth];
                if ($depth > $gateCount) {
                    $rejection = Rejection::cycle($delivery, $gateCount);
                    break;
                }
                $given = $current;
                $result = null;
                try {
                    $result = ($gate->transform)($given);
                } catch (\Throwable $thrown) {
                    $rejection = Rejection::gateThrew($delivery, $gate->signature, $thrown);
                    unset($thrown);
                    break;
                } finally {
                    unset($given);
                }
                [$outputs, $rejection] = self::judge($result, $gate->signature, $delivery);
                // Release: what the gate returned is dropped here, inside the
                // stream's protection; a throw while releasing it (a
                // __destruct, a started generator's finally) adds nothing
                self::release($result);
                if ($rejection !== null) {
                    break;
                }
                foreach ($outputs as $out) {
                    $qEvent[$tail] = $out;
                    $qCause[$tail] = $lastId;
                    $qDepth[$tail] = $depth + 1;
                    $tail++;
                }
            }
            unset($qEvent, $qCause, $qDepth, $current, $gate, $outputs, $out, $gates);
        }

        if ($rejection === null) {
            foreach ($settled as $e) {
                $this->residue[] = $e;
            }
        } else {
            $settled = [];
        }

        // observers (observer-seam): called from this frame, with locals;
        // every batch is built, each under its own protection, before any
        // observer is called; each call, and each release after it, is
        // protected
        if ($observers !== []) {
            $outcome = $rejection === null ? 'settled' : 'rejected';
            $batches = [];
            foreach ($observers as $i => $_) {
                try {
                    $batches[$i] = self::batchFor($outcome, $rejection, $records);
                } catch (\Throwable) {
                }
            }
            $records = [];
            foreach ($observers as $i => $observer) {
                if (!array_key_exists($i, $batches)) {
                    continue;
                }
                $caught = null;
                try {
                    $observer($batches[$i]);
                } catch (\Throwable $caught) {
                }
                self::release($caught);
                self::release($batches[$i]);
            }
            self::release($observer);
            self::release($observers);
        }

        if ($rejection !== null) {
            throw $rejection;
        }
    }

    public function takeResidue(): array
    {
        $taken = $this->residue;
        $this->residue = [];
        return $taken;
    }

    public function sampleHere(): array
    {
        $copy = [];
        $memo = [];
        foreach ($this->residue as $e) {
            $copy[] = Freeze::copy($e, $memo);
        }
        return ['residue' => $copy, 'gateCount' => count($this->gates)];
    }

    public function attach(mixed $observer): void
    {
        if (!$observer instanceof \Closure) {
            throw new \LogicException('attach: an observer is a function, got ' . get_debug_type($observer));
        }
        foreach ($this->observers as $o) {
            if ($o === $observer) {
                throw new \LogicException('attach: this observer is already attached');
            }
        }
        $this->observers[] = $observer;
    }

    public function detach(mixed $observer): void
    {
        foreach ($this->observers as $i => $o) {
            if ($o === $observer) {
                unset($this->observers[$i]);
                $this->observers = array_values($this->observers);
                return;
            }
        }
        throw new \LogicException('detach: this observer is not attached');
    }

    // ── the stream's own ───────────────────────────

    /** a short name for a value that is not an Event, for a rejection message */
    private static function describe(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'the bool ' . ($v ? 'true' : 'false');
        }
        if (is_int($v) || is_float($v)) {
            return 'the ' . get_debug_type($v) . ' ' . var_export($v, true);
        }
        if (is_string($v)) {
            return 'a string';
        }
        if (is_array($v)) {
            return 'an array that is not an Event';
        }
        if (is_object($v)) {
            return 'a ' . get_debug_type($v) . ' that is not an Event';
        }
        return 'a ' . get_debug_type($v);
    }

    /**
     * judge($result, $signature, $delivery) → [outputs, null] where outputs
     * is the list of Events judged, or [[], the rejection the return decides].
     */
    private static function judge(mixed $result, string $signature, array $delivery): array
    {
        if ($result === null) {
            return [[], null];
        }
        if ($result instanceof Event) {
            if ($result->type === $signature) {
                return [[], Rejection::selfLoop($delivery, $signature)];
            }
            return [[$result], null];
        }
        if (!is_array($result)) {
            return [[], Rejection::notEvent($delivery, $signature, self::describe($result))];
        }
        $members = [];
        $n = 0;
        while (array_key_exists($n, $result)) {
            $member = $result[$n];
            $n++;
            if (!$member instanceof Event) {
                return [[], Rejection::notEvent($delivery, $signature,
                    'a list whose member ' . $n . ' is ' . self::describe($member))];
            }
            if ($member->type === $signature) {
                return [[], Rejection::selfLoop($delivery, $signature)];
            }
            $members[] = $member;
        }
        if (count($result) !== $n) {
            return [[], Rejection::notEvent($delivery, $signature,
                'an array whose keys are not exactly 0..' . ($n - 1) . ' (a hole or a named key)')];
        }
        return [$members, null];
    }

    /**
     * One observer's batch: its own records with its own copies of the
     * data (one memo per observer), and its own plain copy of the
     * rejection.
     */
    private static function batchFor(string $outcome, ?Rejection $rejection, array $records): array
    {
        $own = [];
        $memo = [];
        foreach ($records as $r) {
            $own[] = [
                'eventId' => $r['eventId'],
                'causeId' => $r['causeId'],
                'eventType' => $r['eventType'],
                'claimed' => $r['claimed'],
                'data' => Freeze::copyData($r['data'], $memo),
            ];
        }
        return [
            'outcome' => $outcome,
            'rejection' => $rejection === null ? null : Freeze::plain($rejection),
            'records' => $own,
        ];
    }

    /**
     * Drops $v (its last reference, when this is one) inside protection: a
     * throw while releasing it (a __destruct, a generator's finally), or
     * while releasing what that throw raised, adds nothing.
     */
    private static function release(mixed &$v): void
    {
        $caught = null;
        try {
            $v = null;
        } catch (\Throwable $caught) {
        }
        while ($caught !== null) {
            try {
                $caught = null;
            } catch (\Throwable $caught) {
            }
        }
    }
}
