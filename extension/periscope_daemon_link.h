#ifndef PHP_PERISCOPE_DAEMON_LINK_H
#define PHP_PERISCOPE_DAEMON_LINK_H

#include <stdint.h>
#include <stdbool.h>

/* Phase 8a: minimal one-message-per-request notification channel.
 * Phase 8b: bidirectional — daemon pushes set_breakpoints / continue,
 * extension pushes breakpoint_hit and blocks waiting to be released.
 *
 * Wire format matches `daemon/src/ext_link.rs`:
 *   - 4-byte big-endian length
 *   - UTF-8 JSON body
 *
 * Failure modes (any one): silently disable the link. The daemon is
 * never load-bearing for the request; the trace file is. If the daemon
 * isn't running, nothing happens; if a write would block, we drop the
 * link to keep the request fast.
 *
 * Activation: connection is attempted only when the env var
 * `PERISCOPE_DAEMON_SOCKET` is set. Default is to do nothing — zero
 * overhead for users who aren't running the daemon.
 */

bool periscope_daemon_link_open(const char *socket_path);
void periscope_daemon_link_close(void);
bool periscope_daemon_link_active(void);

/* 8a: end-of-request notification. */
void periscope_daemon_link_send_request_finished(
    const char *request_id, const char *trace_path, uint64_t duration_micros);

/* 8b: pause primitive support. */

/* Drain any pending daemon → extension messages without blocking.
 * Called at every userland frame boundary so set_breakpoints arrives
 * promptly. */
void periscope_daemon_link_drain(void);

/* Test if (file, line) is currently a breakpoint. Lookup is O(N) on the
 * registered breakpoint count which is bounded; cost is irrelevant
 * compared to the rest of the frame-boundary work. */
bool periscope_daemon_link_is_breakpoint(const char *file, uint32_t line);

/* Send a breakpoint_hit message and block-read until daemon sends
 * Continue. Consumed by periscope_fcall_begin when a breakpoint matches.
 *
 * Returns true if Continue was received cleanly. False on socket error
 * (in which case the link is closed and the request resumes — we never
 * leave a request wedged because the daemon disappeared). */
bool periscope_daemon_link_pause(uint32_t frame_id, const char *file, uint32_t line);

#endif /* PHP_PERISCOPE_DAEMON_LINK_H */
