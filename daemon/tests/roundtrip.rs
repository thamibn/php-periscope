#![forbid(unsafe_code)]

//! Phase 4 round-trip: write a cap'n proto trace from Rust, read it back.
//! Validates the schema + reader path independently of the C++ writer.

use std::io::Write;

use periscope_daemon::trace::Trace;
use periscope_daemon::trace_capnp;

#[test]
fn write_and_read_minimal_trace() {
    let dir = tempfile::tempdir().expect("tempdir");
    let path = dir.path().join("synthetic.cptrace");

    let mut message = capnp::message::Builder::new_default();
    {
        let mut root = message.init_root::<trace_capnp::trace::Builder>();

        let mut meta = root.reborrow().init_meta();
        meta.set_php_version("8.3.22");
        meta.set_periscope_version("0.1.0-test");
        meta.set_started_at_unix_micros(1_700_000_000_000_000);
        meta.set_duration_micros(12_345);
        meta.set_working_dir("/tmp");
        meta.set_entry_point("test.php");
        meta.set_sapi("cli");
        meta.set_pid(42);

        let mut frames = root.init_frames(2);
        {
            let mut f = frames.reborrow().get(0);
            f.set_id(1);
            f.set_parent_id(0);
            f.set_function("{main}");
            f.set_file("test.php");
            f.set_line(1);
            f.set_enter_micros(0);
            f.set_exit_micros(12_345);
            f.set_depth(1);
            f.init_return_value().set_int_val(42);
        }
        {
            let mut f = frames.reborrow().get(1);
            f.set_id(2);
            f.set_parent_id(1);
            f.set_function("greet");
            f.set_file("test.php");
            f.set_line(7);
            f.set_enter_micros(100);
            f.set_exit_micros(200);
            f.set_depth(2);
            let mut sv = f.init_return_value().init_string_val();
            sv.set_utf8(b"hi");
            sv.set_total_len(2);
            sv.set_truncated(false);
        }
    }

    {
        let mut file = std::fs::File::create(&path).expect("create");
        capnp::serialize::write_message(&mut file, &message).expect("write");
        file.flush().expect("flush");
    }

    let trace = Trace::open(&path).expect("open");
    let root = trace.root().expect("root");

    let meta = root.get_meta().expect("meta");
    assert_eq!(meta.get_php_version().unwrap().to_str().unwrap(), "8.3.22");
    assert_eq!(meta.get_pid(), 42);
    assert_eq!(meta.get_duration_micros(), 12_345);

    let frames = root.get_frames().expect("frames");
    assert_eq!(frames.len(), 2);

    let f0 = frames.get(0);
    assert_eq!(f0.get_id(), 1);
    assert_eq!(f0.get_function().unwrap().to_str().unwrap(), "{main}");
    assert!(matches!(
        f0.get_return_value().unwrap().which().unwrap(),
        trace_capnp::value::Which::IntVal(42)
    ));

    let f1 = frames.get(1);
    assert_eq!(f1.get_function().unwrap().to_str().unwrap(), "greet");
    let rv = f1.get_return_value().unwrap();
    if let trace_capnp::value::Which::StringVal(sv) = rv.which().unwrap() {
        let sv = sv.unwrap();
        assert_eq!(sv.get_utf8().unwrap(), b"hi");
        assert_eq!(sv.get_total_len(), 2);
    } else {
        panic!("expected string return");
    }
}
