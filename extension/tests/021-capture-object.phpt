--TEST--
Phase 3: typed objects show class, handle, and property values with visibility
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

final class Box
{
    public function __construct(
        public readonly int $id,
        public string $label,
        protected float $weight,
    ) {}
}

function look(Box $b): string { return $b->label; }
echo look(new Box(7, "abc", 1.5)), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter Box::__construct(int $id = int(7), string $label = string(3) "abc", float $weight = float(1.5)) @depth=2
[periscope] exit  Box::__construct -> null (%fms) @depth=2
[periscope] enter look(Box $b = object(Box)#%d {+ro:id: int(7), +label: string(3) "abc", #weight: float(1.5)}) @depth=2
[periscope] exit  look: string -> string(3) "abc" (%fms) @depth=2
abc
[periscope] exit  {main} -> int(1) (%fms) @depth=1
