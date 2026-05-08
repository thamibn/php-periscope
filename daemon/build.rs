fn main() {
    println!("cargo:rerun-if-changed=../proto/trace.capnp");
    capnpc::CompilerCommand::new()
        .src_prefix("../proto")
        .file("../proto/trace.capnp")
        .run()
        .expect("capnp compile failed");
}
