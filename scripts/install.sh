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

need() { printf "  \033[36m?\033[0m %s\n" "$1"; }

# Locate the repo root regardless of where the user invokes from.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Tracks soft-missing requirements collected during preconditions. If any blockers
# remain after the prompt-and-install pass, the script prints them all and exits.
BLOCKERS=()
block() { BLOCKERS+=("$1"); }

# Prompt for a Y/n answer on /dev/tty. Args: question, default ("Y"|"N").
# Returns 0 for yes, 1 for no. Non-interactive returns the default's exit code.
ask_yes_no() {
  local q="$1" default="${2:-Y}" suffix="[Y/n]" ans=""
  [[ "$default" = "N" ]] && suffix="[y/N]"
  if [[ ! -t 0 ]] || [[ ! -t 1 ]]; then
    [[ "$default" = "Y" ]] && return 0 || return 1
  fi
  printf "  %s %s " "$q" "$suffix" >&2
  read -r ans </dev/tty || true
  [[ -z "$ans" ]] && ans="$default"
  case "$ans" in
    [Yy]|[Yy][Ee][Ss]) return 0 ;;
    *) return 1 ;;
  esac
}

# Prompt for a path on /dev/tty. Args: prompt. Stdout: expanded path or empty.
ask_path() {
  local prompt="$1" user_path=""
  if [[ ! -t 0 ]] || [[ ! -t 1 ]]; then return 0; fi
  printf "  %s: " "$prompt" >&2
  read -r user_path </dev/tty || true
  [[ -z "$user_path" ]] && return 0
  printf '%s' "${user_path/#\~/$HOME}"
}

# Ensure Rust toolchain is available. Auto-install via rustup with consent.
ensure_cargo() {
  if command -v cargo >/dev/null 2>&1; then
    ok "cargo: $(command -v cargo)"
    return 0
  fi
  local cmd="curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable"
  need "rust toolchain not found (needed to build the daemon)"
  printf "      command: %s\n" "$cmd" >&2
  if ask_yes_no "run this now? (Y = install for you, N = run it yourself and re-run this script)" Y; then
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m curl ... rustup.rs | sh -s -- -y --profile minimal\n"
      ok "cargo: (dry-run; would be installed)"
      return 0
    fi
    if curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable; then
      # shellcheck disable=SC1091
      [[ -f "$HOME/.cargo/env" ]] && source "$HOME/.cargo/env"
      export PATH="$HOME/.cargo/bin:$PATH"
    fi
    if command -v cargo >/dev/null 2>&1; then
      ok "cargo: $(command -v cargo) (just installed)"
      return 0
    fi
    block "rustup install failed — install Rust manually (https://rustup.rs) and re-run"
    return 1
  fi
  block "rust toolchain (skipped install)"
  return 1
}

# Ensure Cap'n Proto C++ library is available. Auto-install via brew/apt with consent.
ensure_capnp() {
  if command -v capnp >/dev/null 2>&1; then
    ok "capnp: $(command -v capnp)"
    return 0
  fi
  need "Cap'n Proto not found (needed to build the trace serializer)"
  local installer="" install_cmd=""
  case "$(uname -s)" in
    Darwin) command -v brew >/dev/null 2>&1 && { installer="brew" ; install_cmd="brew install capnp" ; } ;;
    Linux)  command -v apt-get >/dev/null 2>&1 && { installer="apt" ; install_cmd="sudo apt-get install -y libcapnp-dev capnproto" ; } ;;
  esac
  if [[ -z "$installer" ]]; then
    block "Cap'n Proto (no supported package manager found — install capnproto manually)"
    return 1
  fi
  printf "      command: %s\n" "$install_cmd" >&2
  if ask_yes_no "run this now? (Y = install for you, N = run it yourself and re-run this script)" Y; then
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m %s\n" "$install_cmd"
      ok "capnp: (dry-run; would be installed)"
      return 0
    fi
    if eval "$install_cmd" && command -v capnp >/dev/null 2>&1; then
      ok "capnp: $(command -v capnp) (just installed)"
      return 0
    fi
    block "Cap'n Proto install failed — try manually: $install_cmd"
    return 1
  fi
  block "Cap'n Proto (skipped install)"
  return 1
}

# ---------- preconditions ----------

step "preconditions"

if [[ "${EUID:-$(id -u)}" -eq 0 ]] && [[ "${RUN_AS_ROOT:-0}" != "1" ]]; then
  fail "refuse to run as root. Re-run without sudo, or set RUN_AS_ROOT=1 if you know what you're doing."
fi

uname_s="$(uname -s)"
case "$uname_s" in
  Darwin) os="macos" ;;
  Linux)
    os="linux"
    # Detect WSL2 — installs work fine here; just flag it so users with a
    # broken WSL DNS / kernel setup see the hint up front.
    if [[ -r /proc/version ]] && grep -qiE "microsoft|wsl" /proc/version; then
      ok "WSL2 detected — proceeding as Linux."
    fi
    ;;
  MINGW*|MSYS*|CYGWIN*)
    cat >&2 <<'EOF'
periscope does not ship for native Windows.

You're seeing this because you're running under Git-Bash / MSYS / Cygwin,
which presents a Unix-y shell on top of Windows. The C extension and the
trace recorder need Linux-native syscalls that don't have clean Windows
equivalents in v1.

Use WSL2 instead — one-time setup from an Admin PowerShell:

  wsl --install -d Ubuntu-22.04
  wsl --set-default-version 2

Reboot, finish Ubuntu's first-run setup, then open the Ubuntu terminal
and re-run this install script there.

Why WSL specifically and not Windows native? See:
  https://periscope.thamibn.com/guide/faq#why-isnt-windows-native-supported
EOF
    exit 1
    ;;
  *) fail "unsupported OS: $uname_s. periscope ships for macOS and Linux only." ;;
esac
ok "OS: $os"

# pick a PHP. CLI knob > $PHP > first one in PATH > known locations > prompt.
if [[ -z "$PHP_BIN" ]]; then
  PHP_BIN="${PHP:-$(command -v php || true)}"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  for cand in \
    /opt/homebrew/bin/php \
    /usr/local/bin/php \
    "$HOME/Library/Application Support/Herd/bin/php" \
    "$HOME/Library/Application Support/Herd/bin/php84" \
    "$HOME/Library/Application Support/Herd/bin/php83" \
    /usr/bin/php ; do
    [[ -x "$cand" ]] && { PHP_BIN="$cand" ; break ; }
  done
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  need "PHP not found in PATH or standard locations (Homebrew, Herd, system)"
  printf "  Install via Herd (https://herd.laravel.com) or \`brew install php@8.3\`, then re-run.\n" >&2
  PHP_BIN="$(ask_path 'Or type a path to a PHP 8.3/8.4 binary (Enter to abort)')"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  block "PHP 8.3 or 8.4 (required)"
fi

if [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]]; then
  php_version="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
  case "$php_version" in
    8.3.*|8.4.*) ok "PHP: $php_version ($PHP_BIN)" ;;
    *) ok "PHP: $php_version ($PHP_BIN)" ; block "PHP $php_version is too old — v1 requires 8.3 or 8.4" ;;
  esac

  # pick php-config alongside php. brew/macOS keep them paired.
  if command -v php-config >/dev/null 2>&1; then
    PHP_CONFIG="$(command -v php-config)"
  else
    PHP_CONFIG="$(dirname "$PHP_BIN")/php-config"
  fi
  if [[ -x "$PHP_CONFIG" ]]; then
    ok "php-config: $PHP_CONFIG"
  else
    block "php-config not found (looked for: $PHP_CONFIG) — install PHP dev headers (brew: included; apt: php-dev)"
  fi

  EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
  SCAN_DIR="$("$PHP_BIN" -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
  [[ -n "$EXT_DIR"  ]] && ok "extension_dir: $EXT_DIR"  || block "could not detect PHP extension_dir"
  [[ -n "$SCAN_DIR" ]] && ok "scan dir:      $SCAN_DIR" || block "could not detect PHP conf.d scan dir"
fi

# Rust + Cap'n Proto via auto-install helpers (Y/n prompts; record blocker on decline).
ensure_cargo
ensure_capnp

# Detect IDE installs (informational — no install attempted in this phase).
# JetBrains config dirs:
PHPSTORM_DIRS=()
case "$(uname -s)" in
  Darwin) for d in "$HOME/Library/Application Support/JetBrains/"PhpStorm* ; do [[ -d "$d" ]] && PHPSTORM_DIRS+=("$d") ; done ;;
  Linux)  for d in "$HOME/.local/share/JetBrains/"PhpStorm*               ; do [[ -d "$d" ]] && PHPSTORM_DIRS+=("$d") ; done ;;
esac
if [[ ${#PHPSTORM_DIRS[@]} -gt 0 ]]; then
  ok "PhpStorm: ${#PHPSTORM_DIRS[@]} config dir(s) detected"
else
  warn "PhpStorm: not detected (plugin will be skipped — browser UI at http://localhost:9999 still works)"
fi
# VSCode `code` CLI:
VSCODE_CLI=""
if command -v code >/dev/null 2>&1; then
  VSCODE_CLI="$(command -v code)"
else
  case "$(uname -s)" in
    Darwin)
      for app in \
        "/Applications/Visual Studio Code.app" \
        "/Applications/Visual Studio Code - Insiders.app" \
        "/Applications/VSCodium.app" \
        "/Applications/Cursor.app" ; do
        local_cli="$app/Contents/Resources/app/bin/code"
        [[ -x "$local_cli" ]] && { VSCODE_CLI="$local_cli" ; break ; }
      done
      ;;
    Linux)
      for cli in "/snap/bin/code" "/usr/share/code/bin/code" "$HOME/.vscode-server/cli/code" ; do
        [[ -x "$cli" ]] && { VSCODE_CLI="$cli" ; break ; }
      done
      ;;
  esac
fi
if [[ -n "$VSCODE_CLI" ]]; then
  ok "VSCode/Cursor: $VSCODE_CLI"
else
  warn "VSCode/Cursor: not detected (extension will be skipped — browser UI still works)"
fi

# All-blockers gate. Better to show everything missing at once than fail-fast.
if [[ ${#BLOCKERS[@]} -gt 0 ]]; then
  printf "\n"
  printf "  \033[31m✗\033[0m Cannot continue — missing required tools:\n" >&2
  for b in "${BLOCKERS[@]}"; do
    printf "      - %s\n" "$b" >&2
  done
  exit 1
fi

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

# ---------- JetBrains plugin (PhpStorm) ----------

step "install JetBrains plugin (if PhpStorm detected)"
install_jetbrains_plugin() {
  # PHPSTORM_DIRS was populated during preconditions. If empty, offer a manual path.
  if [[ ${#PHPSTORM_DIRS[@]} -eq 0 ]]; then
    local hint
    hint="$(ask_path 'PhpStorm config dir (or Enter to skip)')"
    if [[ -n "$hint" && -d "$hint" ]]; then
      PHPSTORM_DIRS+=("$hint")
    else
      warn "PhpStorm not configured — skipping plugin (browser UI at http://localhost:9999 still works)"
      return 0
    fi
  fi

  # Source the .zip — prefer locally-built artefact for contributor flow,
  # fall back to GitHub Releases for everyone else.
  local zip_src
  local local_zip
  local_zip="$(ls -t "$ROOT/jetbrains-plugin/build/distributions/"*.zip 2>/dev/null | head -1 || true)"
  if [[ -n "$local_zip" ]]; then
    zip_src="$local_zip"
    ok "using locally-built plugin: $zip_src"
  else
    zip_src="$(mktemp -t periscope-jetbrains-XXXXXX.zip)"
    local zip_url="https://github.com/thamibn/php-periscope/releases/latest/download/periscope-jetbrains.zip"
    printf "  fetching %s\n" "$zip_url"
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m curl -fsSL %s -o %s\n" "$zip_url" "$zip_src"
    else
      if ! curl -fsSL "$zip_url" -o "$zip_src"; then
        warn "could not download JetBrains plugin from GitHub Releases — skipping"
        warn "(contributors: run \`cd jetbrains-plugin && ./gradlew buildPlugin\` then re-run install)"
        return 0
      fi
    fi
  fi

  for ide_dir in "${PHPSTORM_DIRS[@]}"; do
    local plugins_dir="$ide_dir/plugins"
    run mkdir -p "$plugins_dir"
    run rm -rf "$plugins_dir/periscope-jetbrains"
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m unzip -q -o %s -d %s\n" "$zip_src" "$plugins_dir"
    else
      unzip -q -o "$zip_src" -d "$plugins_dir"
    fi
    ok "plugin installed: $(basename "$ide_dir")"
  done

  [[ "$zip_src" != "$local_zip" ]] && rm -f "$zip_src" || true
}
install_jetbrains_plugin

# ---------- VSCode extension ----------

step "install VSCode extension (if VSCode/Cursor detected)"
install_vscode_extension() {
  # VSCODE_CLI was populated during preconditions. If empty, offer a manual path.
  if [[ -z "$VSCODE_CLI" ]]; then
    local hint
    hint="$(ask_path 'Path to VSCode/Cursor app or `code` CLI (or Enter to skip)')"
    if [[ -n "$hint" ]]; then
      for cand in "$hint" "$hint/Contents/Resources/app/bin/code" "$hint/bin/code" ; do
        [[ -x "$cand" ]] && { VSCODE_CLI="$cand" ; break ; }
      done
    fi
    if [[ -z "$VSCODE_CLI" ]]; then
      warn "VSCode/Cursor not configured — skipping extension (browser UI still works)"
      return 0
    fi
  fi

  # Source the .vsix.
  local vsix_src=""
  local local_vsix
  local_vsix="$(ls -t "$ROOT/vscode-extension/"*.vsix 2>/dev/null | head -1 || true)"
  if [[ -n "$local_vsix" ]]; then
    vsix_src="$local_vsix"
    ok "using locally-built vsix: $vsix_src"
  else
    vsix_src="$(mktemp -t periscope-vscode-XXXXXX.vsix)"
    local vsix_url="https://github.com/thamibn/php-periscope/releases/latest/download/php-periscope.vsix"
    printf "  fetching %s\n" "$vsix_url"
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m curl -fsSL %s -o %s\n" "$vsix_url" "$vsix_src"
    else
      if ! curl -fsSL "$vsix_url" -o "$vsix_src"; then
        warn "could not download VSCode extension from GitHub Releases — skipping"
        return 0
      fi
    fi
  fi

  if [[ $DRY_RUN -eq 1 ]]; then
    printf "  \033[2m(dry-run)\033[0m %s --install-extension %s --force\n" "$VSCODE_CLI" "$vsix_src"
  else
    "$VSCODE_CLI" --install-extension "$vsix_src" --force || {
      warn "VSCode --install-extension failed — try manually: $VSCODE_CLI --install-extension $vsix_src"
      return 0
    }
  fi
  ok "VSCode extension installed via $VSCODE_CLI"

  [[ "$vsix_src" != "$local_vsix" ]] && rm -f "$vsix_src" || true
}
install_vscode_extension

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
    1. Start the daemon:             periscope-daemon
    2. Install the Laravel adapter:  composer require periscopephp/laravel
    3. Open http://localhost:9999    in your browser
    4. Hit any route in your Laravel app — the trace appears in the UI.

  IDE setup:
    - PhpStorm:  plugin auto-installed into your PhpStorm config dir.
                 Restart PhpStorm, then Run > Edit Configurations > + > Periscope > pick a trace.
                 Hit Shift+F9 — breakpoints + step + STEP BACK all work.
    - VSCode:    extension auto-installed via the VSCode CLI.
                 Restart VSCode, then hit F5 — debugger attaches to the latest .cptrace.
    - No IDE?    The browser UI at http://localhost:9999 has the full trace viewer.
                 Install PhpStorm or VSCode later and re-run this script to add IDE debug.

  Updating later:
    A. Manual — re-run this same one-liner whenever you want the newest version:
         bash <(curl -fsSL https://raw.githubusercontent.com/thamibn/php-periscope/main/scripts/install.sh)
       Idempotent. Updates the extension, daemon, and JetBrains plugin to whatever
       the latest GitHub Release ships. Use this if you prefer "I update on my schedule".

    B. Automatic — opt into PhpStorm's update channel for just the JB plugin:
         PhpStorm > Settings > Plugins > ⚙ > Manage Plugin Repositories > + >
           https://periscope.thamibn.com/jetbrains/updatePlugins.xml
       PhpStorm checks the URL on its normal update cycle and offers the new
       version in the IDE notification, same as marketplace plugins.

  Wire AI agents:
    claude mcp add periscope -- php artisan mcp:start periscope

  Uninstall later with:
    bash scripts/uninstall.sh

EOF
