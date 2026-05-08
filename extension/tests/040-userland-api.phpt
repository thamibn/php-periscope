--TEST--
Phase 5a: periscope_record_event() and periscope_checkpoint() exist with correct signatures
--SKIPIF--
<?php if (!extension_loaded("periscope")) print "skip periscope not loaded"; ?>
--FILE--
<?php
declare(strict_types=1);

var_dump(function_exists("periscope_record_event"));
var_dump(function_exists("periscope_checkpoint"));

$ref = new ReflectionFunction("periscope_record_event");
echo "record_event params: ", $ref->getNumberOfParameters(), " (required: ", $ref->getNumberOfRequiredParameters(), ")\n";

$ref = new ReflectionFunction("periscope_checkpoint");
echo "checkpoint params: ", $ref->getNumberOfParameters(), " (required: ", $ref->getNumberOfRequiredParameters(), ")\n";

// Both should return false when no trace is active (trace_dir not set)
var_dump(periscope_record_event("test", []));
var_dump(periscope_checkpoint("noop"));
?>
--EXPECTF--
periscope loaded
[periscope] enter {main}() @depth=1
bool(true)
bool(true)
record_event params: 3 (required: 2)
checkpoint params: 2 (required: 1)
bool(false)
bool(false)
[periscope] exit  {main} -> int(1) (%fms) @depth=1
