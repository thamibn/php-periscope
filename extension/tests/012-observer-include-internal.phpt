--TEST--
Phase 2: periscope.skip_internal=0 surfaces internal calls
(except those PHP specialises into dedicated opcodes — strlen, count, etc.)
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.skip_internal=0
--FILE--
<?php
declare(strict_types=1);

echo array_sum([1, 2, 3]), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter array_sum(array $array = array(3)) @depth=2
[periscope] exit  array_sum: int|float -> int(6) (%fms) @depth=2
6
[periscope] exit  {main} -> int(1) (%fms) @depth=1
