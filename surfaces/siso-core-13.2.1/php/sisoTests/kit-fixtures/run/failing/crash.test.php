<?php
// Fixture suite for php/sisoTests/kit-run.test.php: a fatal error before
// any result line (exit 255); run.php shows the output.
declare(strict_types=1);

echo "about to crash\n";
\Siso\Tests\KitRunFixture\noSuchFunction();
