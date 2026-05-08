--TEST--
X-Periscope-Mode: off header disables capture for the request
--SKIPIF--
<?php if (!extension_loaded('periscope')) die('skip periscope not loaded'); ?>
--ENV--
HTTP_X_PERISCOPE_MODE=off
--FILE--
<?php
// The header is read at RINIT. Verify by emitting a record-event call —
// when disabled it must short-circuit and return false.
function _do_it(): bool {
    return periscope_record_event('test', ['x' => 1], null);
}
var_dump(_do_it());
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
[periscope] enter _do_it() @depth=2
[periscope] exit  _do_it: bool -> bool(false) (%fms) @depth=2
bool(false)
[periscope] exit  {main} -> int(1) (%fms) @depth=1
