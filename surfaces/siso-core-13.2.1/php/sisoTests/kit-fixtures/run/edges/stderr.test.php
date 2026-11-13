<?php
// Fixture suite for php/sisoTests/kit-run.test.php: the result line goes to
// stderr, which run.php reads joined to stdout.
fwrite(STDERR, "stderr: 2/2 passed\n");
