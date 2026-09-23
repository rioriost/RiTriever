#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
. scripts/apple-container-runtime.sh
WORDPRESS_VERSION="${1:-}"
STACK="${2:-mariadb}"
if [ -z "$WORDPRESS_VERSION" ]; then
  echo "Usage: $0 WORDPRESS_VERSION [mariadb|mysql]" >&2
  exit 2
fi
case "$STACK" in mariadb|mysql) ;; *) echo "Unknown database: $STACK" >&2; exit 2 ;; esac
need_container

mkdir -p output
COMPAT_DIR="$(mktemp -d "$(pwd)/output/wp-compat.XXXXXXXX")"
RUN_ID="$(basename "$COMPAT_DIR" | tr '[:upper:].' '[:lower:]-')"
export APPLE_CONTAINER_NETWORK="ritriever-${RUN_ID}-net"
export APPLE_CONTAINER_DB="ritriever-${RUN_ID}-db"
export APPLE_CONTAINER_WP="ritriever-${RUN_ID}-wp"
export APPLE_CONTAINER_MOCK="ritriever-${RUN_ID}-mock"
export APPLE_CONTAINER_DB_VOLUME="ritriever-${RUN_ID}-db-data"
export APPLE_CONTAINER_WP_VOLUME="ritriever-${RUN_ID}-wp-data"
export APPLE_CONTAINER_WP_PORT="${RITRIEVER_COMPAT_PORT:-18081}"
export APPLE_CONTAINER_EMBEDDING_PORT="${RITRIEVER_COMPAT_EMBEDDING_PORT:-19080}"
export RITRIEVER_TEST_DB="$STACK"
export RITRIEVER_WORDPRESS_VERSION="$WORDPRESS_VERSION"
export WP_PATH=/var/www/html
export WP_URL="http://127.0.0.1:${APPLE_CONTAINER_WP_PORT}"
export WP_DB_NAME=wordpress WP_DB_USER=wordpress WP_DB_PASSWORD=wordpress WP_DB_ROOT_PASSWORD=root
export RITRIEVER_TEST_OUTPUT_DIR="$COMPAT_DIR"
# Database image overrides must not silently replace the selected matrix backend.
unset APPLE_CONTAINER_DB_IMAGE

cleanup() {
  result=$?
  trap - EXIT INT TERM
  if ! sh scripts/apple-container-wordpress.sh reset; then
    echo "Cleanup failed for ${APPLE_CONTAINER_WP}; inspect these test resources." >&2
    result=1
  fi
  rm -rf "$COMPAT_DIR"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Never reset somebody else's resources, even in the unlikely event of a name collision.
for name in "$APPLE_CONTAINER_WP" "$APPLE_CONTAINER_DB" "$APPLE_CONTAINER_MOCK"; do
  if container inspect "$name" >/dev/null 2>&1; then
    trap - EXIT INT TERM
    rmdir "$COMPAT_DIR"
    echo "Test container already exists: $name" >&2
    exit 1
  fi
done
for volume in "$APPLE_CONTAINER_DB_VOLUME" "$APPLE_CONTAINER_WP_VOLUME"; do
  if container volume inspect "$volume" >/dev/null 2>&1; then
    trap - EXIT INT TERM
    rmdir "$COMPAT_DIR"
    echo "Test volume already exists: $volume" >&2
    exit 1
  fi
done
if container network inspect "$APPLE_CONTAINER_NETWORK" >/dev/null 2>&1; then
  trap - EXIT INT TERM
  rmdir "$COMPAT_DIR"
  echo "Test network already exists: $APPLE_CONTAINER_NETWORK" >&2
  exit 1
fi

PLUGIN_DIR="$COMPAT_DIR/ritriever"
mkdir "$PLUGIN_DIR"
cp ritriever.php uninstall.php readme.txt README.md LICENSE "$PLUGIN_DIR/"
cp -R includes assets languages "$PLUGIN_DIR/"
find "$PLUGIN_DIR" -name '.DS_Store' -delete
(cd "$COMPAT_DIR" && zip -qr ritriever.zip ritriever)
export RITRIEVER_PLUGIN_ZIP="$COMPAT_DIR/ritriever.zip"
sh scripts/apple-container-setup-stack.sh "$STACK"
ACTUAL_VERSION="$(container exec "$APPLE_CONTAINER_WP" wp --allow-root --path="$WP_PATH" core version)"
if [ "$ACTUAL_VERSION" != "$WORDPRESS_VERSION" ]; then
  echo "Expected WordPress ${WORDPRESS_VERSION}, got ${ACTUAL_VERSION}." >&2
  exit 1
fi
sh scripts/apple-container-smoke-test.sh "$STACK"
if [ "${RITRIEVER_RUN_PLUGIN_CHECK:-0}" = "1" ]; then
  WP_CONTAINER="$APPLE_CONTAINER_WP" PLUGIN_ZIP="" APPLE_CONTAINER_AUTO_START=0 sh scripts/run-plugin-check.sh
fi
echo "WordPress ${WORDPRESS_VERSION} compatibility passed on ${STACK} using Apple Container."
