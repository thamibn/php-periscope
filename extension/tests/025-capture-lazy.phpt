--TEST--
Phase 3: objects with __get are tagged <lazy> and __get is NOT invoked
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

class Proxy
{
    public function __get(string $name): mixed
    {
        throw new RuntimeException("__get must not fire during observation");
    }
}

function take(Proxy $p): string { return 'ok'; }
echo take(new Proxy()), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter take(Proxy $p = object(Proxy)#%d <lazy>) @depth=2
[periscope] exit  take: string -> string(2) "ok" (%fms) @depth=2
ok
[periscope] exit  {main} -> int(1) (%fms) @depth=1
