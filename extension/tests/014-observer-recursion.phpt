--TEST--
Phase 2: recursion depth is tracked correctly across nested calls
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

function down(int $n): int
{
    if ($n <= 0) {
        return 0;
    }
    return down($n - 1) + 1;
}

echo down(3), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter down(int $n = int(3)) @depth=2
[periscope] enter down(int $n = int(2)) @depth=3
[periscope] enter down(int $n = int(1)) @depth=4
[periscope] enter down(int $n = int(0)) @depth=5
[periscope] exit  down: int -> int(0) (%fms) @depth=5
[periscope] exit  down: int -> int(1) (%fms) @depth=4
[periscope] exit  down: int -> int(2) (%fms) @depth=3
[periscope] exit  down: int -> int(3) (%fms) @depth=2
3
[periscope] exit  {main} -> int(1) (%fms) @depth=1
