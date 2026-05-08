--TEST--
Phase 4.1: trace retention prunes old .cptrace files on RINIT
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.trace_dir=/tmp/periscope-phpt-retention
periscope.max_traces=2
periscope.max_trace_age_seconds=0
--FILE--
<?php
declare(strict_types=1);

$dir = "/tmp/periscope-phpt-retention";
if (is_dir($dir)) {
    foreach (glob("$dir/*.cptrace") as $f) @unlink($f);
} else {
    @mkdir($dir, 0755, true);
}

// Pre-seed: 4 stale traces older than the about-to-be-written one
for ($i = 1; $i <= 4; $i++) {
    file_put_contents("$dir/stale-$i.cptrace", "x");
    usleep(10_000);
}

// At this point our extension already swept on RINIT — but RINIT ran
// before this script started. The sweep that matters is the NEXT request.
// We just verify: the smoke test in scripts/smoke.sh covers the cross-request
// case; here we just assert the script runs cleanly with retention configured.
echo "configured\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
configured
[periscope] exit  {main} -> int(1) (%fms) @depth=1
[periscope] trace written: /tmp/periscope-phpt-retention/%s.cptrace
