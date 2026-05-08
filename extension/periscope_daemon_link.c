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

    /* Push a hello so the daemon can log who's connected. */
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
