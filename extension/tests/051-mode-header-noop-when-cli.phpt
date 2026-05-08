--TEST--
periscope_apply_mode_header is a no-op when $_SERVER lacks the header
--SKIPIF--
<?php if (!extension_loaded('periscope')) die('skip periscope not loaded'); ?>
--FILE--
<?php
// No HTTP_X_PERISCOPE_MODE in env — defaults stay. Without a trace_dir
// set, periscope_record_event returns false because PERISCOPE_G(trace) is
// NULL (no sink), confirming the mode-header reader is a clean no-op when
// the header is absent.
var_dump(periscope_record_event('probe', [], null));
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
bool(false)
[periscope] exit  {main} -> int(1) (%fms) @depth=1
