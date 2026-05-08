PHP_ARG_ENABLE([periscope],
  [whether to enable periscope support],
  [AS_HELP_STRING([--enable-periscope],
    [Enable periscope support])],
  [no])

if test "$PHP_PERISCOPE" != "no"; then
  AC_DEFINE(HAVE_PERISCOPE, 1, [ Have periscope support ])

  dnl --- Cap'n Proto (C++ canonical implementation) -------------------
  AC_PATH_PROG(PKG_CONFIG, pkg-config, no)
  if test "x$PKG_CONFIG" = "xno"; then
    AC_MSG_ERROR([pkg-config is required to detect Cap'n Proto])
  fi

  if ! $PKG_CONFIG --exists capnp; then
    AC_MSG_ERROR([Cap'n Proto not found via pkg-config — install with 'brew install capnp' (macOS) or 'apt-get install libcapnp-dev' (Linux)])
  fi

  CAPNP_CFLAGS=`$PKG_CONFIG --cflags capnp`
  CAPNP_LIBS=`$PKG_CONFIG --libs capnp`
  PHP_EVAL_INCLINE([$CAPNP_CFLAGS])
  PHP_EVAL_LIBLINE([$CAPNP_LIBS], PERISCOPE_SHARED_LIBADD)

  dnl --- C++ for the trace writer module ------------------------------
  PHP_REQUIRE_CXX()
  PHP_ADD_LIBRARY(stdc++, 1, PERISCOPE_SHARED_LIBADD)

  dnl Cap'n Proto requires C++14 minimum; force the standard.
  CXXFLAGS="$CXXFLAGS -std=c++17"

  PHP_NEW_EXTENSION(periscope,
    periscope.c periscope_filter.c periscope_capture.c periscope_userland.c periscope_daemon_link.c periscope_trace.cc trace.capnp.cpp,
    $ext_shared,,$CAPNP_CFLAGS)

  PHP_SUBST(PERISCOPE_SHARED_LIBADD)
fi
