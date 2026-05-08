#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "php_periscope.h"

static PHP_MINIT_FUNCTION(periscope)
{
    fprintf(stderr, "periscope loaded\n");
    return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(periscope)
{
    return SUCCESS;
}

static PHP_RINIT_FUNCTION(periscope)
{
    return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(periscope)
{
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(periscope)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "periscope support", "enabled");
    php_info_print_table_row(2, "Version", PHP_PERISCOPE_VERSION);
    php_info_print_table_end();
}

zend_module_entry periscope_module_entry = {
    STANDARD_MODULE_HEADER,
    PHP_PERISCOPE_EXTNAME,
    NULL,                       /* functions */
    PHP_MINIT(periscope),
    PHP_MSHUTDOWN(periscope),
    PHP_RINIT(periscope),
    PHP_RSHUTDOWN(periscope),
    PHP_MINFO(periscope),
    PHP_PERISCOPE_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_PERISCOPE
ZEND_GET_MODULE(periscope)
#endif
