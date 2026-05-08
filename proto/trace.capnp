@0xb87f8e23a9f4c2d1;

# php-periscope on-disk trace format.
#
# Generated once per request by the C extension. Read by the Rust daemon
# (mmap, zero-copy). Schema is the single source of truth shared by both
# producer and consumer — DO NOT renumber existing field tags.
#
# Evolution rules:
#   - new fields get a new tag number, never reuse retired ones
#   - never change a field's type; deprecate + add a new field instead
#   - never reorder; never remove
#
# v1 invariant: function-boundary recording only. No per-opcode frames.

struct Trace {
  meta                   @0 :Meta;
  frames                 @1 :List(Frame);
  observabilityEvents    @2 :List(ObservabilityEvent);
}

struct Meta {
  phpVersion             @0 :Text;
  periscopeVersion       @1 :Text;
  startedAtUnixMicros    @2 :UInt64;
  durationMicros         @3 :UInt64;
  workingDir             @4 :Text;
  entryPoint             @5 :Text;
  sapi                   @6 :Text;       # cli, fpm-fcgi, apache2handler, ...
  hostname               @7 :Text;
  pid                    @8 :UInt32;
  request                @9 :Request;    # may be empty for CLI
  response              @10 :Response;   # may be empty for CLI
}

# Per-frame record. Streamed in the order frames close (post-order); parent
# pointers reconstruct the call tree on the reader side.
struct Frame {
  id                     @0 :UInt32;     # 1-based; 0 = no parent
  parentId               @1 :UInt32;
  function               @2 :Text;       # "Foo::bar", "{main}", "{closure@file:42}"
  file                   @3 :Text;
  line                   @4 :UInt32;
  enterMicros            @5 :UInt64;     # offset from Meta.startedAtUnixMicros
  exitMicros             @6 :UInt64;
  args                   @7 :List(Argument);
  returnValue            @8 :Value;
  observabilityEventIds  @9 :List(UInt32);
  depth                 @10 :UInt32;
  flags                 @11 :UInt32;     # bit 0 = exception thrown
}

struct Argument {
  name                   @0 :Text;       # parameter name from signature
  declaredType           @1 :Text;       # "int", "?User", "string|null"
  byRef                  @2 :Bool;
  variadic               @3 :Bool;
  value                  @4 :Value;
}

# Recursive value snapshot — mirrors what the C extension's
# periscope_capture serialiser produces.
struct Value {
  union {
    nullVal              @0 :Void;
    boolVal              @1 :Bool;
    intVal               @2 :Int64;
    floatVal             @3 :Float64;
    stringVal            @4 :StringValue;
    arrayVal             @5 :ArrayValue;
    objectVal            @6 :ObjectValue;
    enumVal              @7 :EnumValue;
    closureVal           @8 :ClosureValue;
    resourceVal          @9 :ResourceValue;
    referenceVal        @10 :Value;
    backref             @11 :UInt32;     # cycle break, points at object handle
    truncated           @12 :Truncation;
    opaque              @13 :Text;       # last-resort fallback
  }
}

struct StringValue {
  utf8                   @0 :Data;       # bytes, may be capped
  totalLen               @1 :UInt32;     # original length before capping
  truncated              @2 :Bool;
}

struct ArrayValue {
  totalCount             @0 :UInt32;
  items                  @1 :List(KeyValue);
  truncated              @2 :Bool;
}

struct ObjectValue {
  className              @0 :Text;
  objectHandle           @1 :UInt32;     # stable identity within a request
  visibility             @2 :List(PropertyVisibility);
  properties             @3 :List(KeyValue);
  totalProps             @4 :UInt32;
  isLazy                 @5 :Bool;       # has __get magic — props skipped
  isReadonly             @6 :Bool;       # readonly class
  truncated              @7 :Bool;
}

struct PropertyVisibility {
  name                   @0 :Text;
  scope                  @1 :Visibility;
  readonly               @2 :Bool;
}

enum Visibility {
  public                 @0;
  protected              @1;
  private                @2;
}

struct EnumValue {
  className              @0 :Text;
  caseName               @1 :Text;
  backed                 @2 :Bool;
  union :group {
    none                 @3 :Void;
    intValue             @4 :Int64;
    stringValue          @5 :Text;
  }
}

struct ClosureValue {
  scopeClass             @0 :Text;       # may be empty for top-level closures
  function               @1 :Text;       # "{closure}" or actual name
  declaredAt             @2 :Text;       # file:line
}

struct ResourceValue {
  handle                 @0 :Int32;
  typeName               @1 :Text;       # "stream", "curl", ...
}

struct KeyValue {
  union {
    intKey               @0 :Int64;
    strKey               @1 :Text;
  }
  value                  @2 :Value;
}

struct Truncation {
  reason                 @0 :TruncationReason;
}

enum TruncationReason {
  depth                  @0;
  size                   @1;
  items                  @2;
  props                  @3;
}

# ----- Request / response envelope (see project_request_capture memory) -----

struct Request {
  method                 @0 :Text;
  uri                    @1 :Text;
  headers                @2 :List(Header);
  cookies                @3 :List(Header);
  query                  @4 :List(Header);
  postParams             @5 :List(Header);
  rawBody                @6 :Data;       # capped at periscope.max_body_bytes
  bodyTruncated          @7 :Bool;
  totalBodyBytes         @8 :UInt32;
  files                  @9 :List(UploadedFile);
  remoteAddr            @10 :Text;
  scheme                @11 :Text;
}

struct Response {
  statusCode             @0 :UInt16;
  headers                @1 :List(Header);
  body                   @2 :Data;
  bodyTruncated          @3 :Bool;
  totalBodyBytes         @4 :UInt32;
  durationMicros         @5 :UInt64;
  peakMemoryBytes        @6 :UInt64;
}

struct Header {
  name                   @0 :Text;
  value                  @1 :Text;
  redacted               @2 :Bool;       # true if value was scrubbed
}

struct UploadedFile {
  formField              @0 :Text;
  filename               @1 :Text;
  mimeType               @2 :Text;
  size                   @3 :UInt32;
}

# ----- Observability events (Phase 5+ Laravel adapter populates these) -----

struct ObservabilityEvent {
  id                     @0 :UInt32;
  atMicros               @1 :UInt64;
  inFrameId              @2 :UInt32;
  payload :union {
    sqlQuery             @3 :SqlQueryEvent;
    logLine              @4 :LogEvent;
    cacheOp              @5 :CacheEvent;
    httpCall             @6 :HttpEvent;
    redisOp              @7 :RedisEvent;
    eventDispatched      @8 :EventDispatchedEvent;
    jobDispatched        @9 :JobDispatchedEvent;
    mailSent            @10 :MailEvent;
    nPlusOne            @11 :NPlusOneWarning;
    requestResolved     @12 :RequestResolvedEvent;
    # v1 fallback: type tag + JSON-encoded payload. Phase 6+ migrates to
    # typed variants above. The reader handles either form transparently.
    genericJson         @13 :GenericJsonEvent;
  }
  userCallSite          @14 :CallSite;
}

struct GenericJsonEvent {
  type                   @0 :Text;       # "sql", "log", "cache", "http", ...
  payloadJson            @1 :Text;       # arbitrary JSON shape — see laravel-adapter/docs/EVENT_PAYLOADS.md
  callSiteJson           @2 :Text;       # JSON {file, line, snippet, frame_stack} — empty when adapter couldn't resolve a user-code frame
}

struct CallSite {
  file                   @0 :Text;
  line                   @1 :UInt32;
  snippet                @2 :List(SnippetLine);
  frameStack             @3 :List(UInt32);   # frame ids root → leaf
}

struct SnippetLine {
  number                 @0 :UInt32;
  source                 @1 :Text;
}

struct SqlQueryEvent {
  connection             @0 :Text;
  sql                    @1 :Text;
  bindings               @2 :List(Value);
  timeMs                 @3 :Float64;
  rowsAffected           @4 :Int32;
}

struct LogEvent {
  level                  @0 :Text;
  channel                @1 :Text;
  message                @2 :Text;
  context                @3 :List(KeyValue);
}

struct CacheEvent {
  store                  @0 :Text;
  operation              @1 :Text;       # hit/miss/write/forget
  key                    @2 :Text;
  ttlSeconds             @3 :Int32;
}

struct HttpEvent {
  method                 @0 :Text;
  url                    @1 :Text;
  statusCode             @2 :UInt16;
  durationMs             @3 :Float64;
  requestBytes           @4 :UInt32;
  responseBytes          @5 :UInt32;
}

struct RedisEvent {
  command                @0 :Text;
  args                   @1 :List(Text);
  durationMs             @2 :Float64;
}

struct EventDispatchedEvent {
  eventClass             @0 :Text;
  payload                @1 :Value;
}

struct JobDispatchedEvent {
  jobClass               @0 :Text;
  queue                  @1 :Text;
  connection             @2 :Text;
  delaySeconds           @3 :Int32;
  payload                @4 :Value;
}

struct MailEvent {
  to                     @0 :List(Text);
  subject                @1 :Text;
  mailable               @2 :Text;
}

struct NPlusOneWarning {
  pattern                @0 :Text;
  count                  @1 :UInt32;
  firstQueryFrameId      @2 :UInt32;
}

struct RequestResolvedEvent {
  routeName              @0 :Text;
  action                 @1 :Text;       # "Controller@method"
  parameters             @2 :List(KeyValue);
  authUserId             @3 :Text;
  authGuard              @4 :Text;
  locale                 @5 :Text;
}
