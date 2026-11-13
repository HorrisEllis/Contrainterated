<?php
// Fixture suite for php/sisoTests/kit-run.test.php: a suite at depth is found.
declare(strict_types=1);

require_once __DIR__ . '/../../../../../../code/harness.php';

use Siso\Harness\H;

H::test('one', function (): void {
    H::assert(true);
});
H::finish('inner');
