#ifndef PERISCOPE_FILTER_H
#define PERISCOPE_FILTER_H

#include "php.h"
#include <stdbool.h>

/* Returns true if this function should be observed (begin/end emitted),
 * false if it should be silently skipped. Honors periscope.skip_internal. */
bool periscope_filter_should_observe(const zend_function *func);

#endif /* PERISCOPE_FILTER_H */
