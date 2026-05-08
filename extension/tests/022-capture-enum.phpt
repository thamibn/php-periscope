--TEST--
Phase 3: enums show as enum(Class::Case) — backed includes value, pure does not
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

enum Status: string { case Active = 'A'; case Done = 'D'; }
enum Tier { case Free; case Pro; }

function take(Status $s, Tier $t): int { return 1; }
take(Status::Active, Tier::Pro);
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter take(Status $s = enum(Status::Active = string(1) "A"), Tier $t = enum(Tier::Pro)) @depth=2
[periscope] exit  take: int -> int(1) (%fms) @depth=2
[periscope] exit  {main} -> int(1) (%fms) @depth=1
