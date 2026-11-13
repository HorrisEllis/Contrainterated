<?php
/**
 * The vendored SISO core is the release, unchanged.
 *
 * doc/siso-core-13.1.0.sha256 lists every file copied from the core
 * 13.1.0 release (VERSION, LICENSE, fixtures/, js/code, js/sisoTests,
 * php/code, php/sisoTests) with its SHA-256, taken from the release
 * zip. This suite checks that siso/ holds exactly those files with
 * exactly those hashes, and that siso/VERSION says 13.1.0. Upgrading
 * the core means replacing siso/ and the pin file together.
 *
 * What this proves alone: siso/ matches the pin. Editing a vendored
 * file and its pin line together would pass. To prove siso/ is the
 * release itself, point SISO_RELEASE at an unpacked release's core/
 * directory: every pinned file is then also compared byte for byte
 * with the release (and the case is skipped when the variable is unset).
 *
 * Run: php tests/siso-vendor.test.php
 *      SISO_RELEASE=/path/to/core-13.1.0/core php tests/siso-vendor.test.php
 */

require_once __DIR__ . '/runner.php';

use function SisoTest\{test, assertTrue, assertEqual, report};

const SISO_DIR = __DIR__ . '/../siso';
const PIN_FILE = __DIR__ . '/../doc/siso-core-13.1.0.sha256';

function pinned(): array
{
    $pins = [];
    foreach (file(PIN_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $m)) {
            throw new \RuntimeException("pin file: bad line '{$line}'");
        }
        $pins[$m[2]] = $m[1];
    }
    return $pins;
}

function onDisk(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SISO_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files[] = substr($f->getPathname(), strlen(SISO_DIR) + 1);
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

test('the pin file lists the release (126 files)', function () {
    assertEqual(count(pinned()), 126);
});

test('siso/ holds exactly the pinned files', function () {
    $pinned = array_keys(pinned());
    sort($pinned, SORT_STRING);
    $disk = onDisk();
    assertEqual(array_values(array_diff($disk, $pinned)), [], 'files in siso/ that are not pinned');
    assertEqual(array_values(array_diff($pinned, $disk)), [], 'pinned files missing from siso/');
});

test('every vendored file matches its pinned SHA-256', function () {
    $changed = [];
    foreach (pinned() as $path => $hash) {
        $file = SISO_DIR . '/' . $path;
        if (is_file($file) && hash_file('sha256', $file) !== $hash) {
            $changed[] = $path;
        }
    }
    assertEqual($changed, [], 'vendored files that differ from the release');
});

test('siso/VERSION is 13.1.0', function () {
    assertEqual(trim(file_get_contents(SISO_DIR . '/VERSION')), '13.1.0');
});

test('the back end loads the vendored core, not another copy', function () {
    $autoload = file_get_contents(__DIR__ . '/../autoload.php');
    foreach (['core/event.php', 'core/gate.php', 'core/rejection.php', 'core/stream.php', 'observer/streamlog.php'] as $f) {
        assertTrue(str_contains($autoload, "/siso/php/code/{$f}"), "autoload.php requires siso/php/code/{$f}");
    }
    assertTrue(!is_dir(__DIR__ . '/../src/Siso'), 'the old src/Siso port is gone');
});

test('with SISO_RELEASE: every pinned file equals the release byte for byte', function () {
    $release = getenv('SISO_RELEASE');
    if ($release === false || $release === '') {
        echo "  (skipped: SISO_RELEASE not set)\n";
        return;
    }
    $differ = [];
    foreach (array_keys(pinned()) as $path) {
        $theirs = rtrim($release, '/') . '/' . $path;
        if (!is_file($theirs) || hash_file('sha256', $theirs) !== hash_file('sha256', SISO_DIR . '/' . $path)) {
            $differ[] = $path;
        }
    }
    assertEqual($differ, [], 'files that differ from the release at SISO_RELEASE');
});

report('siso-vendor');
