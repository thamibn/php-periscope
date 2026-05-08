--TEST--
Phase 3: long strings are truncated to periscope.max_string with size hint
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.max_string=8
--FILE--
<?php
declare(strict_types=1);

function take(string $s): int { return strlen($s); }
echo take("abcdefghijklmnopqrstuvwxyz"), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter take(string $s = string(26) "abcdefgh…+18") @depth=2
[periscope] exit  take: int -> int(26) (%fms) @depth=2
26
[periscope] exit  {main} -> int(1) (%fms) @depth=1
