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
--EXPECT--
periscope loaded
int(0)
