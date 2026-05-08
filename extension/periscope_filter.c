#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_periscope.h"
#include "periscope_filter.h"

bool periscope_filter_should_observe(const zend_function *func)
{
    if (func == NULL) {
        return false;
    }

    if (PERISCOPE_G(skip_internal) && func->type == ZEND_INTERNAL_FUNCTION) {
        return false;
    }

    return true;
}
