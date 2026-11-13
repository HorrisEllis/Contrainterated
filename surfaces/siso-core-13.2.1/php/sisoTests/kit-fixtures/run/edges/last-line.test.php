<?php
// Fixture suite for php/sisoTests/kit-run.test.php: an earlier failing
// result line, then a passing last one, and exit 0: the last line decides.
echo "earlier: 0/1 passed, 1 FAILED\n";
echo "last-line: 3/3 passed\n";
echo "trailing text that is not a result line\n";
