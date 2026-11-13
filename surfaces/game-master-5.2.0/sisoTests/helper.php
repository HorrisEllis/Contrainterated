<?php
/**
 * Per-gate SISO test helper.
 *
 * gate('signature') returns the project's real gate for that
 * signature, built by GameStreamFactory from src/Gates — never
 * a copy.
 *
 * run(Gate, data) registers that gate alone on a fresh core 13
 * stream, enters one event of its signature and takes the residue:
 * only the gate under test runs, nothing downstream fires (no other
 * gate is registered), and what the gate returned is judged by the
 * real core (a throw, a self-loop or a non-event return is a
 * Rejection, and the test fails with it). The whole output comes
 * back as a list of ['type' => ..., 'data' => ...] so a case can
 * assert every event the gate returned.
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../tests/runner.php';

use GameMaster\GameStreamFactory;
use Siso\Core\Event;
use Siso\Core\Gate;
use Siso\Core\Stream;

function gate(string $signature, array $config = []): Gate
{
    return (new GameStreamFactory($config))->gate($signature);
}

/** Transform one event and return the whole output, normalised to a list. */
function run(Gate $gate, array $data = [], ?string $type = null): array
{
    $stream = new Stream();
    $stream->register($gate);
    $stream->emit(new Event($type ?? $gate->signature, $data));
    $list = $stream->takeResidue();
    // Census support: record this case's output shape.
    if (($shapes = getenv('SISO_SHAPES')) !== false && $shapes !== '') {
        file_put_contents($shapes, json_encode([
            'file' => basename($_SERVER['SCRIPT_FILENAME'] ?? ''),
            'case' => \SisoTest\Runner::$current,
            'types' => array_values(array_unique(array_map('shapeOf', $list))),
        ]) . "\n", FILE_APPEND);
    }
    return array_map(fn(Event $e) => ['type' => $e->type, 'data' => $e->data], $list);
}

/**
 * One output event's shape: its type; for boundary requests also the
 * reply type it names; for io_write the resources it writes (a
 * per-zone write counts once, as zone:*); for http_response the status.
 */
function shapeOf(Event $e): string
{
    $d = $e->data;
    return match ($e->type) {
        'io_write' => 'io_write→' . $d['reply'] . '{' . implode(',', array_unique(array_map(
            fn($n) => preg_replace('/:.*/', ':*', (string) $n), array_keys($d['writes'])))) . '}',
        'io_read', 'io_collect', 'ai_call' => "{$e->type}→{$d['reply']}",
        'http_response' => "http_response {$d['status']}",
        default => $e->type,
    };
}

/** Types of an output list, in order. */
function types(array $out): array
{
    return array_map(fn($e) => $e['type'], $out);
}

/** Build a boundary reply payload for io_read results. */
function reads(array $byName): array
{
    $r = [];
    foreach ($byName as $name => $value) {
        $r[$name] = is_string($value) ? ['status' => $value, 'data' => null] : ['status' => 'ok', 'data' => $value];
    }
    return $r;
}
