--TEST--
Phase 4: setting periscope.trace_dir produces a .cptrace file
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--ENV--
TRACE_DIR=/tmp/periscope-phpt-trace
--INI--
periscope.trace_dir=/tmp/periscope-phpt-trace
periscope.skip_internal=1
--FILE--
<?php
declare(strict_types=1);

@mkdir("/tmp/periscope-phpt-trace", 0755, true);
foreach (glob("/tmp/periscope-phpt-trace/*.cptrace") as $f) @unlink($f);

function add(int $a, int $b): int { return $a + $b; }
echo add(2, 3), "\n";

// trace finalises in RSHUTDOWN, after this script returns. We can't read
// it from inside the script, so just assert MINIT/RINIT didn't blow up.
echo "done\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter add(int $a = int(2), int $b = int(3)) @depth=2
[periscope] exit  add: int -> int(5) (%fms) @depth=2
5
done
[periscope] exit  {main} -> int(1) (%fms) @depth=1
[periscope] trace written: /tmp/periscope-phpt-trace/%s.cptrace
