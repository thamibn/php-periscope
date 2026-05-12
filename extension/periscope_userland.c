#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/json/php_json.h"
#include "Zend/zend_API.h"
#include "Zend/zend_smart_str.h"
#include <time.h>
#include <sys/time.h>

#include "php_periscope.h"
#include "periscope_userland.h"
#include "periscope_trace.h"

/* periscope_checkpoint(string $label, mixed $context = null): bool
 *
 * User-callable timeline marker. Use anywhere in code where you want a named
 * point on the timeline that isn't tied to a Laravel event ("after auth",
 * "before charge", etc.). Implemented as a `checkpoint` observability event. */
PHP_FUNCTION(periscope_checkpoint)
{
    zend_string *label;
    zval        *context = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(label)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL_OR_NULL(context)
    ZEND_PARSE_PARAMETERS_END();

    if (PERISCOPE_G(trace) == NULL || !PERISCOPE_G(enabled)) {
        RETURN_FALSE;
    }

    /* Build a tiny JSON payload {"label": "...", "context": ...} */
    zval payload_zval;
    array_init(&payload_zval);
    add_assoc_str(&payload_zval, "label", zend_string_copy(label));
    if (context != NULL) {
        Z_TRY_ADDREF_P(context);
        add_assoc_zval(&payload_zval, "context", context);
    }

    smart_str payload_buf = {0};
    if (php_json_encode(&payload_buf, &payload_zval, 0) == FAILURE) {
        zval_ptr_dtor(&payload_zval);
        smart_str_free(&payload_buf);
        RETURN_FALSE;
    }
    smart_str_0(&payload_buf);

    static uint32_t s_checkpoint_id = 1;
    uint32_t event_id = s_checkpoint_id++;

    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    uint64_t now = (uint64_t)ts.tv_sec * 1000000ULL + (uint64_t)(ts.tv_nsec / 1000);
    uint64_t at_micros = (now > PERISCOPE_G(request_start_us))
        ? (now - PERISCOPE_G(request_start_us)) : 0;

    int d = PERISCOPE_G(depth) - 1;
    if (d < 0) d = 0;
    uint32_t in_frame_id = (d < PERISCOPE_MAX_DEPTH)
        ? PERISCOPE_G(frame_id_at)[d] : 0;

    periscope_trace_event(
        PERISCOPE_G(trace),
        event_id, at_micros, in_frame_id,
        "checkpoint",
        payload_buf.s ? ZSTR_VAL(payload_buf.s) : "",
        NULL);

    zval_ptr_dtor(&payload_zval);
    smart_str_free(&payload_buf);
    RETURN_TRUE;
}

/* periscope_record_event(string $type, array $payload, ?array $callSite = null): bool */
PHP_FUNCTION(periscope_record_event)
{
    zend_string *type;
    HashTable   *payload;
    HashTable   *call_site = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(type)
        Z_PARAM_ARRAY_HT(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT_OR_NULL(call_site)
    ZEND_PARSE_PARAMETERS_END();

    if (PERISCOPE_G(trace) == NULL || !PERISCOPE_G(enabled)) {
        RETURN_FALSE;
    }

    /* Encode payload as JSON */
    smart_str payload_buf = {0};
    zval payload_zval;
    ZVAL_ARR(&payload_zval, payload);
    if (php_json_encode(&payload_buf, &payload_zval, 0) == FAILURE) {
        smart_str_free(&payload_buf);
        RETURN_FALSE;
    }
    smart_str_0(&payload_buf);

    /* Encode optional callSite as JSON */
    smart_str cs_buf = {0};
    if (call_site != NULL) {
        zval cs_zval;
        ZVAL_ARR(&cs_zval, call_site);
        if (php_json_encode(&cs_buf, &cs_zval, 0) == SUCCESS) {
            smart_str_0(&cs_buf);
        }
    }

    /* Compute event id, timestamp, current frame id */
    static uint32_t s_next_event_id = 1;
    uint32_t event_id = s_next_event_id++;

    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    uint64_t now = (uint64_t)ts.tv_sec * 1000000ULL + (uint64_t)(ts.tv_nsec / 1000);
    uint64_t at_micros = (now > PERISCOPE_G(request_start_us))
        ? (now - PERISCOPE_G(request_start_us)) : 0;

    int d = PERISCOPE_G(depth) - 1;
    if (d < 0) d = 0;
    uint32_t in_frame_id = (d < PERISCOPE_MAX_DEPTH)
        ? PERISCOPE_G(frame_id_at)[d] : 0;

    periscope_trace_event(
        PERISCOPE_G(trace),
        event_id,
        at_micros,
        in_frame_id,
        ZSTR_VAL(type),
        payload_buf.s ? ZSTR_VAL(payload_buf.s) : "",
        cs_buf.s ? ZSTR_VAL(cs_buf.s) : NULL);

    smart_str_free(&payload_buf);
    smart_str_free(&cs_buf);

    RETURN_TRUE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_periscope_record_event, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, type, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, callSite, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_periscope_checkpoint, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, label, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, context, IS_MIXED, 1, "null")
ZEND_END_ARG_INFO()

const zend_function_entry periscope_userland_functions[] = {
    PHP_FE(periscope_record_event, arginfo_periscope_record_event)
    PHP_FE(periscope_checkpoint,   arginfo_periscope_checkpoint)
    PHP_FE_END
};
