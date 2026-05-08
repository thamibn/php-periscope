--TEST--
Phase 3: circular object references emit a back-ref token, no infinite loop
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

final class Node
{
    public ?Node $next = null;
}

function visit(Node $n): int { return 1; }

$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
visit($a);
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter visit(Node $n = object(Node)#%d {+next: object(Node)#%d {+next: <recursion ↻ #%d>}}) @depth=2
[periscope] exit  visit: int -> int(1) (%fms) @depth=2
[periscope] exit  {main} -> int(1) (%fms) @depth=1
