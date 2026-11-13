<?php
// Fixture suite for php/sisoTests/kit-run.test.php: one test fails, so the
// harness reports "1/2 passed, 1 FAILED" and exits 1.
declare(strict_types=1);

require_once __DIR__ . '/../../../../code/harness.php';

use Siso\Harness\H;

H::test('passes', function (): void {
    H::assert(true);
});
H::test('fails', function (): void {
    H::assertEqual(1, 2, 'fixture');
});
H::finish('assert-failed');
