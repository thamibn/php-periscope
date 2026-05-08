--TEST--
Phase 3: arrays expand to 1 level with key/value pairs
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

function take(array $a): int { return count($a); }
echo take(['name' => 'thami', 'age' => 30]), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter take(array $a = array(2) ["name": string(5) "thami", "age": int(30)]) @depth=2
[periscope] exit  take: int -> int(2) (%fms) @depth=2
2
[periscope] exit  {main} -> int(1) (%fms) @depth=1
