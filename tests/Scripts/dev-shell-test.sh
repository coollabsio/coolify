#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$TMP_DIR/bin"
cat > "$TMP_DIR/bin/limactl" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail

case "${1:-}" in
  list)
    cat <<'LIST'
NAME          STATUS
coold-dev     Running
coold-dev-2   Running
LIST
    ;;
  shell)
    printf '%s\n' "$*" > "$LIMACTL_SHELL_ARGS_FILE"
    ;;
  *)
    printf '%s\n' "$*" > "${LIMACTL_OTHER_ARGS_FILE:-/dev/null}"
    ;;
esac
STUB
chmod +x "$TMP_DIR/bin/limactl"

export PATH="$TMP_DIR/bin:$PATH"
export LIMACTL_SHELL_ARGS_FILE="$TMP_DIR/limactl-shell-args"
export LIMACTL_OTHER_ARGS_FILE="$TMP_DIR/limactl-other-args"

assert_equals() {
  local expected="$1"
  local actual="$2"
  local message="$3"

  if [ "$expected" != "$actual" ]; then
    echo "FAIL: $message" >&2
    echo "Expected: $expected" >&2
    echo "Actual:   $actual" >&2
    exit 1
  fi
}

assert_contains() {
  local needle="$1"
  local haystack="$2"
  local message="$3"

  if [[ "$haystack" != *"$needle"* ]]; then
    echo "FAIL: $message" >&2
    echo "Expected output to contain: $needle" >&2
    echo "Actual output: $haystack" >&2
    exit 1
  fi
}

(
  cd "$ROOT"
  scripts/dev.sh shell coold-dev-2 >/dev/null
)
assert_equals "shell coold-dev-2 -- sudo env TERM=xterm-256color SYSTEMD_PAGER=cat SYSTEMD_LESS=FRXMK bash -l" "$(cat "$LIMACTL_SHELL_ARGS_FILE")" "shell accepts a Lima hostname"

set +e
numeric_output="$(cd "$ROOT" && scripts/dev.sh shell 1 2>&1 >/dev/null)"
numeric_status=$?
set -e

if [ "$numeric_status" -eq 0 ]; then
  echo "FAIL: numeric shell target should be rejected" >&2
  exit 1
fi
assert_contains "Use the Lima hostname" "$numeric_output" "numeric shell target explains hostname usage"

echo "dev-shell-test: ok"
