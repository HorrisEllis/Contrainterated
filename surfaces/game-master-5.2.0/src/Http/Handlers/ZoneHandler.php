<?php

namespace GameMaster\Http\Handlers;

use GameMaster\Http\Request;
use GameMaster\Http\Response;
use Siso\Core\Event;

/**
 * ZoneHandler — zone endpoints.
 *
 * POST   /api/game/{gameId}/zone/generate     {zoneId, parentZoneId?, direction?, constraints?}
 *        → zone_generate_requested
 * POST   /api/game/{gameId}/zone/pregenerate  {fromZoneId}
 *        → zone_pregenerate_requested
 * GET    /api/game/{gameId}/zone/{zoneId}     → zone_get_requested
 * GET    /api/game/{gameId}/zones             → zone_list_requested
 * DELETE /api/game/{gameId}/zone/{zoneId}     → zone_delete_requested
 */
class ZoneHandler extends StreamEndpoint
{
    public function generate(Request $request, string $gameId): Response
    {
        return $this->respond(new Event('zone_generate_requested', [
            'gameId' => $gameId,
            'zoneId' => $request->input('zoneId'),
            'parentZoneId' => $request->input('parentZoneId'),
            'direction' => $request->input('direction'),
            'constraints' => $request->input('constraints', []),
            'now' => self::now(),
        ]), 'ZoneHandler::generate');
    }

    public function pregenerate(Request $request, string $gameId): Response
    {
        return $this->respond(new Event('zone_pregenerate_requested', [
            'gameId' => $gameId,
            'fromZoneId' => $request->input('fromZoneId'),
            'now' => self::now(),
        ]), 'ZoneHandler::pregenerate');
    }

    public function get(Request $request, string $gameId, string $zoneId): Response
    {
        return $this->respond(new Event('zone_get_requested', ['gameId' => $gameId, 'zoneId' => $zoneId]), 'ZoneHandler::get');
    }

    public function list(Request $request, string $gameId): Response
    {
        return $this->respond(new Event('zone_list_requested', ['gameId' => $gameId]), 'ZoneHandler::list');
    }

    public function delete(Request $request, string $gameId, string $zoneId): Response
    {
        return $this->respond(new Event('zone_delete_requested', ['gameId' => $gameId, 'zoneId' => $zoneId]), 'ZoneHandler::delete');
    }

    // ── Test helpers ────────────────────────────────

    public function generateZone(string $gameId, string $zoneId, ?string $parentZoneId = null, ?string $direction = null): array
    {
        return self::decode($this->generate(Request::fromArray(['method' => 'POST',
            'body' => ['zoneId' => $zoneId, 'parentZoneId' => $parentZoneId, 'direction' => $direction]]), $gameId));
    }

    public function pregenerateFrom(string $gameId, string $fromZoneId): array
    {
        return self::decode($this->pregenerate(Request::fromArray(['method' => 'POST', 'body' => ['fromZoneId' => $fromZoneId]]), $gameId));
    }

    public function getZone(string $gameId, string $zoneId): array
    {
        return self::decode($this->get(Request::fromArray(['method' => 'GET']), $gameId, $zoneId));
    }

    public function listZones(string $gameId): array
    {
        return self::decode($this->list(Request::fromArray(['method' => 'GET']), $gameId));
    }
}
