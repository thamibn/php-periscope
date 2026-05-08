#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "Zend/zend_API.h"
#include "Zend/zend_closures.h"
#include "Zend/zend_enum.h"
#include "Zend/zend_smart_str.h"
#include "Zend/zend_types.h"

#include <inttypes.h>

#include "periscope_capture.h"

/* Forward */
static void capture_recursive(smart_str *buf,
                              const zval *z,
                              int depth,
                              const periscope_capture_options_t *opts,
                              HashTable *visited);

/* ------------------------------------------------------------------------ */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------ */

static void append_quoted_string(smart_str *buf, const char *s, size_t len, int max_len)
{
    smart_str_appendl(buf, "string(", sizeof("string(") - 1);
    char numbuf[32];
    int n = snprintf(numbuf, sizeof(numbuf), "%zu", len);
    smart_str_appendl(buf, numbuf, n);
    smart_str_appendl(buf, ") \"", sizeof(") \"") - 1);

    size_t shown = (max_len > 0 && len > (size_t)max_len) ? (size_t)max_len : len;
    for (size_t i = 0; i < shown; i++) {
        unsigned char c = (unsigned char)s[i];
        if (c == '"' || c == '\\') {
            smart_str_appendc(buf, '\\');
            smart_str_appendc(buf, c);
        } else if (c < 0x20 || c == 0x7f) {
            char esc[8];
            int en = snprintf(esc, sizeof(esc), "\\x%02x", c);
            smart_str_appendl(buf, esc, en);
        } else {
            smart_str_appendc(buf, c);
        }
    }
    if (len > shown) {
        smart_str_appendl(buf, "…+", sizeof("…+") - 1);
        n = snprintf(numbuf, sizeof(numbuf), "%zu", len - shown);
        smart_str_appendl(buf, numbuf, n);
    }
    smart_str_appendc(buf, '"');
}

static bool object_has_get_magic(const zend_object *obj)
{
    if (!obj || !obj->ce) return false;
    return obj->ce->__get != NULL;
}

static bool object_is_enum(const zend_object *obj)
{
    if (!obj || !obj->ce) return false;
    return (obj->ce->ce_flags & ZEND_ACC_ENUM) != 0;
}

static bool object_is_closure(const zend_object *obj)
{
    if (!obj || !obj->ce) return false;
    return obj->ce == zend_ce_closure;
}

static void capture_array(smart_str *buf,
                          HashTable *ht,
                          int depth,
                          const periscope_capture_options_t *opts,
                          HashTable *visited)
{
    uint32_t total = ht ? zend_hash_num_elements(ht) : 0;
    char numbuf[32];
    int n;

    smart_str_appendl(buf, "array(", sizeof("array(") - 1);
    n = snprintf(numbuf, sizeof(numbuf), "%u", total);
    smart_str_appendl(buf, numbuf, n);
    smart_str_appendc(buf, ')');

    if (total == 0) {
        smart_str_appendl(buf, " []", sizeof(" []") - 1);
        return;
    }

    if (depth >= opts->max_depth) {
        smart_str_appendl(buf, " <…depth>", sizeof(" <…depth>") - 1);
        return;
    }

    smart_str_appendl(buf, " [", sizeof(" [") - 1);

    uint32_t shown = 0;
    uint32_t cap = (opts->max_array_items > 0) ? (uint32_t)opts->max_array_items : total;
    zend_string *str_key;
    zend_ulong num_key;
    zval *val;

    ZEND_HASH_FOREACH_KEY_VAL(ht, num_key, str_key, val) {
        if (shown >= cap) break;
        if (shown > 0) smart_str_appendl(buf, ", ", 2);
        if (str_key) {
            smart_str_appendc(buf, '"');
            smart_str_append(buf, str_key);
            smart_str_appendl(buf, "\": ", 3);
        } else {
            n = snprintf(numbuf, sizeof(numbuf), "%" PRId64 ": ", (int64_t)num_key);
            smart_str_appendl(buf, numbuf, n);
        }
        capture_recursive(buf, val, depth + 1, opts, visited);
        shown++;
    } ZEND_HASH_FOREACH_END();

    if (shown < total) {
        smart_str_appendl(buf, ", <…items+", sizeof(", <…items+") - 1);
        n = snprintf(numbuf, sizeof(numbuf), "%u", total - shown);
        smart_str_appendl(buf, numbuf, n);
        smart_str_appendc(buf, '>');
    }
    smart_str_appendc(buf, ']');
}

static void capture_object(smart_str *buf,
                           const zval *z,
                           int depth,
                           const periscope_capture_options_t *opts,
                           HashTable *visited)
{
    zend_object *obj = Z_OBJ_P(z);
    char numbuf[32];
    int n;

    /* Cycle detection — record handle, bail if revisiting */
    uint32_t handle = obj->handle;
    if (visited && zend_hash_index_exists(visited, (zend_ulong)handle)) {
        smart_str_appendl(buf, "<recursion ↻ #", sizeof("<recursion ↻ #") - 1);
        n = snprintf(numbuf, sizeof(numbuf), "%u", handle);
        smart_str_appendl(buf, numbuf, n);
        smart_str_appendc(buf, '>');
        return;
    }

    /* Enum — show case name and (if backed) value */
    if (object_is_enum(obj)) {
        smart_str_appendl(buf, "enum(", sizeof("enum(") - 1);
        smart_str_append(buf, obj->ce->name);
        smart_str_appendl(buf, "::", 2);
        zval *case_name = zend_enum_fetch_case_name(obj);
        if (case_name && Z_TYPE_P(case_name) == IS_STRING) {
            smart_str_append(buf, Z_STR_P(case_name));
        } else {
            smart_str_appendl(buf, "?", 1);
        }
        /* Only backed enums have a value worth printing */
        if (obj->ce->enum_backing_type != IS_UNDEF) {
            zval *case_val = zend_enum_fetch_case_value(obj);
            if (case_val && Z_TYPE_P(case_val) != IS_UNDEF && Z_TYPE_P(case_val) != IS_NULL) {
                smart_str_appendl(buf, " = ", 3);
                if (Z_TYPE_P(case_val) == IS_LONG) {
                    n = snprintf(numbuf, sizeof(numbuf), "%lld", (long long)Z_LVAL_P(case_val));
                    smart_str_appendl(buf, numbuf, n);
                } else if (Z_TYPE_P(case_val) == IS_STRING) {
                    append_quoted_string(buf, Z_STRVAL_P(case_val), Z_STRLEN_P(case_val),
                                         opts->max_string);
                }
            }
        }
        smart_str_appendc(buf, ')');
        return;
    }

    /* Closure */
    if (object_is_closure(obj)) {
        const zend_function *func = zend_get_closure_method_def(obj);
        smart_str_appendl(buf, "closure(", sizeof("closure(") - 1);
        if (func) {
            if (func->common.scope) {
                smart_str_append(buf, func->common.scope->name);
                smart_str_appendl(buf, "::", 2);
            }
            if (func->common.function_name) {
                smart_str_append(buf, func->common.function_name);
            } else {
                smart_str_appendl(buf, "{closure}", sizeof("{closure}") - 1);
            }
        }
        smart_str_appendc(buf, ')');
        return;
    }

    /* Header: object(ClassName)#handle */
    smart_str_appendl(buf, "object(", sizeof("object(") - 1);
    if (obj->ce && obj->ce->name) {
        smart_str_append(buf, obj->ce->name);
    } else {
        smart_str_appendc(buf, '?');
    }
    smart_str_appendl(buf, ")#", 2);
    n = snprintf(numbuf, sizeof(numbuf), "%u", handle);
    smart_str_appendl(buf, numbuf, n);

    /* Lazy/proxy detection — has __get magic, mark and skip dive */
    if (object_has_get_magic(obj)) {
        smart_str_appendl(buf, " <lazy>", sizeof(" <lazy>") - 1);
        return;
    }

    if (depth >= opts->max_depth) {
        smart_str_appendl(buf, " <…depth>", sizeof(" <…depth>") - 1);
        return;
    }

    /* Reach into property table directly — bypasses __get */
    HashTable *props = obj->properties;
    if (props == NULL) {
        /* Property hashtable not yet built — synthesise from declared slots
         * without calling user code. */
        smart_str_appendl(buf, " {", sizeof(" {") - 1);
        uint32_t shown = 0;
        uint32_t cap = (opts->max_object_props > 0) ? (uint32_t)opts->max_object_props : 0xFFFFFFFFu;
        if (obj->ce && obj->ce->properties_info.arData) {
            zend_string *prop_name;
            zend_property_info *info;
            ZEND_HASH_FOREACH_STR_KEY_PTR(&obj->ce->properties_info, prop_name, info) {
                if (!info || (info->flags & ZEND_ACC_STATIC)) continue;
                if (shown >= cap) break;
                if (shown > 0) smart_str_appendl(buf, ", ", 2);
                /* Visibility prefix */
                if (info->flags & ZEND_ACC_PRIVATE)   smart_str_appendc(buf, '-');
                else if (info->flags & ZEND_ACC_PROTECTED) smart_str_appendc(buf, '#');
                else                                  smart_str_appendc(buf, '+');
                if (info->flags & ZEND_ACC_READONLY)  smart_str_appendl(buf, "ro:", 3);
                if (prop_name) {
                    smart_str_append(buf, prop_name);
                } else if (info->name) {
                    smart_str_append(buf, info->name);
                }
                smart_str_appendl(buf, ": ", 2);
                zval *slot = OBJ_PROP(obj, info->offset);
                if (slot && Z_TYPE_P(slot) != IS_UNDEF) {
                    /* Mark visited before recursing */
                    if (visited == NULL) {
                        visited = emalloc(sizeof(HashTable));
                        zend_hash_init(visited, 8, NULL, NULL, 0);
                    }
                    zend_hash_index_add_empty_element(visited, (zend_ulong)handle);
                    capture_recursive(buf, slot, depth + 1, opts, visited);
                } else {
                    smart_str_appendl(buf, "<uninit>", sizeof("<uninit>") - 1);
                }
                shown++;
            } ZEND_HASH_FOREACH_END();
        }
        smart_str_appendc(buf, '}');
        return;
    }

    /* Mark visited before recursing */
    bool owns_visited = false;
    if (visited == NULL) {
        visited = emalloc(sizeof(HashTable));
        zend_hash_init(visited, 8, NULL, NULL, 0);
        owns_visited = true;
    }
    zend_hash_index_add_empty_element(visited, (zend_ulong)handle);

    smart_str_appendl(buf, " {", sizeof(" {") - 1);
    uint32_t total = zend_hash_num_elements(props);
    uint32_t shown = 0;
    uint32_t cap = (opts->max_object_props > 0) ? (uint32_t)opts->max_object_props : total;
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(props, key, val) {
        if (shown >= cap) break;
        if (shown > 0) smart_str_appendl(buf, ", ", 2);
        if (key) smart_str_append(buf, key);
        smart_str_appendl(buf, ": ", 2);
        capture_recursive(buf, val, depth + 1, opts, visited);
        shown++;
    } ZEND_HASH_FOREACH_END();
    if (shown < total) {
        smart_str_appendl(buf, ", <…props+", sizeof(", <…props+") - 1);
        n = snprintf(numbuf, sizeof(numbuf), "%u", total - shown);
        smart_str_appendl(buf, numbuf, n);
        smart_str_appendc(buf, '>');
    }
    smart_str_appendc(buf, '}');

    if (owns_visited) {
        zend_hash_destroy(visited);
        efree(visited);
    }
}

static void capture_recursive(smart_str *buf,
                              const zval *z,
                              int depth,
                              const periscope_capture_options_t *opts,
                              HashTable *visited)
{
    if (z == NULL) {
        smart_str_appendl(buf, "<null-zval>", sizeof("<null-zval>") - 1);
        return;
    }

    char numbuf[64];
    int n;

    switch (Z_TYPE_P(z)) {
        case IS_UNDEF:
            smart_str_appendl(buf, "undef", sizeof("undef") - 1);
            return;
        case IS_NULL:
            smart_str_appendl(buf, "null", sizeof("null") - 1);
            return;
        case IS_TRUE:
            smart_str_appendl(buf, "bool(true)", sizeof("bool(true)") - 1);
            return;
        case IS_FALSE:
            smart_str_appendl(buf, "bool(false)", sizeof("bool(false)") - 1);
            return;
        case IS_LONG:
            n = snprintf(numbuf, sizeof(numbuf), "int(%lld)", (long long)Z_LVAL_P(z));
            smart_str_appendl(buf, numbuf, n);
            return;
        case IS_DOUBLE:
            n = snprintf(numbuf, sizeof(numbuf), "float(%g)", Z_DVAL_P(z));
            smart_str_appendl(buf, numbuf, n);
            return;
        case IS_STRING:
            append_quoted_string(buf, Z_STRVAL_P(z), Z_STRLEN_P(z), opts->max_string);
            return;
        case IS_ARRAY:
            capture_array(buf, Z_ARRVAL_P(z), depth, opts, visited);
            return;
        case IS_OBJECT:
            capture_object(buf, z, depth, opts, visited);
            return;
        case IS_RESOURCE: {
            const char *type_name = zend_rsrc_list_get_rsrc_type(Z_RES_P(z));
            n = snprintf(numbuf, sizeof(numbuf), "resource(#%ld %s)",
                         (long)Z_RES_HANDLE_P(z),
                         type_name ? type_name : "?");
            smart_str_appendl(buf, numbuf, n);
            return;
        }
        case IS_REFERENCE:
            smart_str_appendl(buf, "ref->", sizeof("ref->") - 1);
            capture_recursive(buf, Z_REFVAL_P(z), depth, opts, visited);
            return;
        default:
            n = snprintf(numbuf, sizeof(numbuf), "type(%d)", (int)Z_TYPE_P(z));
            smart_str_appendl(buf, numbuf, n);
            return;
    }
}

void periscope_capture_value(smart_str *buf,
                             const zval *z,
                             const periscope_capture_options_t *opts)
{
    HashTable *visited = NULL;
    capture_recursive(buf, z, 0, opts, visited);
    /* visited is allocated lazily inside capture_object; no-op here. */
}

void periscope_capture_function_name(smart_str *buf, const zend_function *func)
{
    if (func == NULL) {
        smart_str_appendl(buf, "<unknown>", sizeof("<unknown>") - 1);
        return;
    }
    if (func->common.scope) {
        smart_str_append(buf, func->common.scope->name);
        smart_str_appendl(buf, "::", 2);
    }
    if (func->common.function_name) {
        smart_str_append(buf, func->common.function_name);
    } else {
        smart_str_appendl(buf, "{main}", sizeof("{main}") - 1);
    }
}

void periscope_capture_arg(smart_str *buf,
                           const zend_function *func,
                           uint32_t i,
                           const zval *value,
                           const periscope_capture_options_t *opts)
{
    bool printed_signature = false;
    if (func && func->common.arg_info && i < func->common.num_args) {
        zend_arg_info *info = &func->common.arg_info[i];

        if (ZEND_TYPE_IS_SET(info->type)) {
            zend_string *t = zend_type_to_string(info->type);
            if (t) {
                smart_str_append(buf, t);
                smart_str_appendc(buf, ' ');
                zend_string_release(t);
            }
        }
        if (ZEND_ARG_SEND_MODE(info)) smart_str_appendc(buf, '&');
        if (ZEND_ARG_IS_VARIADIC(info)) smart_str_appendl(buf, "...", 3);

        if (info->name) {
            smart_str_appendc(buf, '$');
            if (func->type == ZEND_USER_FUNCTION) {
                smart_str_append(buf, (zend_string *)info->name);
            } else {
                smart_str_appends(buf, (const char *)info->name);
            }
            printed_signature = true;
        }
    }
    if (printed_signature) smart_str_appendl(buf, " = ", 3);
    periscope_capture_value(buf, value, opts);
}

void periscope_capture_return_type(smart_str *buf, const zend_function *func)
{
    if (!func) return;
    if ((func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) == 0) return;
    if (!func->common.arg_info) return;

    zend_arg_info *ret_info = &func->common.arg_info[-1];
    if (!ZEND_TYPE_IS_SET(ret_info->type)) return;

    zend_string *t = zend_type_to_string(ret_info->type);
    if (!t) return;
    smart_str_appendl(buf, ": ", 2);
    smart_str_append(buf, t);
    zend_string_release(t);
}
