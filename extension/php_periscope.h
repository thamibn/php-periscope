#ifndef PHP_PERISCOPE_H
#define PHP_PERISCOPE_H

#include "Zend/zend_smart_str.h"
#include <stdbool.h>
#include <stdint.h>

#define PHP_PERISCOPE_VERSION "0.1.0-dev"
#define PHP_PERISCOPE_EXTNAME "periscope"

/* Hard cap on tracked recursion depth. Frames deeper than this still execute
 * but are not timed (we just stop pushing onto the per-frame stack). */
#define PERISCOPE_MAX_DEPTH 4096

ZEND_BEGIN_MODULE_GLOBALS(periscope)
    /* INI-controlled */
    bool skip_internal;
    bool disabled;            /* PERISCOPE_DISABLE env or periscope.disabled */
    zend_long max_depth;
    zend_long max_string;
    zend_long max_array_items;
    zend_long max_object_props;
    char *namespace_filter;

    /* Runtime */
    int  depth;
    uint64_t enter_us[PERISCOPE_MAX_DEPTH];
    smart_str scratch;        /* reusable buffer to avoid per-call malloc */
ZEND_END_MODULE_GLOBALS(periscope)

ZEND_EXTERN_MODULE_GLOBALS(periscope)

#define PERISCOPE_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(periscope, v)

extern zend_module_entry periscope_module_entry;
#define phpext_periscope_ptr &periscope_module_entry

#endif /* PHP_PERISCOPE_H */
