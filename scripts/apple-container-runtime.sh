#!/bin/sh
set -eu

# Do not execute legacy command strings, even when a caller exports them.
if [ -n "${COMPOSE:-}${COMPOSE_FILE:-}${WPCLI_COMMAND:-}" ]; then
  echo "COMPOSE, COMPOSE_FILE and WPCLI_COMMAND are no longer supported. Use Apple Container and WP_CONTAINER." >&2
  exit 2
fi

need_container() {
  if ! command -v container >/dev/null 2>&1; then
    echo "Apple Container CLI is required; no alternate runtime will be used." >&2
    exit 1
  fi
  version="$(container --version)" || exit 1
  case "$version" in
    "container CLI version "*) ;;
    *) echo "The container executable is not the Apple Container CLI." >&2; exit 1 ;;
  esac
  runtime_status="$(container system status)" || exit 1
  if [ "$(printf '%s\n' "$runtime_status" | awk '$1 == "status" { print $2 }')" != running ]; then
    echo "Apple Container is not running. Start it with: container system start" >&2
    exit 1
  fi
}

if [ "${1:-}" = "--check" ]; then
  need_container
  echo "Apple Container runtime is available."
fi
