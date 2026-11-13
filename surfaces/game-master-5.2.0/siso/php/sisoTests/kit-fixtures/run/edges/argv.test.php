<?php
// Fixture suite for php/sisoTests/kit-run.test.php: each suite runs in its
// own process, given only its own path: no class another suite loaded (the
// harness, run.php's own code) is present.
$alone = count($argv) === 1 && str_ends_with($argv[0], 'edges/argv.test.php')
    && !class_exists('Siso\Harness\H', false) && get_included_files() === [realpath(__FILE__)];
echo 'argv: ' . ($alone ? '1' : '0') . "/1 passed\n";
