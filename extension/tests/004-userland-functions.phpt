--TEST--
Phase 1+5a: extension exposes periscope_record_event + periscope_checkpoint userland functions
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
$ref = new ReflectionExtension("periscope");
$names = [];
foreach ($ref->getFunctions() as $f) {
    $names[] = $f->getName();
}
sort($names);
foreach ($names as $name) echo $name, "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
periscope_checkpoint
periscope_record_event
[periscope] exit  {main} -> int(1) (%fms) @depth=1
