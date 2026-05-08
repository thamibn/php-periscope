#ifndef PHP_PERISCOPE_DAEMON_LINK_H
#define PHP_PERISCOPE_DAEMON_LINK_H

#include <stdint.h>
#include <stdbool.h>

/* Phase 8a: minimal one-message-per-request notification channel from
 * the C extension to the Rust daemon over a Unix domain socket.
 *
 * No per-frame streaming. Per-frame push for typical (50-200ms) HTTP
 * requests is theatre — the human reaction loop is too slow to react to
 * frames flying past. We push exactly one message per request, at
 * RSHUTDOWN, telling any subscribed UI tab "this trace is now ready."
 * The UI then opens it via the existing /api/traces/{id} HTTP endpoint
 * (instant — trace is already on disk).
 *
 * Wire format matches `daemon/src/ext_link.rs`:
 *   - 4-byte big-endian length
 *   - UTF-8 JSON body
 *
 * Failure modes (any one): silently drop. The daemon is never load-bearing
 * for the request; the trace file is. If the daemon isn't running, nothing
 * happens; if a write would block, we drop the message.
 *
 * Activation: connection is attempted only when the env var
 * `PERISCOPE_DAEMON_SOCKET` is set. Default is to do nothing — zero
 * overhead for users who aren't running the daemon.
 *
 * Future: opt-in `periscope.live_stream=1` (v1.1) flips on per-frame
 * push for long-running CLI/queue/test scenarios where it actually
 * matters. Default stays off.
 */

bool periscope_daemon_link_open(const char *socket_path);
void periscope_daemon_link_close(void);
bool periscope_daemon_link_active(void);

void periscope_daemon_link_send_request_finished(
    const char *request_id, const char *trace_path, uint64_t duration_micros);

#endif /* PHP_PERISCOPE_DAEMON_LINK_H */
