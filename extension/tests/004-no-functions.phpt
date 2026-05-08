--TEST--
Phase 1: extension exposes no userland functions yet
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
$ref = new ReflectionExtension("periscope");
$fns = $ref->getFunctions();
var_dump(count($fns));
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
int(0)
[periscope] exit  {main} -> int(1) (%fms) @depth=1
