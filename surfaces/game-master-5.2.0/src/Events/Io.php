<?php

namespace GameMaster\Events;

use Siso\Core\Event;

/**
 * Io — constructors for the events that cross the boundary.
 *
 * Gates never perform I/O. When a gate needs something from
 * outside the stream (a file, the AI, the HTTP client), it
 * returns one of these request events. No gate is registered
 * for them, so they settle as residue; the boundary
 * (GameMaster\Boundary\Driver) takes the residue after each
 * propagation, performs the I/O, and enters the reply event the
 * request names.
 *
 * Request types (no gate; served by the boundary):
 *   io_read     {reads, reply, carry}
 *   io_write    {writes, reply, carry}
 *   io_collect  {types, where, key, expect, reads, reply, carry}
 *               — a join: served once the driver has taken, for every value
 *               in `expect`, an event of one of `types` whose data
 *               matches `where` and has data[key] = that value;
 *               replies with those events plus fresh reads
 *   ai_call     {system, user, reply, carry}
 *   http_response {status, body}                — terminal; the answer
 *
 * Reply event data = carry + the result under a reserved key:
 *   io_read    → reads:     {name: {status, data}}
 *                status: ok | missing | corrupt | unreadable | invalid
 *   io_write   → writes:    {name: {ok}}, at: ISO-8601 time of the write
 *   io_collect → collected: [{type, data}], reads: {...}
 *   ai_call    → ai:        {ok, content, usage: {input, output}, model,
 *                            error: {code, message} | null}
 *
 * `carry` is the continuation: what the reply's gate needs besides
 * the result. Gates keep it to exactly that.
 */
final class Io
{
    // ── Request events ──────────────────────────────────────────

    public static function read(array $reads, string $reply, array $carry = []): Event
    {
        return new Event('io_read', ['reads' => $reads, 'reply' => $reply, 'carry' => $carry]);
    }

    public static function write(array $writes, string $reply, array $carry = []): Event
    {
        return new Event('io_write', ['writes' => $writes, 'reply' => $reply, 'carry' => $carry]);
    }

    /**
     * A join: ready when, for every value in $expect, a settled event of
     * one of $types exists whose data matches $where and whose data[$key]
     * is that value.
     */
    public static function collect(
        array $types,
        array $reads,
        string $reply,
        array $carry = [],
        array $where = [],
        string $key = '',
        array $expect = []
    ): Event {
        return new Event('io_collect', [
            'types' => $types, 'where' => $where, 'key' => $key, 'expect' => $expect,
            'reads' => $reads, 'reply' => $reply, 'carry' => $carry,
        ]);
    }

    public static function ai(string $system, string $user, string $reply, array $carry = []): Event
    {
        return new Event('ai_call', ['system' => $system, 'user' => $user, 'reply' => $reply, 'carry' => $carry]);
    }

    public static function http(int $status, array $body): Event
    {
        return new Event('http_response', ['status' => $status, 'body' => $body]);
    }

    // ── Resource specs (what to read or write) ──────────────────

    public static function save(string $gameId): array
    {
        return ['kind' => 'save', 'gameId' => $gameId];
    }

    public static function template(string $templateId): array
    {
        return ['kind' => 'template', 'templateId' => $templateId];
    }

    /** Every world template, keyed by id (file name). */
    public static function templates(): array
    {
        return ['kind' => 'templates'];
    }

    public static function zone(string $gameId, string $zoneId): array
    {
        return ['kind' => 'zone', 'gameId' => $gameId, 'zoneId' => $zoneId];
    }

    public static function zoneIndex(string $gameId): array
    {
        return ['kind' => 'zone_index', 'gameId' => $gameId];
    }

    public static function put(array $spec, array $content): array
    {
        return $spec + ['op' => 'put', 'content' => $content];
    }

    public static function delete(array $spec): array
    {
        return $spec + ['op' => 'delete'];
    }
}
