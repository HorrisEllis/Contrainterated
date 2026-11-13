<?php

namespace GameMaster\Boundary;

use GameMaster\Pure\Ids;

/**
 * FileStore — the JSON files behind io_read / io_write.
 *
 * Boundary code: file I/O only. Resource specs (see
 * GameMaster\Events\Io) name a kind and its ids; the store maps
 * them to paths:
 *
 *   save        {savesPath}/game-{gameId}.json
 *   template    {worldTemplatesPath}/{templateId}.json
 *   templates   {worldTemplatesPath}/*.json      (read only)
 *   zone        {zonesPath}/{gameId}/{zoneId}.json
 *   zone_index  {zonesPath}/{gameId}/_index.json
 *
 * Ids outside [A-Za-z0-9_-]{1,100} never reach the filesystem:
 * reads answer 'invalid', writes fail. The zone id '_index' is
 * reserved for the index file. Gates validate ids first;
 * this is the second line of defence against path traversal.
 */
final class FileStore
{
    public function __construct(
        private readonly string $savesPath,
        private readonly string $worldTemplatesPath,
        private readonly string $zonesPath,
    ) {
    }

    /** @return array{status: string, data: mixed} */
    public function read(array $spec): array
    {
        if (($spec['kind'] ?? '') === 'templates') {
            return $this->readCatalog();
        }
        $path = $this->path($spec);
        if ($path === null) {
            return ['status' => 'invalid', 'data' => null];
        }
        if (!is_file($path)) {
            return ['status' => 'missing', 'data' => null];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return ['status' => 'unreadable', 'data' => null];
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return ['status' => 'corrupt', 'data' => null];
        }
        return ['status' => 'ok', 'data' => $data];
    }

    /** @return array{ok: bool} */
    public function write(array $spec): array
    {
        $path = $this->path($spec);
        if ($path === null) {
            return ['ok' => false];
        }
        if (($spec['op'] ?? 'put') === 'delete') {
            return ['ok' => is_file($path) && @unlink($path)];
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false];
        }
        $json = json_encode($spec['content'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ['ok' => $json !== false && @file_put_contents($path, $json, LOCK_EX) !== false];
    }

    private function readCatalog(): array
    {
        if (!is_dir($this->worldTemplatesPath)) {
            return ['status' => 'missing', 'data' => null];
        }
        $catalog = [];
        foreach (glob("{$this->worldTemplatesPath}/*.json") ?: [] as $file) {
            $content = @file_get_contents($file);
            $data = $content === false ? null : json_decode($content, true);
            if (is_array($data)) {
                $catalog[basename($file, '.json')] = $data;
            }
        }
        return ['status' => 'ok', 'data' => $catalog];
    }

    private function path(array $spec): ?string
    {
        $id = fn(string $key) => Ids::isValid($spec[$key] ?? null) ? $spec[$key] : null;

        switch ($spec['kind'] ?? '') {
            case 'save':
                $g = $id('gameId');
                return $g === null ? null : "{$this->savesPath}/game-{$g}.json";
            case 'template':
                $t = $id('templateId');
                return $t === null ? null : "{$this->worldTemplatesPath}/{$t}.json";
            case 'zone':
                $g = $id('gameId');
                $z = $id('zoneId');
                if ($z === '_index') {
                    return null;   // reserved: the zone index lives beside the zones
                }
                return $g === null || $z === null ? null : "{$this->zonesPath}/{$g}/{$z}.json";
            case 'zone_index':
                $g = $id('gameId');
                return $g === null ? null : "{$this->zonesPath}/{$g}/_index.json";
            default:
                return null;
        }
    }
}
