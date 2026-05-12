#ifndef PHP_PERISCOPE_H
#define PHP_PERISCOPE_H

#include "Zend/zend_smart_str.h"
#include <stdbool.h>
#include <stdint.h>

#include "periscope_trace.h"  /* opaque trace writer handle */

#define PHP_PERISCOPE_VERSION "0.1.0-dev"
#define PHP_PERISCOPE_EXTNAME "periscope"

/* Hard cap on tracked recursion depth. */
#define PERISCOPE_MAX_DEPTH 4096

ZEND_BEGIN_MODULE_GLOBALS(periscope)
    /* INI-controlled */
    bool skip_internal;
    bool enabled;
    bool verbose;             /* mirror enter/exit/trace lines to stderr */
    zend_long max_depth;
    zend_long max_string;
    zend_long max_array_items;
    zend_long max_object_props;
    char *namespace_filter;
    char *path_ignore;        /* comma-separated request URI prefixes to skip */
    char *trace_dir;          /* empty/NULL = no on-disk trace */
    zend_long max_traces;     /* keep newest N; 0 = unlimited */
    zend_long max_trace_age_seconds;  /* delete older; 0 = never expire */

    /* Runtime */
    int  depth;
    uint64_t enter_us[PERISCOPE_MAX_DEPTH];
    uint32_t frame_id_at[PERISCOPE_MAX_DEPTH];
    uint64_t request_start_us;
    uint32_t next_frame_id;
    smart_str scratch;

    /* Cap'n Proto trace (NULL when trace_dir is empty) */
    periscope_trace_writer *trace;
ZEND_END_MODULE_GLOBALS(periscope)

ZEND_EXTERN_MODULE_GLOBALS(periscope)

#define PERISCOPE_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(periscope, v)

extern zend_module_entry periscope_module_entry;
#define phpext_periscope_ptr &periscope_module_entry

#endif /* PHP_PERISCOPE_H */
