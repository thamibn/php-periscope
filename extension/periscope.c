#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "Zend/zend_observer.h"
#include "Zend/zend_smart_str.h"
#include "SAPI.h"

#include <stdbool.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <sys/time.h>
#include <sys/stat.h>
#include <dirent.h>
#include <unistd.h>

#include "php_periscope.h"
#include "periscope_filter.h"
#include "periscope_capture.h"
#include "periscope_trace.h"
#include "periscope_userland.h"
#include "periscope_daemon_link.h"

ZEND_DECLARE_MODULE_GLOBALS(periscope)

/* ------------------------------------------------------------------------ */
/* INI                                                                       */
/* ------------------------------------------------------------------------ */

PHP_INI_BEGIN()
    STD_PHP_INI_BOOLEAN("periscope.skip_internal",     "1", PHP_INI_SYSTEM,                OnUpdateBool,   skip_internal,     zend_periscope_globals, periscope_globals)
    STD_PHP_INI_BOOLEAN("periscope.disabled",          "0", PHP_INI_SYSTEM | PHP_INI_PERDIR, OnUpdateBool, disabled,          zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.max_depth",         "5", PHP_INI_ALL,                   OnUpdateLong,   max_depth,         zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.max_string",     "4096", PHP_INI_ALL,                   OnUpdateLong,   max_string,        zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.max_array_items", "100", PHP_INI_ALL,                   OnUpdateLong,   max_array_items,   zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.max_object_props", "50", PHP_INI_ALL,                   OnUpdateLong,   max_object_props,  zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.namespace_filter",  "",  PHP_INI_ALL,                   OnUpdateString, namespace_filter,  zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.trace_dir",         "",  PHP_INI_SYSTEM | PHP_INI_PERDIR, OnUpdateString, trace_dir,        zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.max_traces",      "100", PHP_INI_ALL,                   OnUpdateLong,   max_traces,        zend_periscope_globals, periscope_globals)
    STD_PHP_INI_ENTRY  ("periscope.max_trace_age_seconds", "86400", PHP_INI_ALL,           OnUpdateLong,   max_trace_age_seconds, zend_periscope_globals, periscope_globals)
PHP_INI_END()

static void php_periscope_init_globals(zend_periscope_globals *g)
{
    g->skip_internal     = true;
    g->disabled          = false;
    g->depth             = 0;
    g->max_depth         = 5;
    g->max_string        = 4096;
    g->max_array_items   = 100;
    g->max_object_props  = 50;
    g->namespace_filter  = NULL;
    g->trace_dir         = NULL;
    g->max_traces        = 100;
    g->max_trace_age_seconds = 86400;
    g->trace             = NULL;
    g->next_frame_id     = 0;
    g->request_start_us  = 0;
    memset(&g->scratch, 0, sizeof(g->scratch));
    memset(g->frame_id_at, 0, sizeof(g->frame_id_at));
}

/* ------------------------------------------------------------------------ */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------ */

static inline uint64_t periscope_now_us_monotonic(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (uint64_t)ts.tv_sec * 1000000ULL + (uint64_t)(ts.tv_nsec / 1000);
}

static inline uint64_t periscope_now_us_wall(void)
{
    struct timeval tv;
    gettimeofday(&tv, NULL);
    return (uint64_t)tv.tv_sec * 1000000ULL + (uint64_t)tv.tv_usec;
}

static inline void periscope_current_options(periscope_capture_options_t *opts)
{
    opts->max_depth        = (int)PERISCOPE_G(max_depth);
    opts->max_string       = (int)PERISCOPE_G(max_string);
    opts->max_array_items  = (int)PERISCOPE_G(max_array_items);
    opts->max_object_props = (int)PERISCOPE_G(max_object_props);
}

static inline void periscope_flush_scratch(void)
{
    smart_str *buf = &PERISCOPE_G(scratch);
    if (buf->s && ZSTR_LEN(buf->s) > 0) {
        fwrite(ZSTR_VAL(buf->s), 1, ZSTR_LEN(buf->s), stderr);
    }
    if (buf->s) ZSTR_LEN(buf->s) = 0;
}

/* Render a function name into a fresh malloc'd C string. Caller frees. */
static char *render_function_name(const zend_function *func)
{
    smart_str s = {0};
    periscope_capture_function_name(&s, func);
    smart_str_0(&s);
    if (!s.s) return NULL;
    char *out = strdup(ZSTR_VAL(s.s));
    smart_str_free(&s);
    return out;
}

static char *render_args_summary(const zend_function *func,
                                 zend_execute_data *ex,
                                 const periscope_capture_options_t *opts)
{
    smart_str s = {0};
    uint32_t n = ZEND_CALL_NUM_ARGS(ex);
    for (uint32_t i = 0; i < n; i++) {
        if (i > 0) smart_str_appendl(&s, ", ", 2);
        zval *arg = ZEND_CALL_ARG(ex, i + 1);
        periscope_capture_arg(&s, func, i, arg, opts);
    }
    smart_str_0(&s);
    char *out = s.s ? strdup(ZSTR_VAL(s.s)) : strdup("");
    smart_str_free(&s);
    return out;
}

static char *render_value_summary(const zval *value,
                                  const periscope_capture_options_t *opts)
{
    smart_str s = {0};
    periscope_capture_value(&s, value, opts);
    smart_str_0(&s);
    char *out = s.s ? strdup(ZSTR_VAL(s.s)) : strdup("");
    smart_str_free(&s);
    return out;
}

/* ------------------------------------------------------------------------ */
/* Trace retention                                                           */
/* ------------------------------------------------------------------------ */

typedef struct {
    char path[1024];
    time_t mtime;
} periscope_trace_entry_t;

static int trace_entry_cmp_newest_first(const void *a, const void *b)
{
    time_t ma = ((const periscope_trace_entry_t *)a)->mtime;
    time_t mb = ((const periscope_trace_entry_t *)b)->mtime;
    if (ma > mb) return -1;
    if (ma < mb) return  1;
    return 0;
}

/* Sweep dir, deleting .cptrace files older than max_age_secs and any beyond
 * the newest max_keep entries. Best-effort; failures are silent (a failed
 * unlink shouldn't take down a debug session). */
static void periscope_trace_retention_sweep(const char *dir,
                                            long max_keep,
                                            long max_age_secs)
{
    if (!dir || !*dir) return;
    if (max_keep <= 0 && max_age_secs <= 0) return;

    DIR *d = opendir(dir);
    if (!d) return;

    /* Bounded scratch — we keep at most 4096 entries. Anyone with more than
     * that already needs manual cleanup. */
    enum { MAX_SCAN = 4096 };
    periscope_trace_entry_t *entries = (periscope_trace_entry_t *)
        emalloc(sizeof(periscope_trace_entry_t) * MAX_SCAN);
    if (!entries) {
        closedir(d);
        return;
    }
    size_t n = 0;
    time_t now = time(NULL);

    struct dirent *ent;
    while ((ent = readdir(d)) != NULL && n < MAX_SCAN) {
        const char *name = ent->d_name;
        size_t name_len = strlen(name);
        if (name_len < 9) continue;
        if (memcmp(name + name_len - 8, ".cptrace", 8) != 0) continue;

        char full[1024];
        int written = snprintf(full, sizeof(full), "%s/%s", dir, name);
        if (written <= 0 || (size_t)written >= sizeof(full)) continue;

        struct stat st;
        if (stat(full, &st) != 0) continue;
        if (!S_ISREG(st.st_mode)) continue;

        /* Age-based deletion happens up front so it doesn't count toward keep cap */
        if (max_age_secs > 0 && (now - st.st_mtime) > max_age_secs) {
            unlink(full);
            continue;
        }

        memcpy(entries[n].path, full, (size_t)written + 1);
        entries[n].mtime = st.st_mtime;
        n++;
    }
    closedir(d);

    if (max_keep > 0 && n > (size_t)max_keep) {
        qsort(entries, n, sizeof(*entries), trace_entry_cmp_newest_first);
        for (size_t i = (size_t)max_keep; i < n; i++) {
            unlink(entries[i].path);
        }
    }

    efree(entries);
}

/* ------------------------------------------------------------------------ */
/* Observer                                                                  */
/* ------------------------------------------------------------------------ */

static void periscope_fcall_begin(zend_execute_data *ex)
{
    if (ex == NULL || ex->func == NULL) return;
    if (PERISCOPE_G(disabled)) return;

    int d = PERISCOPE_G(depth);
    if (d < PERISCOPE_MAX_DEPTH) {
        PERISCOPE_G(enter_us)[d] = periscope_now_us_monotonic();
        PERISCOPE_G(frame_id_at)[d] = ++PERISCOPE_G(next_frame_id);
    }
    PERISCOPE_G(depth) = d + 1;

    /* stderr output (Phase 2/3 behaviour preserved) */
    smart_str *buf = &PERISCOPE_G(scratch);
    periscope_capture_options_t opts;
    periscope_current_options(&opts);

    smart_str_appendl(buf, "[periscope] enter ", sizeof("[periscope] enter ") - 1);
    periscope_capture_function_name(buf, ex->func);
    smart_str_appendc(buf, '(');

    uint32_t arg_count = ZEND_CALL_NUM_ARGS(ex);
    for (uint32_t i = 0; i < arg_count; i++) {
        if (i > 0) smart_str_appendl(buf, ", ", 2);
        zval *arg = ZEND_CALL_ARG(ex, i + 1);
        periscope_capture_arg(buf, ex->func, i, arg, &opts);
    }

    char depthbuf[32];
    int n = snprintf(depthbuf, sizeof(depthbuf), ") @depth=%d\n", d + 1);
    smart_str_appendl(buf, depthbuf, n);

    periscope_flush_scratch();
}

static void periscope_fcall_end(zend_execute_data *ex, zval *retval)
{
    if (ex == NULL || ex->func == NULL) return;
    if (PERISCOPE_G(disabled)) return;

    int d = PERISCOPE_G(depth) - 1;
    if (d < 0) d = 0;
    PERISCOPE_G(depth) = d;

    uint64_t now = periscope_now_us_monotonic();
    uint64_t enter = (d < PERISCOPE_MAX_DEPTH) ? PERISCOPE_G(enter_us)[d] : 0;
    double elapsed_ms = (enter != 0) ? (double)(now - enter) / 1000.0 : -1.0;

    /* Trace writer — append the frame */
    if (PERISCOPE_G(trace) != NULL && d < PERISCOPE_MAX_DEPTH) {
        uint32_t frame_id  = PERISCOPE_G(frame_id_at)[d];
        uint32_t parent_id = (d > 0) ? PERISCOPE_G(frame_id_at)[d - 1] : 0;
        periscope_capture_options_t opts;
        periscope_current_options(&opts);

        char *fname = render_function_name(ex->func);
        char *args  = render_args_summary(ex->func, ex, &opts);
        char *ret   = render_value_summary(retval, &opts);

        const char *file = (ex->func->type == ZEND_USER_FUNCTION
                             && ex->func->op_array.filename)
            ? ZSTR_VAL(ex->func->op_array.filename) : "";
        uint32_t line = (ex->func->type == ZEND_USER_FUNCTION)
            ? ex->func->op_array.line_start : 0;

        uint64_t enter_offset = (enter > PERISCOPE_G(request_start_us))
            ? (enter - PERISCOPE_G(request_start_us)) : 0;
        uint64_t exit_offset  = (now > PERISCOPE_G(request_start_us))
            ? (now - PERISCOPE_G(request_start_us)) : 0;

        periscope_trace_frame(
            PERISCOPE_G(trace),
            frame_id, parent_id,
            fname ? fname : "",
            file, line,
            enter_offset, exit_offset,
            (uint32_t)(d + 1),
            args ? args : "",
            ret ? ret : "");

        free(fname);
        free(args);
        free(ret);
    }

    /* stderr output (Phase 2/3 behaviour preserved) */
    smart_str *buf = &PERISCOPE_G(scratch);
    periscope_capture_options_t opts;
    periscope_current_options(&opts);

    smart_str_appendl(buf, "[periscope] exit  ", sizeof("[periscope] exit  ") - 1);
    periscope_capture_function_name(buf, ex->func);
    periscope_capture_return_type(buf, ex->func);
    smart_str_appendl(buf, " -> ", 4);
    periscope_capture_value(buf, retval, &opts);

    char tail[64];
    int n;
    if (elapsed_ms >= 0) {
        n = snprintf(tail, sizeof(tail), " (%.3fms) @depth=%d\n", elapsed_ms, d + 1);
    } else {
        n = snprintf(tail, sizeof(tail), " @depth=%d\n", d + 1);
    }
    smart_str_appendl(buf, tail, n);
    periscope_flush_scratch();
}

static zend_observer_fcall_handlers periscope_observer_init(zend_execute_data *ex)
{
    zend_observer_fcall_handlers handlers = {NULL, NULL};
    if (ex == NULL || !periscope_filter_should_observe(ex->func)) return handlers;
    handlers.begin = periscope_fcall_begin;
    handlers.end   = periscope_fcall_end;
    return handlers;
}

/* ------------------------------------------------------------------------ */
/* Module hooks                                                              */
/* ------------------------------------------------------------------------ */

static PHP_MINIT_FUNCTION(periscope)
{
    ZEND_INIT_MODULE_GLOBALS(periscope, php_periscope_init_globals, NULL);
    REGISTER_INI_ENTRIES();

    const char *env = getenv("PERISCOPE_DISABLE");
    if (env && env[0] != '\0' && env[0] != '0') {
        PERISCOPE_G(disabled) = true;
    }

    zend_observer_fcall_register(periscope_observer_init);

    fprintf(stderr, "periscope loaded\n");
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(periscope)
{
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

/* Per-request `X-Periscope-Mode` header lets the UI flip modes without
 * editing php.ini. Values:
 *   off       — disable all capture for this request (kill switch)
 *   full      — force full capture (overrides INI/env if disabled there)
 *   anything else — leave INI/env settings untouched
 *
 * The UI's "Re-run with periscope off" button uses this. */
static void periscope_apply_mode_header(void)
{
    zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(server) != IS_ARRAY) {
        return;
    }
    zval *hdr = zend_hash_str_find(
        Z_ARRVAL_P(server),
        "HTTP_X_PERISCOPE_MODE", sizeof("HTTP_X_PERISCOPE_MODE") - 1);
    if (hdr == NULL || Z_TYPE_P(hdr) != IS_STRING) {
        return;
    }
    const char *v = Z_STRVAL_P(hdr);
    if (strcasecmp(v, "off") == 0 || strcasecmp(v, "kill") == 0) {
        PERISCOPE_G(disabled) = true;
    } else if (strcasecmp(v, "full") == 0 || strcasecmp(v, "on") == 0) {
        PERISCOPE_G(disabled) = false;
    }
}

static PHP_RINIT_FUNCTION(periscope)
{
    PERISCOPE_G(depth) = 0;
    PERISCOPE_G(next_frame_id) = 0;
    PERISCOPE_G(request_start_us) = periscope_now_us_monotonic();

    periscope_apply_mode_header();

    if (PERISCOPE_G(scratch).s == NULL) {
        smart_str_alloc(&PERISCOPE_G(scratch), 4096, 0);
        smart_str_0(&PERISCOPE_G(scratch));
        ZSTR_LEN(PERISCOPE_G(scratch).s) = 0;
    }

    /* Open a trace if periscope.trace_dir is set */
    PERISCOPE_G(trace) = NULL;
    const char *trace_dir = PERISCOPE_G(trace_dir);
    if (trace_dir && trace_dir[0] != '\0' && !PERISCOPE_G(disabled)) {
        /* Cheap retention sweep before we add another trace */
        periscope_trace_retention_sweep(
            trace_dir,
            (long)PERISCOPE_G(max_traces),
            (long)PERISCOPE_G(max_trace_age_seconds));

        char cwd[1024];
        if (getcwd(cwd, sizeof(cwd)) == NULL) cwd[0] = '\0';

        const char *entry_point = "";
        if (SG(request_info).path_translated) {
            entry_point = SG(request_info).path_translated;
        }

        PERISCOPE_G(trace) = periscope_trace_open(
            trace_dir,
            PHP_VERSION,
            PHP_PERISCOPE_VERSION,
            sapi_module.name ? sapi_module.name : "",
            entry_point,
            cwd,
            periscope_now_us_wall(),
            (uint32_t)getpid());
    }

    /* Best-effort: open the daemon link if PERISCOPE_DAEMON_SOCKET is set.
     * Silent no-op when the daemon isn't running. */
    periscope_daemon_link_open(NULL);

    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(periscope)
{
    if (PERISCOPE_G(trace) != NULL) {
        uint64_t now = periscope_now_us_monotonic();
        uint64_t duration = (now > PERISCOPE_G(request_start_us))
            ? (now - PERISCOPE_G(request_start_us)) : 0;

        const char *path = periscope_trace_close(PERISCOPE_G(trace), duration);
        if (path) {
            fprintf(stderr, "[periscope] trace written: %s\n", path);

            /* Notify any subscribed UI tab via the daemon. The "request_id"
             * is the trace file's basename without extension — stable, unique,
             * and what the HTTP API uses too. Best-effort: link may be
             * inactive (daemon not running) which is fine. */
            if (periscope_daemon_link_active()) {
                const char *base = strrchr(path, '/');
                base = base ? base + 1 : path;
                char id[256];
                size_t blen = strlen(base);
                if (blen > 8 && strcmp(base + blen - 8, ".cptrace") == 0) {
                    blen -= 8;
                }
                if (blen >= sizeof(id)) blen = sizeof(id) - 1;
                memcpy(id, base, blen);
                id[blen] = '\0';
                periscope_daemon_link_send_request_finished(id, path, duration);
            }
        }
        periscope_trace_free(PERISCOPE_G(trace));
        PERISCOPE_G(trace) = NULL;
    }
    periscope_daemon_link_close();
    smart_str_free(&PERISCOPE_G(scratch));
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(periscope)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "periscope support", "enabled");
    php_info_print_table_row(2, "Version", PHP_PERISCOPE_VERSION);
    php_info_print_table_end();

    DISPLAY_INI_ENTRIES();
}

zend_module_entry periscope_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_PERISCOPE_EXTNAME,
    periscope_userland_functions,
    PHP_MINIT(periscope),
    PHP_MSHUTDOWN(periscope),
    PHP_RINIT(periscope),
    PHP_RSHUTDOWN(periscope),
    PHP_MINFO(periscope),
    PHP_PERISCOPE_VERSION,
    PHP_MODULE_GLOBALS(periscope),
    NULL, NULL, NULL,
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_PERISCOPE
ZEND_GET_MODULE(periscope)
#endif
