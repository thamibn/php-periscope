#!/usr/bin/env bash
#
# Uninstall php-periscope (extension + daemon).
#
# Usage:
#   bash scripts/uninstall.sh             # remove everything
#   bash scripts/uninstall.sh -v          # verbose
#   bash scripts/uninstall.sh --dry-run   # print what would happen
#   bash scripts/uninstall.sh --keep-config   # leave the .ini in place
#   bash scripts/uninstall.sh --keep-traces   # leave /tmp/periscope alone
#
# Reverses scripts/install.sh — removes the .so, the 99-periscope.ini,
# and the periscope-{daemon,dump,export} binaries from the configured
# prefix. Optional cleanup of the trace dir (/tmp/periscope by default).

set -euo pipefail

VERBOSE=0
DRY_RUN=0
KEEP_CONFIG=0
KEEP_TRACES=0
PHP_BIN=""
PREFIX=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    -v|--verbose)   VERBOSE=1; shift ;;
    --dry-run)      DRY_RUN=1;  shift ;;
    --keep-config)  KEEP_CONFIG=1; shift ;;
    --keep-traces)  KEEP_TRACES=1; shift ;;
    --php)          PHP_BIN="$2"; shift 2 ;;
    --prefix)       PREFIX="$2"; shift 2 ;;
    -h|--help)
      sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "unknown flag: $1" >&2
      exit 1
      ;;
  esac
done

step() { printf "\033[1;34m::\033[0m %s\n" "$1"; }
ok()   { printf "  \033[32m✓\033[0m %s\n" "$1"; }
skip() { printf "  \033[2m-\033[0m %s\n" "$1"; }
warn() { printf "  \033[33m!\033[0m %s\n" "$1"; }
fail() { printf "  \033[31m✗\033[0m %s\n" "$1" >&2; exit 1; }
trace(){ [[ $VERBOSE -eq 1 ]] && printf "    \033[2m%s\033[0m\n" "$*" || true; }

rm_path() {
  local target="$1"
  if [[ ! -e "$target" ]]; then
    skip "absent: $target"
    return
  fi
  if [[ $DRY_RUN -eq 1 ]]; then
    printf "  \033[2m(dry-run)\033[0m rm %s\n" "$target"
    return
  fi
  if [[ -w "$(dirname "$target")" ]]; then
    rm -f "$target"
  else
    sudo rm -f "$target"
  fi
  ok "removed: $target"
}

if [[ "${EUID:-$(id -u)}" -eq 0 ]] && [[ "${RUN_AS_ROOT:-0}" != "1" ]]; then
  fail "refuse to run as root. Re-run without sudo, or set RUN_AS_ROOT=1."
fi

# detect PHP for ext/scan dirs
step "detect PHP"
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN="${PHP:-$(command -v php || true)}"
fi
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || fail "no php in PATH. Use --php /path/to/php."
EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
SCAN_DIR="$("$PHP_BIN" -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
ok "extension_dir: $EXT_DIR"
ok "scan dir:      $SCAN_DIR"

# default prefix matches install.sh
uname_s="$(uname -s)"
if [[ -z "$PREFIX" ]]; then
  if [[ "$uname_s" = "Darwin" ]] && [[ -d "/opt/homebrew/bin" ]]; then
    PREFIX="/opt/homebrew/bin"
  else
    PREFIX="/usr/local/bin"
  fi
fi
ok "binary prefix: $PREFIX"

# ---------- remove files ----------

step "remove extension"
rm_path "$EXT_DIR/periscope.so"

step "remove ini"
if [[ $KEEP_CONFIG -eq 1 ]]; then
  skip "preserved (--keep-config): $SCAN_DIR/99-periscope.ini"
else
  rm_path "$SCAN_DIR/99-periscope.ini"
  rm_path "$SCAN_DIR/99-periscope.ini.bak"
fi

step "remove daemon binaries"
rm_path "$PREFIX/periscope-daemon"
rm_path "$PREFIX/periscope-dump"
rm_path "$PREFIX/periscope-export"

step "remove traces"
if [[ $KEEP_TRACES -eq 1 ]]; then
  skip "preserved (--keep-traces): /tmp/periscope"
else
  if [[ -d /tmp/periscope ]]; then
    trace "removing /tmp/periscope/*.cptrace*"
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m rm -rf /tmp/periscope\n"
    else
      rm -rf /tmp/periscope
    fi
    ok "cleared /tmp/periscope"
  else
    skip "no /tmp/periscope directory"
  fi
fi

# ---------- verify ----------

step "verify"
if [[ $DRY_RUN -eq 1 ]]; then
  warn "skipping verification in --dry-run mode"
elif "$PHP_BIN" -m 2>/dev/null | grep -q '^periscope$'; then
  warn "php -m still lists 'periscope'. There may be an extra ini somewhere (php -ini | grep periscope)."
else
  ok "php -m no longer lists periscope"
fi

step "done"
cat <<EOF

  Removed:
    - $EXT_DIR/periscope.so
    - $SCAN_DIR/99-periscope.ini
    - $PREFIX/periscope-daemon, periscope-dump, periscope-export

  Left in place:
    - The cloned repository (delete manually if you wish).
    - The composer package thamibn/php-periscope-laravel in your Laravel apps
      (composer remove thamibn/php-periscope-laravel to drop it).
EOF
