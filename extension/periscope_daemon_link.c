#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "periscope_daemon_link.h"

#include <errno.h>
#include <fcntl.h>
#include <stddef.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/un.h>
#include <unistd.h>

/* Per-process fd. Each request opens/closes the link; a static fd is
 * sufficient for a single-threaded PHP request. */
static int s_fd = -1;

/* Phase 8b breakpoint store. Bounded by PERISCOPE_MAX_BREAKPOINTS to keep
 * lookup cheap and memory predictable. Lookup is linear scan — fine for
 * IDE-set breakpoint counts which are typically tens, never hundreds. */
#define PERISCOPE_MAX_BREAKPOINTS 256
typedef struct {
    char     file[1024];
    uint32_t line;
} periscope_breakpoint_t;

static periscope_breakpoint_t s_breakpoints[PERISCOPE_MAX_BREAKPOINTS];
static size_t s_breakpoint_count = 0;

/* JSON-escape: cheap for our message shapes (function names, file paths,
 * already-encoded JSON). */
static void escape_json(const char *src, char *dst, size_t cap)
{
    size_t pos = 0;
    if (cap == 0) return;
    if (src == NULL) src = "";
    while (*src && pos + 8 < cap) {
        unsigned char c = (unsigned char)*src++;
        switch (c) {
        case '"':  dst[pos++] = '\\'; dst[pos++] = '"'; break;
        case '\\': dst[pos++] = '\\'; dst[pos++] = '\\'; break;
        case '\n': dst[pos++] = '\\'; dst[pos++] = 'n'; break;
        case '\r': dst[pos++] = '\\'; dst[pos++] = 'r'; break;
        case '\t': dst[pos++] = '\\'; dst[pos++] = 't'; break;
        default:
            if (c < 0x20) {
                int n = snprintf(dst + pos, cap - pos, "\\u%04x", c);
                if (n > 0) pos += (size_t)n;
            } else {
                dst[pos++] = (char)c;
            }
        }
    }
    dst[pos] = '\0';
}

/* Send: one length-prefixed JSON frame. Non-blocking; drop link on any
 * failure. */
static void send_frame(const char *body, size_t len)
{
    if (s_fd < 0) return;
    unsigned char hdr[4];
    hdr[0] = (unsigned char)((len >> 24) & 0xff);
    hdr[1] = (unsigned char)((len >> 16) & 0xff);
    hdr[2] = (unsigned char)((len >> 8) & 0xff);
    hdr[3] = (unsigned char)(len & 0xff);

    ssize_t n = send(s_fd, hdr, 4, MSG_NOSIGNAL | MSG_DONTWAIT);
    if (n != 4) {
        periscope_daemon_link_close();
        return;
    }
    n = send(s_fd, body, len, MSG_NOSIGNAL | MSG_DONTWAIT);
    if (n != (ssize_t)len) {
        periscope_daemon_link_close();
        return;
    }
}

/* Read exactly `len` bytes — blocking variant, used while paused. */
static int read_exact_blocking(int fd, void *buf, size_t len)
{
    /* Temporarily switch the fd to blocking mode so we can wait for the
     * Continue message without spinning. */
    int flags = fcntl(fd, F_GETFL, 0);
    if (flags < 0) return -1;
    if (fcntl(fd, F_SETFL, flags & ~O_NONBLOCK) < 0) return -1;

    size_t got = 0;
    int rc = 0;
    while (got < len) {
        ssize_t r = recv(fd, (char *)buf + got, len - got, 0);
        if (r > 0) {
            got += (size_t)r;
            continue;
        }
        if (r == 0) { rc = -1; break; } /* peer closed */
        if (errno == EINTR) continue;
        rc = -1; break;
    }

    /* Restore non-blocking. */
    if (fcntl(fd, F_SETFL, flags) < 0) {
        /* If we can't restore, the link is no longer trustworthy. */
        periscope_daemon_link_close();
        return -1;
    }
    return rc;
}

/* Read one frame (blocking). Caller must free `*out_body`. Returns
 * frame length on success, 0 on clean EOF, -1 on error. */
static long read_frame_blocking(int fd, char **out_body)
{
    *out_body = NULL;
    unsigned char hdr[4];
    if (read_exact_blocking(fd, hdr, 4) != 0) return -1;
    uint32_t len = ((uint32_t)hdr[0] << 24)
                 | ((uint32_t)hdr[1] << 16)
                 | ((uint32_t)hdr[2] << 8)
                 |  (uint32_t)hdr[3];
    if (len == 0 || len > 256 * 1024) return -1;
    char *body = (char *)malloc((size_t)len + 1);
    if (body == NULL) return -1;
    if (read_exact_blocking(fd, body, (size_t)len) != 0) {
        free(body);
        return -1;
    }
    body[len] = '\0';
    *out_body = body;
    return (long)len;
}

/* Read one frame (non-blocking). Returns 0 if no frame ready, len if read,
 * -1 on error. */
static long read_frame_nonblocking(int fd, char **out_body)
{
    *out_body = NULL;
    unsigned char hdr[4];
    ssize_t r = recv(fd, hdr, 4, MSG_PEEK | MSG_DONTWAIT);
    if (r < 4) return (r < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) ? 0 : -1;
    /* Header is available; consume it. */
    r = recv(fd, hdr, 4, 0);
    if (r != 4) return -1;
    uint32_t len = ((uint32_t)hdr[0] << 24)
                 | ((uint32_t)hdr[1] << 16)
                 | ((uint32_t)hdr[2] << 8)
                 |  (uint32_t)hdr[3];
    if (len == 0 || len > 256 * 1024) return -1;
    char *body = (char *)malloc((size_t)len + 1);
    if (body == NULL) return -1;
    /* Body must come right after the header. Use the blocking variant
     * to drain it cleanly — header was already in the kernel buffer so
     * this won't actually block in practice. */
    if (read_exact_blocking(fd, body, (size_t)len) != 0) {
        free(body);
        return -1;
    }
    body[len] = '\0';
    *out_body = body;
    return (long)len;
}

/* Tiny needle-finder: returns a pointer to the value of the JSON field
 * `key` inside `body`, or NULL. Walks past `"key":` and stops at the
 * value's start. Not a real JSON parser — just substring search. Safe
 * because messages come from our trusted daemon which uses serde_json. */
static const char *json_value_after(const char *body, const char *key)
{
    char pat[64];
    int n = snprintf(pat, sizeof(pat), "\"%s\":", key);
    if (n <= 0 || (size_t)n >= sizeof(pat)) return NULL;
    const char *p = strstr(body, pat);
    if (p == NULL) return NULL;
    p += (size_t)n;
    while (*p == ' ' || *p == '\t') p++;
    return p;
}

/* Extract a string-typed JSON value into `out`. Returns true on success. */
static bool json_string(const char *value_start, char *out, size_t cap)
{
    if (value_start == NULL || *value_start != '"') return false;
    const char *p = value_start + 1;
    size_t i = 0;
    while (*p && i + 1 < cap) {
        if (*p == '\\' && *(p + 1) != '\0') {
            char esc = *(p + 1);
            switch (esc) {
            case '"':  out[i++] = '"'; break;
            case '\\': out[i++] = '\\'; break;
            case '/':  out[i++] = '/'; break;
            case 'n':  out[i++] = '\n'; break;
            case 'r':  out[i++] = '\r'; break;
            case 't':  out[i++] = '\t'; break;
            default:   out[i++] = esc; break;
            }
            p += 2;
            continue;
        }
        if (*p == '"') break;
        out[i++] = *p++;
    }
    out[i] = '\0';
    return *p == '"';
}

/* Extract a JSON int array into `out`, capped at `cap`. Returns count. */
static size_t json_int_array(const char *value_start, uint32_t *out, size_t cap)
{
    if (value_start == NULL || *value_start != '[') return 0;
    const char *p = value_start + 1;
    size_t count = 0;
    while (*p && *p != ']' && count < cap) {
        while (*p == ' ' || *p == ',' || *p == '\t' || *p == '\n') p++;
        if (*p == ']' || *p == '\0') break;
        char *end = NULL;
        long v = strtol(p, &end, 10);
        if (end == p) break;
        if (v >= 0) out[count++] = (uint32_t)v;
        p = end;
    }
    return count;
}

/* Process one inbound daemon message body. */
static void apply_daemon_message(const char *body)
{
    if (body == NULL) return;
    const char *type_value = json_value_after(body, "type");
    if (type_value == NULL) return;

    char type_str[64];
    if (!json_string(type_value, type_str, sizeof(type_str))) return;

    if (strcmp(type_str, "set_breakpoints") == 0) {
        char file[1024];
        const char *file_v = json_value_after(body, "file");
        if (!file_v || !json_string(file_v, file, sizeof(file))) return;

        uint32_t lines[64];
        const char *lines_v = json_value_after(body, "lines");
        size_t line_count = json_int_array(lines_v, lines, sizeof(lines) / sizeof(lines[0]));

        /* Drop existing breakpoints in this file, then insert the new set. */
        size_t w = 0;
        for (size_t r = 0; r < s_breakpoint_count; r++) {
            if (strcmp(s_breakpoints[r].file, file) != 0) {
                if (w != r) s_breakpoints[w] = s_breakpoints[r];
                w++;
            }
        }
        s_breakpoint_count = w;

        for (size_t i = 0; i < line_count && s_breakpoint_count < PERISCOPE_MAX_BREAKPOINTS; i++) {
            periscope_breakpoint_t *b = &s_breakpoints[s_breakpoint_count++];
            size_t flen = strlen(file);
            if (flen >= sizeof(b->file)) flen = sizeof(b->file) - 1;
            memcpy(b->file, file, flen);
            b->file[flen] = '\0';
            b->line = lines[i];
        }
    }
    /* `continue` is handled by the pause-loop reader; if it arrives here
     * (i.e. outside a pause), we just ignore it. */
}

bool periscope_daemon_link_open(const char *socket_path)
{
    if (s_fd >= 0) return true;

    if (socket_path == NULL || socket_path[0] == '\0') {
        const char *env = getenv("PERISCOPE_DAEMON_SOCKET");
        if (env == NULL || env[0] == '\0') return false;
        socket_path = env;
    }

    size_t path_len = strlen(socket_path);
    if (path_len == 0 || path_len >= sizeof(((struct sockaddr_un *)0)->sun_path)) {
        return false;
    }

    int fd = socket(AF_UNIX, SOCK_STREAM, 0);
    if (fd < 0) return false;

    struct sockaddr_un addr;
    memset(&addr, 0, sizeof(addr));
    addr.sun_family = AF_UNIX;
    memcpy(addr.sun_path, socket_path, path_len);
    addr.sun_path[path_len] = '\0';

    socklen_t addr_len = (socklen_t)(offsetof(struct sockaddr_un, sun_path) + path_len + 1);
    if (connect(fd, (struct sockaddr *)&addr, addr_len) < 0) {
        close(fd);
        return false;
    }

    int flags = fcntl(fd, F_GETFL, 0);
    if (flags < 0 || fcntl(fd, F_SETFL, flags | O_NONBLOCK) < 0) {
        close(fd);
        return false;
    }

    s_fd = fd;
    /* Reset breakpoint state for this request. */
    s_breakpoint_count = 0;

    /* Push a hello so the daemon registers our outbound channel. */
    char body[128];
    int n = snprintf(body, sizeof(body),
        "{\"type\":\"hello\",\"pid\":%u,\"version\":\"%s\"}",
        (unsigned)getpid(), "0.1.0");
    if (n > 0) send_frame(body, (size_t)n);

    return s_fd >= 0;
}

void periscope_daemon_link_close(void)
{
    if (s_fd >= 0) {
        close(s_fd);
        s_fd = -1;
    }
    s_breakpoint_count = 0;
}

bool periscope_daemon_link_active(void)
{
    return s_fd >= 0;
}

void periscope_daemon_link_send_request_finished(
    const char *request_id, const char *trace_path, uint64_t duration_micros)
{
    if (s_fd < 0) return;
    char id_esc[256], path_esc[1024];
    escape_json(request_id, id_esc, sizeof(id_esc));
    escape_json(trace_path, path_esc, sizeof(path_esc));
    char buf[2048];
    int n = snprintf(buf, sizeof(buf),
        "{\"type\":\"request_finished\",\"request_id\":\"%s\",\"trace_path\":\"%s\",\"duration_micros\":%llu}",
        id_esc, path_esc, (unsigned long long)duration_micros);
    if (n > 0 && (size_t)n < sizeof(buf)) send_frame(buf, (size_t)n);
}

void periscope_daemon_link_drain(void)
{
    if (s_fd < 0) return;
    /* Drain everything available; bounded loop so a misbehaving daemon
     * can't pin us in the read loop indefinitely. */
    for (int i = 0; i < 32; i++) {
        char *body = NULL;
        long len = read_frame_nonblocking(s_fd, &body);
        if (len <= 0) {
            if (len < 0) periscope_daemon_link_close();
            return;
        }
        apply_daemon_message(body);
        free(body);
    }
}

bool periscope_daemon_link_is_breakpoint(const char *file, uint32_t line)
{
    if (s_breakpoint_count == 0 || file == NULL) return false;
    for (size_t i = 0; i < s_breakpoint_count; i++) {
        if (s_breakpoints[i].line == line && strcmp(s_breakpoints[i].file, file) == 0) {
            return true;
        }
    }
    return false;
}

bool periscope_daemon_link_pause(uint32_t frame_id, const char *file, uint32_t line)
{
    if (s_fd < 0) return false;

    /* Notify the daemon we're stopping. */
    char file_esc[1024];
    escape_json(file ? file : "", file_esc, sizeof(file_esc));
    char buf[1536];
    int n = snprintf(buf, sizeof(buf),
        "{\"type\":\"breakpoint_hit\",\"frame_id\":%u,\"file\":\"%s\",\"line\":%u}",
        (unsigned)frame_id, file_esc, (unsigned)line);
    if (n <= 0 || (size_t)n >= sizeof(buf)) return false;
    send_frame(buf, (size_t)n);
    if (s_fd < 0) return false;

    /* Block until Continue arrives. While waiting, also process any
     * SetBreakpoints updates the IDE may send so the cursor sees fresh
     * state on resume. */
    while (s_fd >= 0) {
        char *body = NULL;
        long len = read_frame_blocking(s_fd, &body);
        if (len <= 0) {
            if (body) free(body);
            periscope_daemon_link_close();
            return false;
        }

        const char *type_v = json_value_after(body, "type");
        char type_str[64] = {0};
        if (type_v) json_string(type_v, type_str, sizeof(type_str));

        if (strcmp(type_str, "continue") == 0) {
            free(body);
            return true;
        }
        /* Anything else (set_breakpoints) → apply and keep waiting. */
        apply_daemon_message(body);
        free(body);
    }
    return false;
}
