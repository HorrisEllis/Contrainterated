<?php
// Fixture suite for php/sisoTests/kit-run.test.php: a file named fixtures is a suite (only directories are skipped).
declare(strict_types=1);

require_once __DIR__ . '/../../../../code/harness.php';

use Siso\Harness\H;

H::test('one', function (): void {
    H::assert(true);
});
H::finish('fixtures-named');
