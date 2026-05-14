--TEST--
X-Periscope-Mode: off header disables capture for the request
--SKIPIF--
<?php if (!extension_loaded('periscope')) die('skip periscope not loaded'); ?>
--ENV--
HTTP_X_PERISCOPE_MODE=off
--FILE--
<?php
// The header is read at RINIT. When mode=off, periscope short-circuits
// for the whole request: observer goes silent AND record-event returns
// false. Only the load-time banner remains.
function _do_it(): bool {
    return periscope_record_event('test', ['x' => 1], null);
}
var_dump(_do_it());
--EXPECTF--
periscope loaded
bool(false)
