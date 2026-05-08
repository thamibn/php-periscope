--TEST--
Phase 3: deep nested arrays are truncated at periscope.max_depth
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.max_depth=2
--FILE--
<?php
declare(strict_types=1);

function take(array $a): int { return count($a, COUNT_RECURSIVE); }
$deep = ['l1' => ['l2' => ['l3' => ['l4' => 'leaf']]]];
echo take($deep), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter take(array $a = array(1) ["l1": array(1) ["l2": array(1) <…depth>]]) @depth=2
[periscope] exit  take: int -> int(%d) (%fms) @depth=2
%d
[periscope] exit  {main} -> int(1) (%fms) @depth=1
