#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "Zend/zend_observer.h"
#include "Zend/zend_attributes.h"
#include "Zend/zend_extensions.h"
#include "Zend/zend_closures.h"

#include <stdbool.h>
#include <stdint.h>
#include <inttypes.h>
#include <time.h>

#include "php_periscope.h"
#include "periscope_filter.h"

ZEND_DECLARE_MODULE_GLOBALS(periscope)

/* ------------------------------------------------------------------------ */
/* INI                                                                       */
/* ------------------------------------------------------------------------ */

PHP_INI_BEGIN()
    STD_PHP_INI_BOOLEAN(
        "periscope.skip_internal", "1",
        PHP_INI_SYSTEM, OnUpdateBool,
        skip_internal, zend_periscope_globals, periscope_globals)
PHP_INI_END()

static void php_periscope_init_globals(zend_periscope_globals *g)
{
    g->skip_internal = true;
    g->depth = 0;
    /* enter_us[] is zero-initialised by ZEND_INIT_MODULE_GLOBALS */
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

/* Best-effort one-line value summary. Phase 3 replaces this with a full
 * recursive snapshot serialiser; here we only need enough for stderr logs. */
static void periscope_dump_zval(const zval *z)
{
    if (z == NULL) {
        fprintf(stderr, "<null-zval>");
        return;
    }

    switch (Z_TYPE_P(z)) {
        case IS_UNDEF:
            fprintf(stderr, "undef");
            break;
        case IS_NULL:
            fprintf(stderr, "null");
            break;
        case IS_TRUE:
            fprintf(stderr, "bool(true)");
            break;
        case IS_FALSE:
            fprintf(stderr, "bool(false)");
            break;
        case IS_LONG:
            fprintf(stderr, "int(%lld)", (long long)Z_LVAL_P(z));
            break;
        case IS_DOUBLE:
            fprintf(stderr, "float(%g)", Z_DVAL_P(z));
            break;
        case IS_STRING: {
            size_t len = Z_STRLEN_P(z);
            const char *val = Z_STRVAL_P(z);
            size_t shown = len > 32 ? 32 : len;
            fprintf(stderr, "string(%zu) \"", len);
            for (size_t i = 0; i < shown; i++) {
                unsigned char c = (unsigned char)val[i];
                if (c == '"' || c == '\\') {
                    fputc('\\', stderr);
                    fputc(c, stderr);
                } else if (c < 0x20 || c == 0x7f) {
                    fprintf(stderr, "\\x%02x", c);
                } else {
                    fputc(c, stderr);
                }
            }
            if (len > shown) {
                fprintf(stderr, "...");
            }
            fputc('"', stderr);
            break;
        }
        case IS_ARRAY:
            fprintf(stderr, "array(%u)", zend_hash_num_elements(Z_ARRVAL_P(z)));
            break;
        case IS_OBJECT: {
            zend_class_entry *ce = Z_OBJCE_P(z);
            fprintf(stderr, "object(%s)#%u",
                    ce ? ZSTR_VAL(ce->name) : "?",
                    Z_OBJ_HANDLE_P(z));
            break;
        }
        case IS_RESOURCE:
            fprintf(stderr, "resource(#%ld)", (long)Z_RES_HANDLE_P(z));
            break;
        case IS_REFERENCE:
            fprintf(stderr, "ref->");
            periscope_dump_zval(Z_REFVAL_P(z));
            break;
        default:
            fprintf(stderr, "type(%d)", (int)Z_TYPE_P(z));
            break;
    }
}

static void periscope_dump_function_name(const zend_function *func)
{
    if (func == NULL) {
        fprintf(stderr, "<unknown>");
        return;
    }

    if (func->common.scope) {
        fprintf(stderr, "%s::", ZSTR_VAL(func->common.scope->name));
    }

    if (func->common.function_name) {
        fprintf(stderr, "%s", ZSTR_VAL(func->common.function_name));
    } else {
        fprintf(stderr, "{main}");
    }
}

/* ------------------------------------------------------------------------ */
/* Observer                                                                  */
/* ------------------------------------------------------------------------ */

static void periscope_dump_arg_info(const zend_function *func, uint32_t i)
{
    if (func == NULL || func->common.arg_info == NULL) return;
    if (i >= func->common.num_args) return;

    zend_arg_info *info = &func->common.arg_info[i];

    /* Declared type (if any) */
    if (ZEND_TYPE_IS_SET(info->type)) {
        zend_string *t = zend_type_to_string(info->type);
        if (t) {
            fprintf(stderr, "%s ", ZSTR_VAL(t));
            zend_string_release(t);
        }
    }

    /* Variadic / by-ref markers */
    if (ZEND_ARG_SEND_MODE(info)) fputc('&', stderr);
    if (ZEND_ARG_IS_VARIADIC(info)) fprintf(stderr, "...");

    /* Parameter name */
    if (info->name) {
        if (func->type == ZEND_USER_FUNCTION) {
            fprintf(stderr, "$%s", ZSTR_VAL(info->name));
        } else {
            /* Internal functions store name as char* not zend_string* */
            fprintf(stderr, "$%s", (const char *)info->name);
        }
    }
}

static void periscope_fcall_begin(zend_execute_data *ex)
{
    if (ex == NULL || ex->func == NULL) return;

    int d = PERISCOPE_G(depth);
    if (d < PERISCOPE_MAX_DEPTH) {
        PERISCOPE_G(enter_us)[d] = periscope_now_us();
    }
    PERISCOPE_G(depth) = d + 1;

    fprintf(stderr, "[periscope] enter ");
    periscope_dump_function_name(ex->func);
    fputc('(', stderr);

    uint32_t arg_count = ZEND_CALL_NUM_ARGS(ex);
    for (uint32_t i = 0; i < arg_count; i++) {
        if (i > 0) fprintf(stderr, ", ");
        periscope_dump_arg_info(ex->func, i);
        if (i < ex->func->common.num_args && ex->func->common.arg_info && ex->func->common.arg_info[i].name) {
            fprintf(stderr, " = ");
        }
        zval *arg = ZEND_CALL_ARG(ex, i + 1);
        periscope_dump_zval(arg);
    }
    fprintf(stderr, ") @depth=%d\n", d + 1);
}

static void periscope_fcall_end(zend_execute_data *ex, zval *retval)
{
    if (ex == NULL || ex->func == NULL) return;

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

    fprintf(stderr, "[periscope] exit  ");
    periscope_dump_function_name(ex->func);

    /* Declared return type if present */
    if ((ex->func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE)
        && ex->func->common.arg_info != NULL) {
        /* arg_info[-1] is the return-type slot for both user & internal funcs */
        zend_arg_info *ret_info = &ex->func->common.arg_info[-1];
        if (ZEND_TYPE_IS_SET(ret_info->type)) {
            zend_string *t = zend_type_to_string(ret_info->type);
            if (t) {
                fprintf(stderr, ": %s", ZSTR_VAL(t));
                zend_string_release(t);
            }
        }
    }

    fprintf(stderr, " -> ");
    periscope_dump_zval(retval);
    if (elapsed_ms >= 0) {
        fprintf(stderr, " (%.3fms)", elapsed_ms);
    }
    fprintf(stderr, " @depth=%d\n", d + 1);
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
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(periscope)
{
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
    NULL,                       /* globals ctor (handled in MINIT via ZEND_INIT_MODULE_GLOBALS) */
    NULL,                       /* globals dtor */
    NULL,                       /* post deactivate */
    STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_PERISCOPE
ZEND_GET_MODULE(periscope)
#endif
