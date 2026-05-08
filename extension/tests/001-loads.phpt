--TEST--
Phase 1: extension is registered and loaded
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
var_dump(extension_loaded("periscope"));
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
bool(true)
[periscope] exit  {main} -> int(1) (%fms) @depth=1
