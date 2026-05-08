// Cap'n Proto trace writer for php-periscope.
//
// This module is the only C++ in the extension; everything else is C and
// reaches us via the extern "C" interface in periscope_trace.h.
//
// v1 stores frames as Cap'n Proto messages with the args/return values held
// as opaque text summaries (the same format Phase 3 emits to stderr). Phase 5
// migrates the value side to typed Cap'n Proto Value structs.

#include "trace.capnp.h"

#include <capnp/message.h>
#include <capnp/serialize.h>
#include <kj/io.h>
#include <kj/string.h>

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>

#include <string>
#include <vector>

extern "C" {
#include "periscope_trace.h"
}

namespace {

struct FrameRow {
    uint32_t id;
    uint32_t parentId;
    std::string function;
    std::string file;
    uint32_t line;
    uint64_t enterMicros;
    uint64_t exitMicros;
    uint32_t depth;
    std::string argsSummary;
    std::string returnSummary;
};

struct EventRow {
    uint32_t id;
    uint64_t atMicros;
    uint32_t inFrameId;
    std::string typeTag;
    std::string payloadJson;
    std::string callSiteJson;
};

struct RequestRow {
    bool hasRequest = false;
    std::string method;
    std::string uri;
    std::string headersJson;
    std::string cookiesJson;
    std::string queryJson;
    std::string postJson;
    std::string rawBody;
    uint32_t totalBodyBytes = 0;
    std::string remoteAddr;
    std::string scheme;
};

struct ResponseRow {
    bool hasResponse = false;
    uint16_t statusCode = 0;
    std::string headersJson;
    uint64_t peakMemoryBytes = 0;
};

}  // namespace

struct periscope_trace_writer {
    std::string traceDir;
    std::string traceId;
    std::string outputPath;

    // Meta fields collected up-front; written into the message at close time.
    std::string phpVersion;
    std::string periscopeVersion;
    std::string sapi;
    std::string entryPoint;
    std::string cwd;
    uint64_t startedAt = 0;
    uint32_t pid = 0;

    std::vector<FrameRow> frames;
    std::vector<EventRow> events;
    RequestRow request;
    ResponseRow response;
};

namespace {

bool ensure_dir(const char *path) {
    struct stat st;
    if (stat(path, &st) == 0) return S_ISDIR(st.st_mode);
    return mkdir(path, 0755) == 0;
}

std::string make_trace_id(uint32_t pid, uint64_t started_us) {
    char buf[64];
    std::snprintf(buf, sizeof(buf), "%010llu-%u",
                  static_cast<unsigned long long>(started_us), pid);
    return buf;
}

}  // namespace

extern "C" periscope_trace_writer *periscope_trace_open(
    const char *trace_dir,
    const char *php_version,
    const char *periscope_version,
    const char *sapi,
    const char *entry_point,
    const char *cwd,
    uint64_t started_at_unix_micros,
    uint32_t pid)
{
    if (!trace_dir || !*trace_dir) return nullptr;
    if (!ensure_dir(trace_dir)) return nullptr;

    auto *w = new (std::nothrow) periscope_trace_writer();
    if (!w) return nullptr;

    w->traceDir         = trace_dir;
    w->traceId          = make_trace_id(pid, started_at_unix_micros);
    w->outputPath       = w->traceDir + "/" + w->traceId + ".cptrace";
    w->phpVersion       = php_version       ? php_version       : "";
    w->periscopeVersion = periscope_version ? periscope_version : "";
    w->sapi             = sapi              ? sapi              : "";
    w->entryPoint       = entry_point       ? entry_point       : "";
    w->cwd              = cwd               ? cwd               : "";
    w->startedAt        = started_at_unix_micros;
    w->pid              = pid;

    w->frames.reserve(256);
    w->events.reserve(64);
    return w;
}

extern "C" void periscope_trace_event(
    periscope_trace_writer *w,
    uint32_t event_id,
    uint64_t at_micros,
    uint32_t in_frame_id,
    const char *type_tag,
    const char *payload_json,
    const char *call_site_json)
{
    if (!w) return;
    EventRow row;
    row.id = event_id;
    row.atMicros = at_micros;
    row.inFrameId = in_frame_id;
    row.typeTag = type_tag ? type_tag : "";
    row.payloadJson = payload_json ? payload_json : "";
    row.callSiteJson = call_site_json ? call_site_json : "";
    w->events.emplace_back(std::move(row));
}

extern "C" void periscope_trace_set_request(
    periscope_trace_writer *w,
    const char *method,
    const char *uri,
    const char *headers_json,
    const char *cookies_json,
    const char *query_json,
    const char *post_json,
    const char *raw_body,
    uint32_t raw_body_len,
    uint32_t total_body_bytes,
    const char *remote_addr,
    const char *scheme)
{
    if (!w) return;
    w->request.hasRequest = true;
    w->request.method      = method       ? method       : "";
    w->request.uri         = uri          ? uri          : "";
    w->request.headersJson = headers_json ? headers_json : "";
    w->request.cookiesJson = cookies_json ? cookies_json : "";
    w->request.queryJson   = query_json   ? query_json   : "";
    w->request.postJson    = post_json    ? post_json    : "";
    if (raw_body && raw_body_len > 0) {
        w->request.rawBody.assign(raw_body, raw_body_len);
    }
    w->request.totalBodyBytes = total_body_bytes;
    w->request.remoteAddr  = remote_addr  ? remote_addr  : "";
    w->request.scheme      = scheme       ? scheme       : "";
}

extern "C" void periscope_trace_set_response(
    periscope_trace_writer *w,
    uint16_t status_code,
    const char *headers_json,
    uint64_t peak_memory_bytes)
{
    if (!w) return;
    w->response.hasResponse = true;
    w->response.statusCode = status_code;
    w->response.headersJson = headers_json ? headers_json : "";
    w->response.peakMemoryBytes = peak_memory_bytes;
}

extern "C" void periscope_trace_frame(
    periscope_trace_writer *w,
    uint32_t frame_id,
    uint32_t parent_id,
    const char *function,
    const char *file,
    uint32_t line,
    uint64_t enter_micros,
    uint64_t exit_micros,
    uint32_t depth,
    const char *args_summary,
    const char *return_summary)
{
    if (!w) return;
    FrameRow row;
    row.id            = frame_id;
    row.parentId      = parent_id;
    row.function      = function       ? function       : "";
    row.file          = file           ? file           : "";
    row.line          = line;
    row.enterMicros   = enter_micros;
    row.exitMicros    = exit_micros;
    row.depth         = depth;
    row.argsSummary   = args_summary   ? args_summary   : "";
    row.returnSummary = return_summary ? return_summary : "";
    w->frames.emplace_back(std::move(row));
}

extern "C" const char *periscope_trace_close(
    periscope_trace_writer *w,
    uint64_t duration_micros)
{
    if (!w) return nullptr;

    capnp::MallocMessageBuilder message;
    Trace::Builder trace = message.initRoot<Trace>();

    Meta::Builder meta = trace.initMeta();
    meta.setPhpVersion(w->phpVersion);
    meta.setPeriscopeVersion(w->periscopeVersion);
    meta.setStartedAtUnixMicros(w->startedAt);
    meta.setDurationMicros(duration_micros);
    meta.setWorkingDir(w->cwd);
    meta.setEntryPoint(w->entryPoint);
    meta.setSapi(w->sapi);
    meta.setPid(w->pid);

    if (w->request.hasRequest) {
        Request::Builder req = meta.initRequest();
        req.setMethod(w->request.method);
        req.setUri(w->request.uri);
        // Headers/cookies/query/post are stored as JSON in v1; phase 6
        // migrates to typed lists. Daemon reader handles JSON via the
        // CallSite/payload pattern.
        if (!w->request.headersJson.empty()) {
            auto h = req.initHeaders(1);
            h[0].setName("__json__");
            h[0].setValue(w->request.headersJson);
        }
        if (!w->request.cookiesJson.empty()) {
            auto c = req.initCookies(1);
            c[0].setName("__json__");
            c[0].setValue(w->request.cookiesJson);
        }
        if (!w->request.queryJson.empty()) {
            auto q = req.initQuery(1);
            q[0].setName("__json__");
            q[0].setValue(w->request.queryJson);
        }
        if (!w->request.postJson.empty()) {
            auto p = req.initPostParams(1);
            p[0].setName("__json__");
            p[0].setValue(w->request.postJson);
        }
        if (!w->request.rawBody.empty()) {
            req.setRawBody(kj::arrayPtr(
                reinterpret_cast<const kj::byte *>(w->request.rawBody.data()),
                w->request.rawBody.size()));
        }
        req.setTotalBodyBytes(w->request.totalBodyBytes);
        req.setRemoteAddr(w->request.remoteAddr);
        req.setScheme(w->request.scheme);
    }

    if (w->response.hasResponse) {
        Response::Builder res = meta.initResponse();
        res.setStatusCode(w->response.statusCode);
        if (!w->response.headersJson.empty()) {
            auto h = res.initHeaders(1);
            h[0].setName("__json__");
            h[0].setValue(w->response.headersJson);
        }
        res.setPeakMemoryBytes(w->response.peakMemoryBytes);
        res.setDurationMicros(duration_micros);
    }

    auto frames = trace.initFrames(w->frames.size());
    for (size_t i = 0; i < w->frames.size(); ++i) {
        const FrameRow &row = w->frames[i];
        Frame::Builder f = frames[i];
        f.setId(row.id);
        f.setParentId(row.parentId);
        f.setFunction(row.function);
        f.setFile(row.file);
        f.setLine(row.line);
        f.setEnterMicros(row.enterMicros);
        f.setExitMicros(row.exitMicros);
        f.setDepth(row.depth);

        // v1: args list with one Argument whose Value is opaque text.
        if (!row.argsSummary.empty()) {
            auto args = f.initArgs(1);
            Argument::Builder a = args[0];
            a.setName("");
            a.setDeclaredType("");
            a.setByRef(false);
            a.setVariadic(false);
            a.initValue().setOpaque(row.argsSummary);
        }

        // v1: returnValue is opaque text in this phase.
        if (!row.returnSummary.empty()) {
            f.initReturnValue().setOpaque(row.returnSummary);
        } else {
            f.initReturnValue().setNullVal();
        }
    }

    // Observability events from the Laravel adapter (or any other userland code
    // that calls periscope_record_event()). v1 stores them as generic JSON;
    // phase 6+ promotes the most-used types into typed Cap'n Proto variants.
    if (!w->events.empty()) {
        auto evs = trace.initObservabilityEvents(w->events.size());
        for (size_t i = 0; i < w->events.size(); ++i) {
            const EventRow &row = w->events[i];
            ObservabilityEvent::Builder e = evs[i];
            e.setId(row.id);
            e.setAtMicros(row.atMicros);
            e.setInFrameId(row.inFrameId);
            auto generic = e.getPayload().initGenericJson();
            generic.setType(row.typeTag);
            generic.setPayloadJson(row.payloadJson);
            // v1: call site is stored alongside the JSON payload as raw JSON;
            // Phase 6+ will promote it into the typed CallSite struct. The
            // dumper / daemon parse this lazily.
            generic.setCallSiteJson(row.callSiteJson);
        }
    }

    int fd = ::open(w->outputPath.c_str(), O_WRONLY | O_CREAT | O_TRUNC, 0644);
    if (fd < 0) return nullptr;

    try {
        kj::FdOutputStream stream(fd);
        capnp::writeMessage(stream, message);
    } catch (...) {
        ::close(fd);
        return nullptr;
    }
    ::close(fd);

    return w->outputPath.c_str();
}

extern "C" void periscope_trace_free(periscope_trace_writer *w)
{
    delete w;
}
