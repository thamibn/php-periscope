--TEST--
Phase 3: methods on enum cases are observed; enum instance is captured as enum(...)
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

enum Tier: int
{
    case Free = 0;
    case Pro  = 1;

    public function label(): string
    {
        return match ($this) {
            self::Free => 'free tier',
            self::Pro  => 'pro tier',
        };
    }
}

echo Tier::Pro->label(), "\n";
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter Tier::label() @depth=2
[periscope] exit  Tier::label: string -> string(8) "pro tier" (%fms) @depth=2
pro tier
[periscope] exit  {main} -> int(1) (%fms) @depth=1
