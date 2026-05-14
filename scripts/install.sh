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

# Log file for noisy compile output. Stays around after the run so users can read failures.
LOG_FILE="${TMPDIR:-/tmp}/periscope-install-$$.log"
: > "$LOG_FILE"

# Run a noisy command behind a single progress line. Use for things users can't act on
# (compilers, ./configure, make, cargo build). In -v mode, output is streamed live.
# Args: "label", then the command + args.
compile_step() {
  local label="$1" ; shift
  if [[ $DRY_RUN -eq 1 ]]; then
    printf "  %s ... \033[2m(dry-run)\033[0m %s\n" "$label" "$*"
    return 0
  fi
  if [[ $VERBOSE -eq 1 ]]; then
    printf "  %s ...\n" "$label"
    "$@"
    return $?
  fi
  printf "  %s ..." "$label"
  if "$@" >>"$LOG_FILE" 2>&1; then
    printf " \033[32m✓\033[0m\n"
    return 0
  fi
  local rc=$?
  printf " \033[31m✗\033[0m\n\n"
  printf "  build failed (exit %d). Last 20 lines from %s:\n\n" "$rc" "$LOG_FILE" >&2
  tail -20 "$LOG_FILE" | sed 's/^/    /' >&2
  printf "\n  Full log: %s\n" "$LOG_FILE" >&2
  exit "$rc"
}

need() { printf "  \033[36m?\033[0m %s\n" "$1"; }

# Locate the repo root. When invoked as `bash <(curl ...)` $0 is /dev/fd/N, so
# the usual sibling lookup yields /dev — useless. Detect that case and clone the
# repo into a tempdir so the build steps have source to work with.
ROOT="$(cd "$(dirname "$0")/.." 2>/dev/null && pwd || true)"
if [[ -z "$ROOT" || ! -d "$ROOT/extension" ]]; then
  CLONE_DIR="$(mktemp -d -t periscope-src-XXXXXX)"
  printf "\033[1;34m::\033[0m \033[2mscript piped via curl — cloning source into %s\033[0m\n" "$CLONE_DIR"
  if ! git clone --depth=1 https://github.com/thamibn/php-periscope.git "$CLONE_DIR" >/dev/null 2>&1; then
    printf "  \033[31m✗\033[0m could not clone https://github.com/thamibn/php-periscope.git\n" >&2
    printf "    check your network + git install, then re-run.\n" >&2
    exit 1
  fi
  ROOT="$CLONE_DIR"
  trap 'rm -rf "$CLONE_DIR"' EXIT
fi

# Tracks missing requirements during the detection pass. Each item has a display
# name, the command we'd run to install it, and a flag for whether the user must
# do it themselves (PHP) vs whether we can auto-install (Rust, Cap'n Proto).
MISSING_NAMES=()
MISSING_CMDS=()
MISSING_USER_ONLY=()  # 1 = user must install themselves; 0 = we can auto-install
record_missing() {
  MISSING_NAMES+=("$1")
  MISSING_CMDS+=("$2")
  MISSING_USER_ONLY+=("${3:-0}")
}

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

# Interactive multi-select on /dev/tty. Arrow keys to navigate, space to toggle,
# 'a' = all, 'n' = none, Enter to confirm. Works in curl-pipe mode because we
# read from /dev/tty (fd 3) — stdin is busy with the script content.
#
# Args:
#   $1 — prompt text
#   $2 — name of an array variable holding the items
#   $3 — name of a parallel array of 0/1 (initial selection state)
# The selection-state array is updated in place via nameref.
multi_select() {
  local prompt="$1"
  local -n _items_ref="$2"
  local -n _sel_ref="$3"
  local count="${#_items_ref[@]}"
  [[ $count -eq 0 ]] && return 0

  # If we can't drive a real terminal, fall back to the legacy comma-list prompt.
  if [[ ! -t 1 ]] || [[ ! -r /dev/tty ]]; then
    printf "  %s\n" "$prompt"
    for i in "${!_items_ref[@]}"; do
      printf "    [%d] %s\n" "$((i+1))" "${_items_ref[i]}"
    done
    printf "    [A] all (default)  [N] none\n"
    printf "  pick (Enter = A, or comma-separated numbers): "
    local fallback_choice=""
    read -r fallback_choice </dev/tty || true
    fallback_choice="$(printf '%s' "$fallback_choice" | tr '[:lower:]' '[:upper:]' | tr -d ' ')"
    case "$fallback_choice" in
      ""|A) for i in "${!_sel_ref[@]}"; do _sel_ref[i]=1; done ;;
      N)    for i in "${!_sel_ref[@]}"; do _sel_ref[i]=0; done ;;
      *)
        for i in "${!_sel_ref[@]}"; do _sel_ref[i]=0; done
        IFS=',' read -ra _picks <<< "$fallback_choice"
        for p in "${_picks[@]}"; do
          local idx=$((p-1))
          [[ $idx -ge 0 && $idx -lt $count ]] && _sel_ref[idx]=1
        done
        ;;
    esac
    return 0
  fi

  exec 3</dev/tty
  local stty_saved
  stty_saved="$(stty -g </dev/tty)"
  stty -echo -icanon min 1 time 0 </dev/tty
  tput civis 2>/dev/null || true
  local restore="stty '$stty_saved' </dev/tty 2>/dev/null; tput cnorm 2>/dev/null; exec 3<&- 2>/dev/null"
  trap "$restore" RETURN
  trap "$restore; exit 130" INT

  local cur=0
  local rendered=0
  _render() {
    if [[ $rendered -gt 0 ]]; then
      tput cuu "$rendered" 2>/dev/null
      tput ed 2>/dev/null
    fi
    printf "  \033[1m%s\033[0m\n" "$prompt"
    printf "  \033[2m↑/↓ move · space toggle · a all · n none · enter confirm\033[0m\n"
    rendered=$((count + 2))
    for i in "${!_items_ref[@]}"; do
      local marker="[ ]"
      [[ "${_sel_ref[i]}" = "1" ]] && marker="[\033[32m✓\033[0m]"
      if [[ $i -eq $cur ]]; then
        printf "  \033[7m▶ %b %s\033[0m\n" "$marker" "${_items_ref[i]}"
      else
        printf "    %b %s\n" "$marker" "${_items_ref[i]}"
      fi
    done
  }

  _render
  while :; do
    local k=""
    IFS= read -rsn1 -u 3 k || break
    case "$k" in
      $'\x1b')
        local k2=""
        IFS= read -rsn2 -t 0.05 -u 3 k2 || true
        case "$k2" in
          '[A') (( cur > 0 )) && cur=$((cur-1)) ;;
          '[B') (( cur < count - 1 )) && cur=$((cur+1)) ;;
        esac
        _render
        ;;
      ' ')
        [[ "${_sel_ref[cur]}" = "1" ]] && _sel_ref[cur]=0 || _sel_ref[cur]=1
        _render
        ;;
      a|A) for i in "${!_sel_ref[@]}"; do _sel_ref[i]=1; done ; _render ;;
      n|N) for i in "${!_sel_ref[@]}"; do _sel_ref[i]=0; done ; _render ;;
      '')  break ;;  # Enter
      q|Q) break ;;
    esac
  done
  printf "\n"
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

# Detect Rust toolchain. Records as auto-installable if missing.
detect_cargo() {
  if command -v cargo >/dev/null 2>&1; then
    ok "cargo: $(command -v cargo)"
    return 0
  fi
  warn "cargo: not found (needed to build the daemon)"
  record_missing "Rust toolchain" "curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain stable"
}

# Detect Cap'n Proto. Records as auto-installable if missing.
detect_capnp() {
  if command -v capnp >/dev/null 2>&1; then
    ok "capnp: $(command -v capnp)"
    return 0
  fi
  warn "capnp: not found (needed to build the trace serializer)"
  local install_cmd=""
  case "$(uname -s)" in
    Darwin) command -v brew >/dev/null 2>&1 && install_cmd="brew install capnp" ;;
    Linux)  command -v apt-get >/dev/null 2>&1 && install_cmd="sudo apt-get install -y libcapnp-dev capnproto" ;;
  esac
  if [[ -z "$install_cmd" ]]; then
    record_missing "Cap'n Proto" "# no supported package manager found — install capnproto manually from https://capnproto.org" 1
  else
    record_missing "Cap'n Proto" "$install_cmd"
  fi
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
  warn "PHP: not found"
  record_missing "PHP 8.3 or 8.4" "# install via Herd (https://herd.laravel.com) or 'brew install php@8.3', then re-run this script" 1
fi

if [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]]; then
  php_version="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
  case "$php_version" in
    8.3.*|8.4.*) ok "PHP: $php_version ($PHP_BIN)" ;;
    *) warn "PHP: $php_version ($PHP_BIN) is too old"
       record_missing "PHP 8.3 or 8.4 (got $php_version)" "# install via Herd (https://herd.laravel.com) or 'brew install php@8.3', then re-run this script" 1 ;;
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
    warn "php-config: not found at $PHP_CONFIG"
    record_missing "PHP dev headers (php-config)" "# install PHP dev headers — brew: included with 'php' formula; apt: 'sudo apt-get install -y php-dev'" 1
  fi

  EXT_DIR="$("$PHP_BIN" -r 'echo ini_get("extension_dir");')"
  SCAN_DIR="$("$PHP_BIN" -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
  [[ -n "$EXT_DIR"  ]] && ok "extension_dir: $EXT_DIR"  || record_missing "PHP extension_dir (unknown — check your PHP build)" "# php -r 'echo ini_get(\"extension_dir\");' returns empty" 1
  [[ -n "$SCAN_DIR" ]] && ok "scan dir:      $SCAN_DIR" || record_missing "PHP conf.d scan dir (unknown — check your PHP build)" "# php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;' returns empty" 1
fi

# Rust + Cap'n Proto — detection only. Auto-install (or not) decided after summary.
detect_cargo
detect_capnp

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
# VSCode-family CLIs — detect every install we recognise (VSCode, Cursor, etc.).
VSCODE_LABELS=()
VSCODE_CLIS=()
_add_vsc() {
  local label="$1" cli="$2"
  [[ -x "$cli" ]] || return 0
  for existing in "${VSCODE_CLIS[@]+"${VSCODE_CLIS[@]}"}"; do
    [[ "$existing" = "$cli" ]] && return 0  # dedupe
  done
  VSCODE_LABELS+=("$label")
  VSCODE_CLIS+=("$cli")
}
if command -v code >/dev/null 2>&1; then
  _add_vsc "code (on PATH)" "$(command -v code)"
fi
case "$(uname -s)" in
  Darwin)
    _add_vsc "VSCode"           "/Applications/Visual Studio Code.app/Contents/Resources/app/bin/code"
    _add_vsc "VSCode Insiders"  "/Applications/Visual Studio Code - Insiders.app/Contents/Resources/app/bin/code"
    _add_vsc "VSCodium"         "/Applications/VSCodium.app/Contents/Resources/app/bin/code"
    _add_vsc "Cursor"           "/Applications/Cursor.app/Contents/Resources/app/bin/code"
    _add_vsc "Windsurf"         "/Applications/Windsurf.app/Contents/Resources/app/bin/code"
    ;;
  Linux)
    _add_vsc "VSCode (snap)"     "/snap/bin/code"
    _add_vsc "VSCode (system)"   "/usr/share/code/bin/code"
    _add_vsc "VSCode Server"     "$HOME/.vscode-server/cli/code"
    ;;
esac
if [[ ${#VSCODE_CLIS[@]} -gt 0 ]]; then
  ok "VSCode-family: ${#VSCODE_CLIS[@]} install(s) detected (${VSCODE_LABELS[*]})"
else
  warn "VSCode-family: not detected (extension will be skipped — browser UI still works)"
fi

# ---------- summary + decide ----------

if [[ ${#MISSING_NAMES[@]} -gt 0 ]]; then
  printf "\n"
  step "missing requirements"
  any_user_only=0
  for i in "${!MISSING_NAMES[@]}"; do
    printf "  \033[31m✗\033[0m %s\n" "${MISSING_NAMES[i]}"
    printf "      %s\n" "${MISSING_CMDS[i]}"
    [[ "${MISSING_USER_ONLY[i]}" = "1" ]] && any_user_only=1
  done

  printf "\n"
  if [[ $any_user_only -eq 1 ]]; then
    # User-only items (PHP, php-dev headers) — we can't auto-install. Exit cleanly.
    printf "  Please install the items above and re-run this script.\n"
    exit 1
  fi

  if ! ask_yes_no "Continue and let this script install the items above?" Y; then
    printf "\n  Run the commands above yourself, then re-run this script.\n"
    exit 0
  fi

  step "installing missing requirements"
  for i in "${!MISSING_NAMES[@]}"; do
    printf "  installing %s ...\n" "${MISSING_NAMES[i]}"
    if [[ $DRY_RUN -eq 1 ]]; then
      printf "  \033[2m(dry-run)\033[0m %s\n" "${MISSING_CMDS[i]}"
    else
      eval "${MISSING_CMDS[i]}" || fail "install failed for: ${MISSING_NAMES[i]}"
    fi
  done

  # rustup drops cargo in ~/.cargo/bin — pick it up for the rest of this script.
  [[ -f "$HOME/.cargo/env" ]] && source "$HOME/.cargo/env"
  export PATH="$HOME/.cargo/bin:$PATH"
  ok "all missing requirements installed"
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

# ---------- IDE plugin target picker ----------

# Compose a flat list of all detected IDE targets; user picks which to install into.
# Each parallel-array slot has a label + kind ("jb" or "vsc") + identifier (config dir
# for JB, code-CLI path for VSCode-family).
IDE_LABELS=()
IDE_KINDS=()
IDE_IDS=()
for d in "${PHPSTORM_DIRS[@]+"${PHPSTORM_DIRS[@]}"}"; do
  IDE_LABELS+=("PhpStorm ($(basename "$d"))")
  IDE_KINDS+=("jb")
  IDE_IDS+=("$d")
done
for i in "${!VSCODE_CLIS[@]}"; do
  IDE_LABELS+=("${VSCODE_LABELS[i]}")
  IDE_KINDS+=("vsc")
  IDE_IDS+=("${VSCODE_CLIS[i]}")
done

# Default selection: all detected. User can opt out via the picker.
IDE_SELECTED=()
for _ in "${IDE_LABELS[@]+"${IDE_LABELS[@]}"}"; do IDE_SELECTED+=("1"); done

if [[ ${#IDE_LABELS[@]} -eq 0 ]]; then
  warn "no supported IDEs detected — IDE plugin install will be skipped"
elif [[ $DRY_RUN -eq 0 ]]; then
  step "IDE plugin install — pick targets"
  multi_select "pick IDEs to install the periscope plugin into:" IDE_LABELS IDE_SELECTED
  _picked_names=()
  for i in "${!IDE_SELECTED[@]}"; do
    [[ "${IDE_SELECTED[i]}" = "1" ]] && _picked_names+=("${IDE_LABELS[i]}")
  done
  if [[ ${#_picked_names[@]} -eq 0 ]]; then
    warn "nothing selected — IDE plugin install will be skipped"
  else
    ok "selected: ${_picked_names[*]}"
  fi
fi

# Apply the selection — overwrite the install-target arrays with only chosen entries.
PHPSTORM_DIRS=()
_kept_vsc_labels=()
_kept_vsc_clis=()
for i in "${!IDE_LABELS[@]}"; do
  [[ "${IDE_SELECTED[i]}" = "1" ]] || continue
  case "${IDE_KINDS[i]}" in
    jb)  PHPSTORM_DIRS+=("${IDE_IDS[i]}") ;;
    vsc) _kept_vsc_labels+=("${IDE_LABELS[i]}") ; _kept_vsc_clis+=("${IDE_IDS[i]}") ;;
  esac
done
VSCODE_LABELS=("${_kept_vsc_labels[@]+"${_kept_vsc_labels[@]}"}")
VSCODE_CLIS=("${_kept_vsc_clis[@]+"${_kept_vsc_clis[@]}"}")

# ---------- build extension ----------

step "build C extension"
# Generate Cap'n Proto C++ from proto/trace.capnp into extension/. These files
# (trace.capnp.{h,cpp}) are gitignored — regenerated on every fresh checkout.
if [[ -f "$ROOT/proto/trace.capnp" ]]; then
  run cd "$ROOT"
  compile_step "capnp compile (trace schema)" sh -c "capnp compile -oc++:extension --src-prefix=proto proto/trace.capnp && mv extension/trace.capnp.c++ extension/trace.capnp.cpp"
fi
run cd "$ROOT/extension"
# phpize fails noisily on dirty trees; clean first.
if [[ -f Makefile ]]; then
  make distclean >/dev/null 2>&1 || true
fi
PHPIZE="$(dirname "$PHP_CONFIG")/phpize"
[[ -x "$PHPIZE" ]] || fail "phpize not found next to php-config ($PHPIZE)."
compile_step "phpize" "$PHPIZE"
compile_step "configure" ./configure --with-php-config="$PHP_CONFIG"
compile_step "make (-j$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2))" make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)"
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

# Back up the existing ini only if it differs from what we're about to write.
# Idempotent re-installs (most cases) leave .bak alone; user-edited inis are
# preserved as .bak before being overwritten.
if [[ -f "$INI_FILE" ]] && [[ $DRY_RUN -eq 0 ]]; then
  if cmp -s <(printf '%s\n' "$INI_BODY") "$INI_FILE"; then
    trace "ini already up to date — no backup needed"
  else
    if [[ -w "$(dirname "$INI_FILE")" ]]; then
      cp "$INI_FILE" "$INI_FILE.bak"
    else
      sudo cp "$INI_FILE" "$INI_FILE.bak"
    fi
    warn "existing $(basename "$INI_FILE") differs from new content — backed up to .bak"
  fi
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
compile_step "cargo build --release (~30s first build)" cargo build --release
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
  # PHPSTORM_DIRS has already been filtered by the IDE picker. If empty, the user
  # either had nothing detected, picked None, or unticked all PhpStorm entries.
  if [[ ${#PHPSTORM_DIRS[@]} -eq 0 ]]; then
    warn "no PhpStorm target selected — skipping JetBrains plugin"
    return 0
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

step "install VSCode-family extension(s)"
install_vscode_extension() {
  local code_cli="$1"
  local label="${2:-$code_cli}"

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
    printf "  \033[2m(dry-run)\033[0m %s --install-extension %s --force\n" "$code_cli" "$vsix_src"
  else
    "$code_cli" --install-extension "$vsix_src" --force || {
      warn "$label: --install-extension failed — try manually: $code_cli --install-extension $vsix_src"
      return 0
    }
  fi
  ok "$label: extension installed"

  [[ "$vsix_src" != "$local_vsix" ]] && rm -f "$vsix_src" || true
}

if [[ ${#VSCODE_CLIS[@]} -eq 0 ]]; then
  warn "no VSCode-family editor selected — skipping extension (browser UI still works)"
else
  for _vsc_i in "${!VSCODE_CLIS[@]}"; do
    install_vscode_extension "${VSCODE_CLIS[_vsc_i]}" "${VSCODE_LABELS[_vsc_i]}"
  done
fi

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
    2. Install the Laravel adapter:  composer require thamibn/laravel-periscope
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
