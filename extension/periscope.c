#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "Zend/zend_observer.h"
#include "Zend/zend_smart_str.h"

#include <stdbool.h>
#include <stdint.h>
#include <inttypes.h>
#include <stdlib.h>
#include <time.h>

#include "php_periscope.h"
#include "periscope_filter.h"
#include "periscope_capture.h"

ZEND_DECLARE_MODULE_GLOBALS(periscope)

/* ------------------------------------------------------------------------ */
/* INI                                                                       */
/* ------------------------------------------------------------------------ */

PHP_INI_BEGIN()
    STD_PHP_INI_BOOLEAN(
        "periscope.skip_internal", "1",
        PHP_INI_SYSTEM, OnUpdateBool,
        skip_internal, zend_periscope_globals, periscope_globals)

    STD_PHP_INI_BOOLEAN(
        "periscope.disabled", "0",
        PHP_INI_SYSTEM | PHP_INI_PERDIR, OnUpdateBool,
        disabled, zend_periscope_globals, periscope_globals)

    STD_PHP_INI_ENTRY(
        "periscope.max_depth", "5",
        PHP_INI_ALL, OnUpdateLong,
        max_depth, zend_periscope_globals, periscope_globals)

    STD_PHP_INI_ENTRY(
        "periscope.max_string", "4096",
        PHP_INI_ALL, OnUpdateLong,
        max_string, zend_periscope_globals, periscope_globals)

    STD_PHP_INI_ENTRY(
        "periscope.max_array_items", "100",
        PHP_INI_ALL, OnUpdateLong,
        max_array_items, zend_periscope_globals, periscope_globals)

    STD_PHP_INI_ENTRY(
        "periscope.max_object_props", "50",
        PHP_INI_ALL, OnUpdateLong,
        max_object_props, zend_periscope_globals, periscope_globals)

    STD_PHP_INI_ENTRY(
        "periscope.namespace_filter", "",
        PHP_INI_ALL, OnUpdateString,
        namespace_filter, zend_periscope_globals, periscope_globals)
PHP_INI_END()

static void php_periscope_init_globals(zend_periscope_globals *g)
{
    g->skip_internal = true;
    g->disabled = false;
    g->depth = 0;
    g->max_depth = 5;
    g->max_string = 4096;
    g->max_array_items = 100;
    g->max_object_props = 50;
    g->namespace_filter = NULL;
    memset(&g->scratch, 0, sizeof(g->scratch));
}

/* ------------------------------------------------------------------------ */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------ */

static inline uint64_t periscope_now_us(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (uint64_t)ts.tv_sec * 1000000ULL + (uint64_t)(ts.tv_nsec / 1000);
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
    /* Reset length without freeing the buffer — keeps capacity for reuse */
    if (buf->s) {
        ZSTR_LEN(buf->s) = 0;
    }
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
        PERISCOPE_G(enter_us)[d] = periscope_now_us();
    }
    PERISCOPE_G(depth) = d + 1;

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

    double elapsed_ms = -1.0;
    if (d < PERISCOPE_MAX_DEPTH) {
        uint64_t start = PERISCOPE_G(enter_us)[d];
        if (start != 0) {
            uint64_t now = periscope_now_us();
            elapsed_ms = (double)(now - start) / 1000.0;
        }
    }

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
    if (ex == NULL || !periscope_filter_should_observe(ex->func)) {
        return handlers;
    }
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

    /* PERISCOPE_DISABLE=1 env wins over INI; useful as a per-process kill switch */
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

static PHP_RINIT_FUNCTION(periscope)
{
    PERISCOPE_G(depth) = 0;
    /* Pre-grow scratch buffer to avoid mid-call reallocations on hot paths */
    if (PERISCOPE_G(scratch).s == NULL) {
        smart_str_alloc(&PERISCOPE_G(scratch), 4096, 0);
        smart_str_0(&PERISCOPE_G(scratch));
        ZSTR_LEN(PERISCOPE_G(scratch).s) = 0;
    }
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(periscope)
{
    /* Free scratch on full request end so we don't accumulate across requests
     * if buffer grew very large for one outlier */
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
    NULL,                       /* functions */
    PHP_MINIT(periscope),
    PHP_MSHUTDOWN(periscope),
    PHP_RINIT(periscope),
    PHP_RSHUTDOWN(periscope),
    PHP_MINFO(periscope),
    PHP_PERISCOPE_VERSION,
    PHP_MODULE_GLOBALS(periscope),
    NULL,
    NULL,
    NULL,
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_PERISCOPE
ZEND_GET_MODULE(periscope)
#endif
