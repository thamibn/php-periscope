--TEST--
Phase 2: observer logs user-defined function entry and exit
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

function add(int $a, int $b): int {
    return $a + $b;
}

echo add(2, 3), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter add(int $a = int(2), int $b = int(3)) @depth=2
[periscope] exit  add: int -> int(5) (%fms) @depth=2
5
[periscope] exit  {main} -> int(1) (%fms) @depth=1
