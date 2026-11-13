<?php

namespace GameMaster\Boundary;

use Siso\Core\Event;
use Siso\Core\Stream;

/**
 * Driver — the boundary between the stream and the world.
 *
 * It enters one event, then serves the I/O the gates ask for.
 * Gates ask by returning request events that no gate claims
 * (io_read, io_write, ai_call, io_collect — see
 * GameMaster\Events\Io); those settle as residue. After every
 * propagation the driver takes the residue (core 13's take door),
 * performs each request, and enters the reply event the request
 * names. That reply causes the next gate. It stops when nothing is
 * left to serve and returns every other event it took (normally
 * one http_response).
 *
 * The driver decides nothing about the game. It knows how to
 * read and write files, call the AI, and join: an io_collect
 * names the events it waits for (types, matching fields, and one
 * event per expected key value); it is served as soon as the
 * driver has taken them all, and replies with them plus fresh
 * reads. A join whose events never arrive is left unserved, and
 * the run ends without an answer (the handler reports a 500).
 *
 * Boundary state (conformance B9): within one run the driver holds what
 * it has taken — requests still to serve, joins still waiting and
 * the events that settled — in locals of run(). Nothing outlives
 * the run, and nothing it holds is visible to a gate. A rejected
 * propagation (a gate threw, looped, or returned something that is
 * not an event) is thrown on to the caller as the core's Rejection.
 */
final class Driver
{
    private const REQUESTS = ['io_read', 'io_write', 'ai_call'];

    /** @var \Closure(): string */
    private \Closure $clock;

    public function __construct(
        private readonly Stream $stream,
        private readonly FileStore $store,
        private readonly AiTransport $ai,
        ?\Closure $clock = null,
        private readonly int $maxRequests = 1000,
    ) {
        $this->clock = $clock ?? fn(): string => date('c');
    }

    /** @return Event[] the events taken that are not requests (e.g. http_response) */
    public function run(Event $entry): array
    {
        $requests = [];
        $collects = [];
        $settled = [];
        $served = 0;

        $this->enter($entry, $requests, $collects, $settled);

        while (true) {
            if ($requests !== []) {
                $request = array_shift($requests);
                if (++$served > $this->maxRequests) {
                    throw new \RuntimeException("Driver served {$this->maxRequests} requests without settling");
                }
                $this->enter($this->serve($request), $requests, $collects, $settled);
                continue;
            }

            foreach ($collects as $i => $collect) {
                $matched = $this->joined($collect, $settled);
                if ($matched !== null) {
                    unset($collects[$i]);
                    $this->enter($this->collect($collect, $matched), $requests, $collects, $settled);
                    continue 2;
                }
            }

            return $settled;
        }
    }

    /** Entry door, then take: one propagation, and what it left sorted by kind. */
    private function enter(Event $event, array &$requests, array &$collects, array &$settled): void
    {
        $this->stream->emit($event);
        foreach ($this->stream->takeResidue() as $taken) {
            if (in_array($taken->type, self::REQUESTS, true)) {
                $requests[] = $taken;
            } elseif ($taken->type === 'io_collect') {
                $collects[] = $taken;
            } else {
                $settled[] = $taken;
            }
        }
    }

    private function serve(Event $request): Event
    {
        $d = $request->data;
        switch ($request->type) {
            case 'io_read':
                $result = ['reads' => $this->readAll($d['reads'] ?? [])];
                break;
            case 'io_write':
                $writes = [];
                foreach ($d['writes'] ?? [] as $name => $spec) {
                    $writes[$name] = $this->store->write($spec);
                }
                $result = ['writes' => $writes, 'at' => ($this->clock)()];
                break;
            case 'ai_call':
                $result = ['ai' => $this->ai->call($d)];
                break;
            default:
                throw new \LogicException("Not a request: {$request->type}");
        }
        return new Event($d['reply'], array_merge($d['carry'] ?? [], $result));
    }

    /**
     * The events a join waits for, or null while any is missing.
     *
     * @param Event[] $settled
     * @return Event[]|null
     */
    private function joined(Event $collect, array $settled): ?array
    {
        $d = $collect->data;
        $types = $d['types'] ?? [];
        $where = $d['where'] ?? [];
        $key = $d['key'] ?? '';
        $matches = [];
        foreach ($settled as $event) {
            if (!in_array($event->type, $types, true)) {
                continue;
            }
            foreach ($where as $field => $value) {
                if (($event->data[$field] ?? null) !== $value) {
                    continue 2;
                }
            }
            if ($key !== '' && !in_array($event->data[$key] ?? null, $d['expect'] ?? [], true)) {
                continue;
            }
            $matches[] = $event;
        }
        if ($key !== '') {
            $found = array_map(fn(Event $e) => $e->data[$key], $matches);
            foreach ($d['expect'] ?? [] as $value) {
                if (!in_array($value, $found, true)) {
                    return null;
                }
            }
        }
        return $matches;
    }

    /** @param Event[] $matched */
    private function collect(Event $request, array $matched): Event
    {
        $d = $request->data;
        return new Event($d['reply'], array_merge($d['carry'] ?? [], [
            'collected' => array_map(fn(Event $e) => ['type' => $e->type, 'data' => $e->data], $matched),
            'reads' => $this->readAll($d['reads'] ?? []),
        ]));
    }

    private function readAll(array $reads): array
    {
        $out = [];
        foreach ($reads as $name => $spec) {
            $out[$name] = $this->store->read($spec);
        }
        return $out;
    }
}
