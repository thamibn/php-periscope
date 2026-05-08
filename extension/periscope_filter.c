#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_periscope.h"
#include "periscope_filter.h"

#include <string.h>
#include <stdlib.h>

static bool starts_with(const char *haystack, size_t haystack_len,
                        const char *needle, size_t needle_len)
{
    if (needle_len == 0) return true;
    if (needle_len > haystack_len) return false;
    return memcmp(haystack, needle, needle_len) == 0;
}

/* Matches `name` against a comma-separated allowlist like "App\\,Acme\\".
 * Returns true if any non-empty prefix matches, OR if filter is empty/NULL. */
static bool namespace_filter_matches(const char *filter, const char *name, size_t name_len)
{
    if (filter == NULL || filter[0] == '\0') return true;

    const char *p = filter;
    while (*p) {
        const char *comma = strchr(p, ',');
        size_t plen = comma ? (size_t)(comma - p) : strlen(p);

        /* Trim leading spaces */
        while (plen > 0 && *p == ' ') { p++; plen--; }
        /* Trim trailing spaces */
        while (plen > 0 && p[plen - 1] == ' ') plen--;

        if (plen > 0 && starts_with(name, name_len, p, plen)) {
            return true;
        }

        if (!comma) break;
        p = comma + 1;
    }
    return false;
}

bool periscope_filter_should_observe(const zend_function *func)
{
    if (func == NULL) return false;

    /* Kill switch */
    if (PERISCOPE_G(disabled)) return false;

    /* Internal functions (default skipped) */
    if (PERISCOPE_G(skip_internal) && func->type == ZEND_INTERNAL_FUNCTION) {
        return false;
    }

    /* Namespace filter */
    const char *ns_filter = PERISCOPE_G(namespace_filter);
    if (ns_filter && ns_filter[0] != '\0') {
        /* Build the qualified name: scope::function or just function */
        const char *check = NULL;
        size_t check_len = 0;

        if (func->common.scope && func->common.scope->name) {
            check = ZSTR_VAL(func->common.scope->name);
            check_len = ZSTR_LEN(func->common.scope->name);
        } else if (func->common.function_name) {
            check = ZSTR_VAL(func->common.function_name);
            check_len = ZSTR_LEN(func->common.function_name);
        } else {
            /* {main} — always observe so the user has at least one frame */
            return true;
        }

        if (!namespace_filter_matches(ns_filter, check, check_len)) {
            return false;
        }
    }

    return true;
}
