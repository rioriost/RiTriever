#!/bin/sh

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
. "$ROOT_DIR/scripts/apple-container-runtime.sh"
STACK="${1:-mariadb}"
case "$STACK" in
  mariadb) DB_CLIENT=mariadb; DEFAULT_PORT=8081 ;;
  mysql) DB_CLIENT=mysql; DEFAULT_PORT=8082 ;;
  *) echo "Usage: $0 mariadb|mysql" >&2; exit 2 ;;
esac
export RITRIEVER_TEST_DB="$STACK"
export APPLE_CONTAINER_NETWORK="${APPLE_CONTAINER_NETWORK:-ritriever-${STACK}-net}"
export APPLE_CONTAINER_DB="${APPLE_CONTAINER_DB:-ritriever-${STACK}-db}"
export APPLE_CONTAINER_WP="${APPLE_CONTAINER_WP:-ritriever-${STACK}-wp}"
export APPLE_CONTAINER_MOCK="${APPLE_CONTAINER_MOCK:-ritriever-${STACK}-mock}"
export APPLE_CONTAINER_DB_VOLUME="${APPLE_CONTAINER_DB_VOLUME:-ritriever_${STACK}_data}"
export APPLE_CONTAINER_WP_VOLUME="${APPLE_CONTAINER_WP_VOLUME:-ritriever_${STACK}_html}"
export APPLE_CONTAINER_WP_PORT="${APPLE_CONTAINER_WP_PORT:-$DEFAULT_PORT}"
export APPLE_CONTAINER_EMBEDDING_PORT="${APPLE_CONTAINER_EMBEDDING_PORT:-18080}"
export WP_PATH="${WP_PATH:-/var/www/html}"
export WP_URL="${WP_URL:-http://127.0.0.1:${APPLE_CONTAINER_WP_PORT}}"
export WP_DB_NAME="${WP_DB_NAME:-wordpress}"
export WP_DB_USER="${WP_DB_USER:-wordpress}"
export WP_DB_PASSWORD="${WP_DB_PASSWORD:-wordpress}"
DIMENSIONS="${RITRIEVER_EMBEDDING_DIMENSIONS:-16}"

run_wp() {
  container exec "$APPLE_CONTAINER_WP" wp --allow-root --path="$WP_PATH" "$@"
}

run_sql() {
  container exec -i "$APPLE_CONTAINER_DB" "$DB_CLIENT" \
    -u"$WP_DB_USER" -p"$WP_DB_PASSWORD" "$WP_DB_NAME" -N -B "$@"
}

container_ip() {
  container inspect "$1" | awk -F '"' '/"ipv4Address"/ { print $4; exit }' | sed 's#\\/#/#g' | cut -d/ -f1
}
