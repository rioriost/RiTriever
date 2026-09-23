#!/bin/sh
set -eu

. "$(dirname "$0")/apple-container-stack.sh"
need_container
URL="$WP_URL"
OUTPUT_DIR="${RITRIEVER_TEST_OUTPUT_DIR:-output}"
SEARCH_FILE="${OUTPUT_DIR}/ritriever-${STACK}-search.html"
mkdir -p "$OUTPUT_DIR"
trap 'rm -f "$SEARCH_FILE"' EXIT INT TERM

curl -fsS "http://127.0.0.1:${APPLE_CONTAINER_EMBEDDING_PORT}/health" >/dev/null
run_wp plugin status ritriever >/dev/null

HTTP_CODE=$(curl -fsS -o "$SEARCH_FILE" -w "%{http_code}" "${URL}/?s=vector")
if [ "$HTTP_CODE" != "200" ]; then
  echo "Expected HTTP 200 from ${URL}/?s=vector, got ${HTTP_CODE}" >&2
  exit 1
fi

if [ "$STACK" = "mariadb" ]; then
  TABLE_EXISTS=$(run_sql -e "SHOW TABLES LIKE 'wp_ritriever_chunks';" | wc -l | tr -d ' ')
  if [ "$TABLE_EXISTS" = "0" ]; then
    echo "Expected ritriever_chunks table on MariaDB stack." >&2
    exit 1
  fi

  CHUNKS=$(run_sql -e "SELECT COUNT(*) FROM wp_ritriever_chunks;")
  if [ "$CHUNKS" -le 0 ]; then
    echo "Expected indexed vector chunks on MariaDB stack." >&2
    exit 1
  fi

  ERRORS=$(run_sql -e "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_ritriever_last_error';")
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
  TABLE_EXISTS=$(run_sql -e "SHOW TABLES LIKE 'wp_ritriever_chunks';" | wc -l | tr -d ' ')
  if [ "$TABLE_EXISTS" != "0" ]; then
    echo "Expected no ritriever_chunks table on default MySQL stack." >&2
    exit 1
  fi

  SETTINGS=$(run_wp option get ritriever_settings --format=json)
  echo "$SETTINGS" | grep -q '"search_mode":"off"'
  echo "$SETTINGS" | grep -q '"sync_enabled":false'
  echo "MySQL smoke test passed: plugin active with native vector search disabled by default."
fi
