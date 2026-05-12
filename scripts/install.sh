#!/usr/bin/env bash
#
# One-line install for php-periscope (extension + daemon).
#
# Usage:
#   bash scripts/install.sh              # build + install
#   bash scripts/install.sh -v           # verbose
#   bash scripts/install.sh --dry-run    # print everything, do nothing
#   bash scripts/install.sh --help
#
# What this script does:
#   1. Detects OS (macOS / Linux) and PHP installation.
#   2. Builds the C extension (phpize → ./configure → make) against the
#      first php-config it can find.
#   3. Copies periscope.so into PHP's extension_dir.
#   4. Drops a `99-periscope.ini` into the active PHP's `conf.d` so the
#      extension auto-loads on every SAPI (CLI, fpm, fpm worker).
#   5. Builds the Rust daemon (cargo build --release) and installs
#      `periscope-daemon` + `periscope-dump` + `periscope-export` into
#      a directory in PATH ($PREFIX, default /usr/local/bin or
#      /opt/homebrew/bin on Apple silicon).
#
# Safety:
#   - Refuses to run as root unless RUN_AS_ROOT=1 is set explicitly.
#   - Backs up any existing 99-periscope.ini to *.bak before overwriting.
#   - Idempotent — re-running picks up any newer build artefacts.

set -euo pipefail

VERBOSE=0
DRY_RUN=0
PHP_BIN=""
PREFIX=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    -v|--verbose) VERBOSE=1; shift ;;
    --dry-run)    DRY_RUN=1;  shift ;;
    --php)        PHP_BIN="$2"; shift 2 ;;
    --prefix)     PREFIX="$2"; shift 2 ;;
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

# ---------- helpers ----------

step() { printf "\033[1;34m::\033[0m %s\n" "$1"; }
ok()   { printf "  \033[32m✓\033[0m %s\n" "$1"; }
warn() { printf "  \033[33m!\033[0m %s\n" "$1"; }
fail() { printf "  \033[31m✗\033[0m %s\n" "$1" >&2; exit 1; }
trace(){ [[ $VERBOSE -eq 1 ]] && printf "    \033[2m%s\033[0m\n" "$*" || true; }

run() {
  trace "$ $*"
  if [[ $DRY_RUN -eq 1 ]]; then
    printf "  \033[2m(dry-run)\033[0m %s\n" "$*"
  else
    "$@"
  fi
}

# Locate the repo root regardless of where the user invokes from.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# ---------- preconditions ----------

step "preconditions"

if [[ "${EUID:-$(id -u)}" -eq 0 ]] && [[ "${RUN_AS_ROOT:-0}" != "1" ]]; then
  fail "refuse to run as root. Re-run without sudo, or set RUN_AS_ROOT=1 if you know what you're doing."
fi

uname_s="$(uname -s)"
case "$uname_s" in
  Darwin) os="macos" ;;
  Linux)  os="linux" ;;
  *)      fail "unsupported OS: $uname_s. periscope ships for macOS and Linux only." ;;
esac
ok "OS: $os"

# pick a PHP. CLI knob > $PHP > first one in PATH.
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN="${PHP:-$(command -v php || true)}"
fi
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || fail "no php in PATH. Set PHP=/path/to/php or use --php /path/to/php."

php_version="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
ok "PHP: $php_version ($PHP_BIN)"

case "$php_version" in
  8.3.*|8.4.*) ;;
  *) fail "v1 requires PHP 8.3 or newer (got $php_version)." ;;
esac

# pick php-config alongside php. brew/macOS keep them paired.
if command -v php-config >/dev/null 2>&1; then
  PHP_CONFIG="$(command -v php-config)"
else
  PHP_CONFIG="$(dirname "$PHP_BIN")/php-config"
fi
[[ -x "$PHP_CONFIG" ]] || fail "php-config not found (looked for: $PHP_CONFIG). Install php's dev headers (brew: php → already includes; apt: php-dev)."
ok "php-config: $PHP_CONFIG"

# detect extension dir + scandir
EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
SCAN_DIR="$("$PHP_BIN" -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
[[ -n "$EXT_DIR" ]]  || fail "could not detect extension_dir."
[[ -n "$SCAN_DIR" ]] || fail "could not detect PHP_CONFIG_FILE_SCAN_DIR — no conf.d available to auto-load into."
ok "extension_dir: $EXT_DIR"
ok "scan dir:      $SCAN_DIR"

# need cargo for the daemon
if ! command -v cargo >/dev/null 2>&1; then
  fail "rust toolchain not found. Install via https://rustup.rs/ then re-run."
fi
ok "cargo: $(command -v cargo)"

# choose install prefix
if [[ -z "$PREFIX" ]]; then
  if [[ "$os" = "macos" ]] && [[ -d "/opt/homebrew/bin" ]]; then
    PREFIX="/opt/homebrew/bin"
  else
    PREFIX="/usr/local/bin"
  fi
fi
ok "binary prefix: $PREFIX"
if [[ ! -w "$PREFIX" ]]; then
  warn "$PREFIX is not writable. The script will retry with sudo on the install step only."
fi

# ---------- build extension ----------

step "build C extension"
run cd "$ROOT/extension"
# phpize fails noisily on dirty trees; clean first.
if [[ -f Makefile ]]; then
  run make distclean >/dev/null 2>&1 || true
fi
run "$PHP_CONFIG" >/dev/null
PHPIZE="$(dirname "$PHP_CONFIG")/phpize"
[[ -x "$PHPIZE" ]] || fail "phpize not found next to php-config ($PHPIZE)."
run "$PHPIZE"
run ./configure --with-php-config="$PHP_CONFIG"
run make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)"
SO="$ROOT/extension/modules/periscope.so"
[[ $DRY_RUN -eq 1 ]] || [[ -f "$SO" ]] || fail "expected $SO to exist after build."
ok "built: $SO"
run cd "$ROOT"

# ---------- install extension ----------

step "install extension"
INSTALL_SO="$EXT_DIR/periscope.so"
INI_FILE="$SCAN_DIR/99-periscope.ini"

write_file() {
  local target="$1"
  local mode="$2"
  shift 2
  if [[ -w "$(dirname "$target")" ]]; then
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m write %s (%s)\n" "$target" "$mode"
    else
      "$@" > "$target"
      chmod "$mode" "$target"
    fi
  else
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m sudo write %s (%s)\n" "$target" "$mode"
    else
      "$@" | sudo tee "$target" >/dev/null
      sudo chmod "$mode" "$target"
    fi
  fi
}

# Copy .so
if [[ -w "$EXT_DIR" ]]; then
  run cp "$SO" "$INSTALL_SO"
else
  if [[ $DRY_RUN -eq 1 ]]; then
    printf "  \033[2m(dry-run)\033[0m sudo cp %s %s\n" "$SO" "$INSTALL_SO"
  else
    sudo cp "$SO" "$INSTALL_SO"
  fi
fi
ok "installed: $INSTALL_SO"

# Back up existing ini, then write a fresh one
if [[ -f "$INI_FILE" ]]; then
  if [[ $DRY_RUN -eq 1 ]]; then
    printf "  \033[2m(dry-run)\033[0m backup %s -> %s.bak\n" "$INI_FILE" "$INI_FILE"
  elif [[ -w "$(dirname "$INI_FILE")" ]]; then
    cp "$INI_FILE" "$INI_FILE.bak"
  else
    sudo cp "$INI_FILE" "$INI_FILE.bak"
  fi
  warn "backed up existing $(basename "$INI_FILE") to .bak"
fi

INI_BODY="$(cat <<EOF
; php-periscope — auto-generated by scripts/install.sh
extension=periscope.so

periscope.enabled            = 1
periscope.verbose            = 0
periscope.skip_internal      = 1
periscope.max_depth          = 5
periscope.max_string         = 4096
periscope.max_array_items    = 100
periscope.max_object_props   = 50
; periscope.trace_dir         = /tmp/periscope    ; default; uncomment to override
periscope.max_traces         = 100
periscope.max_trace_age_seconds = 86400
EOF
)"
write_file "$INI_FILE" 644 printf '%s\n' "$INI_BODY"
ok "wrote: $INI_FILE"

# ---------- build daemon ----------

step "build Rust daemon"
run cd "$ROOT/daemon"
run cargo build --release
DAEMON_BIN="$ROOT/daemon/target/release/periscope-daemon"
DUMP_BIN="$ROOT/daemon/target/release/periscope-dump"
EXPORT_BIN="$ROOT/daemon/target/release/periscope-export"
[[ $DRY_RUN -eq 1 ]] || [[ -f "$DAEMON_BIN" ]] || fail "expected $DAEMON_BIN to exist after build."
ok "built: $DAEMON_BIN"
run cd "$ROOT"

# ---------- install binaries ----------

step "install daemon binaries"
install_bin() {
  local src="$1" name="$2"
  local dest="$PREFIX/$name"
  if [[ -w "$PREFIX" ]]; then
    run install -m 0755 "$src" "$dest"
  elif [[ $DRY_RUN -eq 1 ]]; then
    printf "  \033[2m(dry-run)\033[0m sudo install -m 0755 %s %s\n" "$src" "$dest"
  else
    sudo install -m 0755 "$src" "$dest"
  fi
  ok "installed: $dest"
}
install_bin "$DAEMON_BIN" periscope-daemon
[[ -f "$DUMP_BIN"   ]] && install_bin "$DUMP_BIN"   periscope-dump   || warn "skipped periscope-dump (not built)"
[[ -f "$EXPORT_BIN" ]] && install_bin "$EXPORT_BIN" periscope-export || warn "skipped periscope-export (not built)"

# ---------- verify ----------

step "verify"
if [[ $DRY_RUN -eq 1 ]]; then
  warn "skipping verification in --dry-run mode"
else
  if "$PHP_BIN" -m 2>/dev/null | grep -q '^periscope$'; then
    ok "php -m lists 'periscope'"
  else
    fail "php -m did NOT list periscope. Inspect $INI_FILE and re-run."
  fi
  if command -v periscope-daemon >/dev/null 2>&1; then
    ok "periscope-daemon: $(command -v periscope-daemon)"
  else
    warn "periscope-daemon installed to $PREFIX but $PREFIX isn't in PATH."
  fi
fi

step "done"
cat <<EOF

  Next steps:
    1. Start the daemon:           periscope-daemon
    2. Install the Laravel adapter:  composer require periscopephp/laravel
    3. Open http://localhost:9999  in your browser
    4. Read the next request you trigger from your Laravel app.

  Wire AI agents:
    claude mcp add periscope -- php artisan mcp:start periscope

  Uninstall later with:
    bash scripts/uninstall.sh

EOF
