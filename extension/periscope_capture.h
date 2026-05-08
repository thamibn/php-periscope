#ifndef PERISCOPE_CAPTURE_H
#define PERISCOPE_CAPTURE_H

#include "php.h"
#include "zend_smart_str.h"
#include <stdbool.h>

typedef struct {
    int max_depth;
    int max_string;
    int max_array_items;
    int max_object_props;
} periscope_capture_options_t;

/* Append a recursive snapshot of `z` into the provided smart_str buffer.
 * Honors depth/size/items limits. Cycle-safe. Bypasses magic __get. */
void periscope_capture_value(smart_str *buf,
                             const zval *z,
                             const periscope_capture_options_t *opts);

/* Single-line summary of a function name (Foo::bar, {main}, {closure@file:line}). */
void periscope_capture_function_name(smart_str *buf, const zend_function *func);

/* Append the declared param signature + runtime value for arg index i. */
void periscope_capture_arg(smart_str *buf,
                           const zend_function *func,
                           uint32_t i,
                           const zval *value,
                           const periscope_capture_options_t *opts);

/* Append "[: <returntype>]" if declared. */
void periscope_capture_return_type(smart_str *buf, const zend_function *func);

#endif /* PERISCOPE_CAPTURE_H */
