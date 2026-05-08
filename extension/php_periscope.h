#ifndef PHP_PERISCOPE_H
#define PHP_PERISCOPE_H

#include <stdbool.h>
#include <stdint.h>

#define PHP_PERISCOPE_VERSION "0.1.0-dev"
#define PHP_PERISCOPE_EXTNAME "periscope"

/* Hard cap on tracked recursion depth. Frames deeper than this still execute
 * but are not timed (we just stop pushing onto the per-frame stack). */
#define PERISCOPE_MAX_DEPTH 4096

ZEND_BEGIN_MODULE_GLOBALS(periscope)
    bool skip_internal;
    int  depth;
    uint64_t enter_us[PERISCOPE_MAX_DEPTH];
ZEND_END_MODULE_GLOBALS(periscope)

ZEND_EXTERN_MODULE_GLOBALS(periscope)

#define PERISCOPE_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(periscope, v)

extern zend_module_entry periscope_module_entry;
#define phpext_periscope_ptr &periscope_module_entry

#endif /* PHP_PERISCOPE_H */
