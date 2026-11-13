<?php
/**
 * World template data — every template in data/worlds passes the
 * checks the game relies on; the Portland template keeps the
 * shape its gates expect (formerly the data half of
 * tests/Gates/PortlandGatesTest.php, whose path was wrong).
 *
 * Run: php tests/templates.test.php
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/runner.php';

use function SisoTest\{test, assertTrue, assertEqual, assertContains, report};

$dir = __DIR__ . '/../data/worlds';

test('every template is valid JSON with id = file name, theme and tone, and a known starting zone', function () use ($dir) {
    $files = glob("$dir/*.json");
    assertEqual(count($files), 4);
    foreach ($files as $file) {
        $t = json_decode(file_get_contents($file), true);
        $name = basename($file, '.json');
        assertTrue(is_array($t), "{$name}: valid JSON");
        assertEqual($t['id'] ?? null, $name, "{$name}: id");
        assertTrue(!empty($t['theme']) && !empty($t['tone']), "{$name}: theme and tone");
        assertTrue(isset($t['zones'][$t['startingZone']]), "{$name}: starting zone defined");
    }
});

$portland = json_decode(file_get_contents("$dir/portland_scavenger.json"), true);

test('Portland: ten landmarks, each in Portland with a Street View link', function () use ($portland) {
    assertEqual(count($portland['landmarks']), 10);
    foreach ($portland['landmarks'] as $id => $l) {
        assertTrue($l['lat'] > 45 && $l['lat'] < 46, "{$id} lat");
        assertTrue($l['lng'] > -123 && $l['lng'] < -122, "{$id} lng");
        assertContains('google.com/maps', $l['streetview'], "{$id} streetview");
        assertTrue(isset($l['name'], $l['clueIndex']), "{$id} name and clueIndex");
    }
});

test('Portland: the note has ten numbered clues', function () use ($portland) {
    $clues = array_filter($portland['note']['content'], fn($line) => preg_match('/^\d+\./', $line) === 1);
    assertEqual(count($clues), 10);
});

test('Portland: starts at Pioneer Square with a crumpled note and $0', function () use ($portland) {
    assertEqual($portland['startingZone'], 'pioneer_square');
    assertTrue(isset($portland['zones']['pioneer_square']));
    assertTrue(in_array('crumpled note', $portland['playerDefaults']['inventory'], true));
    assertEqual($portland['playerDefaults']['stats']['money'], 0);
});

exit(report('templates') === 0 ? 0 : 1);
