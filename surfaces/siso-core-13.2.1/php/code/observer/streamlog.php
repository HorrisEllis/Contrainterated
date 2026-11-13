<?php
/*
observer/streamlog — StreamLog, the audit trail, on the observer seam
(core 13, PHP instance). Port of lua/code/observer/streamlog.lua.

A log is an observer (SISO principles, "The log — not a primitive"). The
stream shows it one batch per propagation, after the propagation has ended
(php/code/contracts/observer_seam.php):
  ['outcome' => 'settled' | 'rejected', 'rejection' => array | null,
   'records' => [['eventId', 'causeId', 'eventType', 'claimed', 'data'], …]]
The log stores what it is shown and reads nothing back into any stream.
Nothing here is consulted by a gate, a sample or any running part.

Everything the log keeps is its own (observer-side): entries, seq,
timestamps, level, stream labels, lineage and propagation numbers, all
instance properties of one StreamLog. There is no static state: two logs
share nothing. It requires nothing from php/code/core/.

API
  require_once __DIR__ . '/…/observer/streamlog.php';
  use Siso\Observer\StreamLog;

  $log = new StreamLog($level);
      $level is 'OFF' | 'EVENTS' | 'DEEP' | 'DATA'; omitted or null means
      'EVENTS'. Any other value throws \InvalidArgumentException
      "StreamLog: unknown level '…' (OFF, EVENTS, DEEP, DATA)" (X2).

  $obs = $log->observer($label, $parentLabel = null);
      → the \Closure to pass to $stream->attach($obs) (and ->detach).
      $label       a non-empty string naming the observed stream in this
                   log; a label already used in this log throws.
      $parentLabel optional (null: none); the label of the stream this one
                   is a sub-stream of. It must already be a label of this
                   log, else it throws (lineage names only streams the log
                   knows).
      Each call of $obs is one propagation of that stream. The observer
      numbers them 1, 2, … (its own propagation number, counted for every
      batch it is shown, whatever the level).
      Misuse throws \InvalidArgumentException with Lua's messages.

  $log->setLevel($level)  change the level; unknown levels throw (X2), and
                          the level is kept. The following batch respects it.
  $log->level()           → the current level.
  $log->sample()          → ['level' => …, 'count' => …, 'entries' => [entry, …]].
                            Arrays are values, so the sample is the
                            sampler's own: nothing done to it (by-reference
                            writes included) reaches the log. `data`, when
                            present, is the array the stream showed this
                            observer; Events in it are read-only and kept by
                            identity, as Lua keeps the read-only table.
  $log->clear()           drop all entries and reset seq to 0. Labels and
                          propagation numbers are kept, so a propagation is
                          never numbered twice.

LEVELS (each includes everything below)
  OFF     nothing is recorded
  EVENTS  per delivery: seq (0, 1, … per log, in the order the log was
          shown records), time (time(), seconds, an int), propagation,
          outcome ('settled' | 'rejected'), eventId, causeId (null for the
          entry delivery), type, claimed (bool, as the seam shows it)
  DEEP    + streamLabel, and parentLabel when the stream has a parent
  DATA    + data
  An entry holds exactly the keys of its level: a key below its level is
  absent, not null (PHP's counterpart of Lua's absent field); causeId is
  an EVENTS key, present and null for the entry delivery.

IDENTITY AND CAUSALITY
  The stream numbers deliveries 1, 2, … afresh in every propagation, so an
  eventId alone names nothing. Within one stream (label) a delivery is
  (propagation, eventId); across streams sharing a log it is
  (streamLabel, propagation, eventId), with streamLabel recorded at DEEP.
  causeId names a delivery of the same propagation: walking causeId links
  within one (streamLabel, propagation) reconstructs a causal chain,
  whatever order entries were written in. A rejected propagation's
  entries end with the delivery the rejection names (rejection eventId).

SUBSTRATE (PHP)
  - Lua's copyEntry in sample() vanishes: entries are arrays, values, so
    returning them is copying them.
  - Lua's per-observer upvalue counter becomes the log's own
    propagations-by-label array (instance state, kept across clear()).
  - Labels are PHP array keys only as lookups; a numeric-string label is
    compared as a string, and a parentLabel must be a string.
*/
declare(strict_types=1);

namespace Siso\Observer;

final class StreamLog
{
    /** level name => rank (a constant, not state) */
    public const LEVELS = ['OFF' => 0, 'EVENTS' => 1, 'DEEP' => 2, 'DATA' => 3];

    private string $level;
    private int $rank;
    /** @var list<array<string, mixed>> */
    private array $entries = [];
    private int $seq = 0;
    /** label => propagations shown so far (a label is known once it is here) */
    private array $propagations = [];

    public function __construct(mixed $level = null)
    {
        $level ??= 'EVENTS';
        $this->rank = self::rank($level);
        $this->level = $level;
    }

    private static function render(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if ($v === null) {
            return 'null';
        }
        if (is_scalar($v)) {
            return var_export($v, true);
        }
        return get_debug_type($v);
    }

    private static function rank(mixed $level): int
    {
        if (!is_string($level) || !array_key_exists($level, self::LEVELS)) {
            throw new \InvalidArgumentException("StreamLog: unknown level '" . self::render($level) . "' (OFF, EVENTS, DEEP, DATA)");
        }
        return self::LEVELS[$level];
    }

    public function level(): string
    {
        return $this->level;
    }

    public function setLevel(mixed $level): void
    {
        $this->rank = self::rank($level);
        $this->level = $level;
    }

    public function observer(mixed $label, mixed $parentLabel = null): \Closure
    {
        if (!is_string($label) || $label === '') {
            throw new \InvalidArgumentException('StreamLog: a stream label is a non-empty string');
        }
        if (array_key_exists($label, $this->propagations)) {
            throw new \InvalidArgumentException("StreamLog: label '" . $label . "' is already used");
        }
        if ($parentLabel !== null && (!is_string($parentLabel) || !array_key_exists($parentLabel, $this->propagations))) {
            throw new \InvalidArgumentException("StreamLog: parent label '" . self::render($parentLabel) . "' is not a label of this log");
        }
        $this->propagations[$label] = 0;
        return function (array $batch) use ($label, $parentLabel): void {
            $propagation = ++$this->propagations[$label];
            if ($this->rank === 0) {
                return;
            }
            foreach ($batch['records'] as $r) {
                $e = [
                    'seq' => $this->seq, 'time' => time(), 'propagation' => $propagation, 'outcome' => $batch['outcome'],
                    'eventId' => $r['eventId'], 'causeId' => $r['causeId'], 'type' => $r['eventType'], 'claimed' => $r['claimed'],
                ];
                if ($this->rank >= 2) {
                    $e['streamLabel'] = $label;
                    if ($parentLabel !== null) {
                        $e['parentLabel'] = $parentLabel;
                    }
                }
                if ($this->rank >= 3) {
                    $e['data'] = $r['data'];
                }
                $this->seq++;
                $this->entries[] = $e;
            }
        };
    }

    public function sample(): array
    {
        return ['level' => $this->level, 'count' => count($this->entries), 'entries' => $this->entries];
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->seq = 0;
    }
}
