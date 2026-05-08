--TEST--
Phase 2: internal functions are skipped by default (periscope.skip_internal=1)
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.skip_internal=1
--FILE--
<?php
declare(strict_types=1);

$total = array_sum([1, 2, 3]);
echo $total, "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
6
[periscope] exit  {main} -> int(1) (%fms) @depth=1
