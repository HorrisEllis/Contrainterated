<?php
// Fixture suite for php/sisoTests/kit-run.test.php: two passing tests (a separate process reports 2/2, not a running total).
declare(strict_types=1);

require_once __DIR__ . '/../../../../code/harness.php';

use Siso\Harness\H;

H::test('one', function (): void {
    H::assertEqual(1 + 1, 2);
});
H::test('two', function (): void {
    H::assert(true);
});
H::finish('a-lower');
