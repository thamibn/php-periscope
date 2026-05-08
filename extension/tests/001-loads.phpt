--TEST--
Phase 1: extension is registered and loaded
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
var_dump(extension_loaded("periscope"));
?>
--EXPECT--
periscope loaded
bool(true)
