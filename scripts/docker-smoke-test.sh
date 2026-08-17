#!/bin/sh
set -eu

STACK="${1:-}"
COMPOSE="${COMPOSE:-docker compose}"
HOST="${RITRIEVER_HOST:-192.168.1.35}"

if [ "$STACK" != "mariadb" ] && [ "$STACK" != "mysql" ]; then
  echo "Usage: $0 mariadb|mysql" >&2
  exit 2
fi

if [ "$STACK" = "mariadb" ]; then
  WP_SERVICE="wp-mariadb"
  WPCLI_SERVICE="wpcli-mariadb"
  DB_SERVICE="db-mariadb"
  DB_CLIENT="mariadb"
  PORT="${RITRIEVER_WP_MARIADB_PORT:-8081}"
else
  WP_SERVICE="wp-mysql"
  WPCLI_SERVICE="wpcli-mysql"
  DB_SERVICE="db-mysql"
  DB_CLIENT="mysql"
  PORT="${RITRIEVER_WP_MYSQL_PORT:-8082}"
fi

URL="http://${HOST}:${PORT}"
OUTPUT_DIR="${RITRIEVER_TEST_OUTPUT_DIR:-output}"
SEARCH_FILE="${OUTPUT_DIR}/ritriever-${STACK}-search.html"
mkdir -p "$OUTPUT_DIR"
trap 'rm -f "$SEARCH_FILE"' EXIT INT TERM

run_wp() {
  $COMPOSE run --rm "$WPCLI_SERVICE" --path=/var/www/html "$@"
}

run_sql() {
  if [ "$DB_CLIENT" = "mariadb" ]; then
    $COMPOSE exec -T "$DB_SERVICE" mariadb -uwordpress -pwordpress wordpress -N -B -e "$1"
  else
    $COMPOSE exec -T "$DB_SERVICE" mysql -uwordpress -pwordpress wordpress -N -B -e "$1"
  fi
}

$COMPOSE up -d embedding-mock "$DB_SERVICE" "$WP_SERVICE" >/dev/null
curl -fsS "http://${HOST}:${RITRIEVER_EMBEDDING_PORT:-18080}/health" >/dev/null
run_wp plugin status ritriever >/dev/null

HTTP_CODE=$(curl -fsS -o "$SEARCH_FILE" -w "%{http_code}" "${URL}/?s=vector")
if [ "$HTTP_CODE" != "200" ]; then
  echo "Expected HTTP 200 from ${URL}/?s=vector, got ${HTTP_CODE}" >&2
  exit 1
fi

if [ "$STACK" = "mariadb" ]; then
  TABLE_EXISTS=$(run_sql "SHOW TABLES LIKE 'wp_ritriever_chunks';" | wc -l | tr -d ' ')
  if [ "$TABLE_EXISTS" = "0" ]; then
    echo "Expected ritriever_chunks table on MariaDB stack." >&2
    exit 1
  fi

  CHUNKS=$(run_sql "SELECT COUNT(*) FROM wp_ritriever_chunks;")
  if [ "$CHUNKS" -le 0 ]; then
    echo "Expected indexed vector chunks on MariaDB stack." >&2
    exit 1
  fi

  ERRORS=$(run_sql "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_ritriever_last_error';")
  if [ "$ERRORS" -ne 0 ]; then
    echo "Expected zero RiTriever indexing errors, got ${ERRORS}." >&2
    exit 1
  fi

  if ! grep -q '\[RAG\]' "$SEARCH_FILE"; then
    echo "Expected at least one [RAG] badge in MariaDB search output." >&2
    exit 1
  fi
  if ! grep -Eq '\[(Standard search|標準検索)\]' "$SEARCH_FILE"; then
    echo "Expected at least one standard-search badge in MariaDB search output." >&2
    exit 1
  fi

  echo "MariaDB smoke test passed: ${CHUNKS} vector chunks, ${URL}/?s=vector returned RAG and standard badges."
else
  TABLE_EXISTS=$(run_sql "SHOW TABLES LIKE 'wp_ritriever_chunks';" | wc -l | tr -d ' ')
  if [ "$TABLE_EXISTS" != "0" ]; then
    echo "Expected no ritriever_chunks table on default MySQL stack." >&2
    exit 1
  fi

  SETTINGS=$(run_wp option get ritriever_settings --format=json)
  echo "$SETTINGS" | grep -q '"search_mode":"off"'
  echo "$SETTINGS" | grep -q '"sync_enabled":false'
  echo "MySQL smoke test passed: plugin active with native vector search disabled by default."
fi
