--TEST--
Phase 3: periscope.namespace_filter restricts observation to allowlisted prefixes
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--INI--
periscope.namespace_filter=App\
--FILE--
<?php
declare(strict_types=1);

namespace Vendor\Pkg {
    function noisy(): int { return 1; }
}

namespace App\Service {
    function quiet(): int { return 2; }
}

namespace {
    \Vendor\Pkg\noisy();
    \App\Service\quiet();
}
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter App\Service\quiet() @depth=2
[periscope] exit  App\Service\quiet: int -> int(2) (%fms) @depth=2
[periscope] exit  {main} -> int(1) (%fms) @depth=1
