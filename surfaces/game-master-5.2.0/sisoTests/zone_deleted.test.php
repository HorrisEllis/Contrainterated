<?php
/** ZoneDeletedGate — zone_deleted → http_response 200. */

require_once __DIR__ . '/helper.php';

use function SisoTest\{test, assertEqual, report};

$g = gate('zone_deleted');

test('accepted: deleted (whether or not an index was rewritten)', function () use ($g) {
    $ok = [['type' => 'http_response', 'data' => ['status' => 200, 'body' => ['success' => true, 'message' => 'Success', 'data' => ['deleted' => 'cave']]]]];
    assertEqual(run($g, ['zoneId' => 'cave', 'writes' => ['index' => ['ok' => true]], 'at' => 'T']), $ok);
    assertEqual(run($g, ['zoneId' => 'cave', 'writes' => [], 'at' => 'T']), $ok);
});

exit(report('zone_deleted') === 0 ? 0 : 1);
