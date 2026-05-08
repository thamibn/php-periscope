PHP_ARG_ENABLE([periscope],
  [whether to enable periscope support],
  [AS_HELP_STRING([--enable-periscope],
    [Enable periscope support])],
  [no])

if test "$PHP_PERISCOPE" != "no"; then
  AC_DEFINE(HAVE_PERISCOPE, 1, [ Have periscope support ])
  PHP_NEW_EXTENSION(periscope, periscope.c, $ext_shared)
fi
