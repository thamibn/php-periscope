#ifndef PERISCOPE_USERLAND_H
#define PERISCOPE_USERLAND_H

#include "php.h"

/* Userland function declarations for the periscope extension.
 * Currently exposes:
 *   - periscope_record_event(string $type, array $payload, ?array $callSite = null): bool
 *
 * Used by the Laravel adapter (and any other framework adapter) to emit
 * observability events into the active trace. */

PHP_FUNCTION(periscope_record_event);

extern const zend_function_entry periscope_userland_functions[];

#endif /* PERISCOPE_USERLAND_H */
