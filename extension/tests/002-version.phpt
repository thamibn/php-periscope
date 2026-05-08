--TEST--
Phase 1: phpversion() returns the extension version string
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
$v = phpversion("periscope");
var_dump(is_string($v) && strlen($v) > 0);
?>
--EXPECT--
periscope loaded
bool(true)
