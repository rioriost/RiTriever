#!/bin/sh
set -eu
. "$(dirname "$0")/apple-container-stack.sh"
need_container
WXR_PATH="${2:-}"
DELETE_AFTER_IMPORT="${3:-}"
if [ -z "$WXR_PATH" ] || [ ! -f "$WXR_PATH" ]; then
  echo "Usage: $0 mariadb|mysql path/to/export.xml [--delete-after-import]" >&2
  exit 2
fi
case "$DELETE_AFTER_IMPORT" in
  ""|--delete-after-import) ;;
  *) echo "Unknown option: $DELETE_AFTER_IMPORT" >&2; exit 2 ;;
esac
WXR_ABSOLUTE="$(cd "$(dirname "$WXR_PATH")" && pwd)/$(basename "$WXR_PATH")"
CONTAINER_WXR="/tmp/ritriever-import-$$.xml"
trap 'container exec "$APPLE_CONTAINER_WP" rm -f "$CONTAINER_WXR"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
container cp "$WXR_ABSOLUTE" "${APPLE_CONTAINER_WP}:${CONTAINER_WXR}"
run_wp plugin install wordpress-importer --activate
run_wp import "$CONTAINER_WXR" --authors=create
if [ "$DELETE_AFTER_IMPORT" = "--delete-after-import" ]; then
  rm -- "$WXR_ABSOLUTE"
fi
echo "Imported WXR into ${STACK}: $WXR_PATH"
