#ifndef PERISCOPE_TRACE_H
#define PERISCOPE_TRACE_H

/* C-callable interface to the Cap'n Proto trace writer.
 * The implementation lives in periscope_trace.cc (C++) but exposes only this
 * extern "C" surface so the rest of the extension stays plain C. */

#include <stdint.h>
#include <stddef.h>
#include <stdbool.h>

#ifdef __cplusplus
extern "C" {
#endif

/* Opaque handle to an in-progress trace. One per request. */
typedef struct periscope_trace_writer periscope_trace_writer;

/* Open a fresh trace and write the Meta header. Returns NULL on failure
 * (in which case the extension keeps observing but never writes a trace). */
periscope_trace_writer *periscope_trace_open(const char *trace_dir,
                                             const char *php_version,
                                             const char *periscope_version,
                                             const char *sapi,
                                             const char *entry_point,
                                             const char *cwd,
                                             uint64_t started_at_unix_micros,
                                             uint32_t pid);

/* Append a frame. Values are passed already-serialised as a JSON-ish summary
 * for v1 (the same string the Phase 3 capture produces). Phase 6+ will add
 * structured Value variants on top of this. */
void periscope_trace_frame(periscope_trace_writer *w,
                           uint32_t frame_id,
                           uint32_t parent_id,
                           const char *function,
                           const char *file,
                           uint32_t line,
                           uint64_t enter_micros,
                           uint64_t exit_micros,
                           uint32_t depth,
                           const char *args_summary,
                           const char *return_summary);

/* Append an observability event (v1: generic JSON payload).
 * `type_tag` is e.g. "sql", "log", "cache", "http", "redis", "event",
 * "job", "mail", "request_resolved", "n_plus_one".
 * `payload_json` is a NUL-terminated JSON string from the Laravel adapter.
 * `call_site_json` is an optional JSON object {file,line,snippet,frame_stack}
 * — pass NULL or empty if the adapter could not resolve a user-code frame. */
void periscope_trace_event(periscope_trace_writer *w,
                           uint32_t event_id,
                           uint64_t at_micros,
                           uint32_t in_frame_id,
                           const char *type_tag,
                           const char *payload_json,
                           const char *call_site_json);

/* Set the request envelope (method, uri, headers, cookies, query, post body).
 * Pass any field as NULL/empty if not applicable (e.g. CLI). */
void periscope_trace_set_request(periscope_trace_writer *w,
                                 const char *method,
                                 const char *uri,
                                 const char *headers_json,   /* JSON object */
                                 const char *cookies_json,   /* JSON object */
                                 const char *query_json,     /* JSON object */
                                 const char *post_json,      /* JSON object */
                                 const char *raw_body,
                                 uint32_t raw_body_len,
                                 uint32_t total_body_bytes,
                                 const char *remote_addr,
                                 const char *scheme);

/* Set the response envelope (status, headers, peak memory). */
void periscope_trace_set_response(periscope_trace_writer *w,
                                  uint16_t status_code,
                                  const char *headers_json,
                                  uint64_t peak_memory_bytes);

/* Close the trace and return the path written, or NULL on error.
 * Caller does NOT own the returned pointer; it lives until the next call. */
const char *periscope_trace_close(periscope_trace_writer *w,
                                  uint64_t duration_micros);

/* Free the writer. Must be called after periscope_trace_close. */
void periscope_trace_free(periscope_trace_writer *w);

#ifdef __cplusplus
}
#endif

#endif /* PERISCOPE_TRACE_H */
