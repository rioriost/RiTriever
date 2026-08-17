#!/bin/sh
set -eu

WORDPRESS_VERSION="${1:-}"
STACK="${2:-mariadb}"
RUN_PLUGIN_CHECK="${RITRIEVER_RUN_PLUGIN_CHECK:-0}"
HOST="${RITRIEVER_HOST:-127.0.0.1}"

if [ "$WORDPRESS_VERSION" = "" ]; then
  echo "Usage: $0 WORDPRESS_VERSION [mariadb|mysql]" >&2
  exit 2
fi
if [ "$STACK" != "mariadb" ] && [ "$STACK" != "mysql" ]; then
  echo "Usage: $0 WORDPRESS_VERSION [mariadb|mysql]" >&2
  exit 2
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required for the compatibility smoke test." >&2
  exit 1
fi

VERSION_ID=$(printf '%s' "$WORDPRESS_VERSION" | tr '[:upper:].' '[:lower:]--' | tr -cd 'a-z0-9_-')
PROJECT_NAME="${RITRIEVER_COMPOSE_PROJECT:-ritriever-wp-${VERSION_ID}-${STACK}}"
COMPOSE="docker compose -p ${PROJECT_NAME}"
COMPAT_DIR="output/wp-compat-${VERSION_ID}-${STACK}"
PLUGIN_DIR="${COMPAT_DIR}/ritriever"
PLUGIN_ZIP="${COMPAT_DIR}/ritriever.zip"

if [ "$STACK" = "mariadb" ]; then
  export RITRIEVER_WP_MARIADB_PORT="${RITRIEVER_WP_MARIADB_PORT:-18081}"
else
  export RITRIEVER_WP_MYSQL_PORT="${RITRIEVER_WP_MYSQL_PORT:-18082}"
fi
export RITRIEVER_EMBEDDING_PORT="${RITRIEVER_EMBEDDING_PORT:-19080}"
export RITRIEVER_HOST="$HOST"
export RITRIEVER_WORDPRESS_VERSION="$WORDPRESS_VERSION"
export RITRIEVER_PLUGIN_ZIP="$PLUGIN_ZIP"
export COMPOSE_FILE="docker-compose.default.yml"
export COMPOSE

cleanup() {
  $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$COMPAT_DIR"
}
trap cleanup EXIT INT TERM
cleanup

mkdir -p "$PLUGIN_DIR"
cp ritriever.php uninstall.php readme.txt README.md LICENSE "$PLUGIN_DIR/"
cp -R includes assets languages "$PLUGIN_DIR/"
find "$PLUGIN_DIR" -name '.DS_Store' -delete
(cd "$COMPAT_DIR" && zip -qr ritriever.zip ritriever)

sh scripts/docker-setup-stack.sh "$STACK"
ACTUAL_VERSION=$($COMPOSE run --rm "wpcli-${STACK}" --path=/var/www/html core version)
if [ "$ACTUAL_VERSION" != "$WORDPRESS_VERSION" ]; then
  echo "Expected WordPress ${WORDPRESS_VERSION}, got ${ACTUAL_VERSION}." >&2
  exit 1
fi
sh scripts/docker-smoke-test.sh "$STACK"

if [ "$RUN_PLUGIN_CHECK" = "1" ]; then
  PLUGIN_ZIP="" WPCLI_SERVICE="wpcli-${STACK}" WPCLI_RUN_OPTIONS="--user 0" WP_ALLOW_ROOT=1 sh scripts/run-plugin-check.sh
fi

echo "WordPress ${WORDPRESS_VERSION} compatibility passed on ${STACK}."
