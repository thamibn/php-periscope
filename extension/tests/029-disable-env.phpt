--TEST--
Phase 3: PERISCOPE_DISABLE=1 env silences all observation (kill switch)
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--ENV--
PERISCOPE_DISABLE=1
--FILE--
<?php
declare(strict_types=1);

function noisy(): int { return 7; }
echo noisy(), "\n";
?>
--EXPECT--
periscope loaded
7
