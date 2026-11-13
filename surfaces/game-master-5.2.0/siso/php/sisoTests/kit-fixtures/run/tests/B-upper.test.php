<?php
// Fixture suite for php/sisoTests/kit-run.test.php: runs before a-lower.test.php (byte-wise order: 'B' < 'a').
declare(strict_types=1);

require_once __DIR__ . '/../../../../code/harness.php';

use Siso\Harness\H;

H::test('one', function (): void {
    H::assert(true);
});
H::finish('B-upper');
