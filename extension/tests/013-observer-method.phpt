--TEST--
Phase 2: class methods print Class::method with declared param/return types
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

final class Calc
{
    public function double(int $n): int
    {
        return $n * 2;
    }
}

echo (new Calc())->double(21), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter Calc::double(int $n = int(21)) @depth=2
[periscope] exit  Calc::double: int -> int(42) (%fms) @depth=2
42
[periscope] exit  {main} -> int(1) (%fms) @depth=1
