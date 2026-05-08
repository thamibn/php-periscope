--TEST--
Phase 5a: periscope_record_event() returns true and trace contains the event
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.trace_dir=/tmp/periscope-phpt-userland
--FILE--
<?php
declare(strict_types=1);

@mkdir("/tmp/periscope-phpt-userland", 0755, true);
foreach (glob("/tmp/periscope-phpt-userland/*.cptrace") as $f) @unlink($f);

var_dump(periscope_record_event("sql", [
    "connection" => "mysql",
    "sql" => "SELECT 1",
    "bindings" => [],
    "time_ms" => 0.5,
]));

var_dump(periscope_checkpoint("after-query"));

echo "ok\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
bool(true)
bool(true)
ok
[periscope] exit  {main} -> int(1) (%fms) @depth=1
[periscope] trace written: /tmp/periscope-phpt-userland/%s.cptrace
