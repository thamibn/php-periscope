--TEST--
Phase 1: phpinfo() includes the periscope section
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
var_dump(strpos($info, "periscope support") !== false);
var_dump(strpos($info, "enabled") !== false);
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
bool(true)
bool(true)
[periscope] exit  {main} -> int(1) (%fms) @depth=1
