#!/bin/sh
set -eu

PLUGIN_SLUG="${PLUGIN_SLUG:-ritriever}"
WP_PATH="${WP_PATH:-/var/www/html}"
PLUGIN_CHECK_FLAGS="${PLUGIN_CHECK_FLAGS:---format=table --ignore-codes=PluginCheck.CodeAnalysis.AIProvider.DirectIntegration}"
PLUGIN_ZIP="${PLUGIN_ZIP:-}"
APPLE_CONTAINER_AUTO_START="${APPLE_CONTAINER_AUTO_START:-0}"
APPLE_CONTAINER_RUNNER="${APPLE_CONTAINER_RUNNER:-0}"
APPLE_CONTAINER_NETWORK="${APPLE_CONTAINER_NETWORK:-ritriever-net}"
APPLE_CONTAINER_DB="${APPLE_CONTAINER_DB:-ritriever-db}"
APPLE_CONTAINER_WP="${APPLE_CONTAINER_WP:-ritriever-wp}"
APPLE_CONTAINER_WP_VOLUME="${APPLE_CONTAINER_WP_VOLUME:-ritriever_wp_html}"
APPLE_CONTAINER_WPCLI_IMAGE="${APPLE_CONTAINER_WPCLI_IMAGE:-wordpress:cli-php8.3}"
WP_DB_NAME="${WP_DB_NAME:-wordpress}"
WP_DB_USER="${WP_DB_USER:-wordpress}"
WP_DB_PASSWORD="${WP_DB_PASSWORD:-wordpress}"
REPO_ROOT="$(pwd)"
OUTPUT_DIR="${RITRIEVER_TEST_OUTPUT_DIR:-${REPO_ROOT}/output}"
OUTPUT_FILE="${OUTPUT_DIR}/plugin-check.txt"
mkdir -p "$OUTPUT_DIR"
trap 'rm -f "$OUTPUT_FILE"' EXIT INT TERM

if [ "$PLUGIN_ZIP" != "" ] && [ "${PLUGIN_ZIP#/}" = "$PLUGIN_ZIP" ]; then
  PLUGIN_ZIP="${REPO_ROOT}/${PLUGIN_ZIP}"
fi

if [ "$APPLE_CONTAINER_AUTO_START" = "1" ] &&
  [ "${WPCLI_COMMAND:-}" = "" ] &&
  [ "${WP_CONTAINER:-}" = "" ] &&
  [ "${COMPOSE:-}" = "" ] &&
  ! command -v wp >/dev/null 2>&1; then
  sh scripts/apple-container-wordpress.sh up >/dev/null
  APPLE_CONTAINER_RUNNER=1
fi

run_wp() {
  if [ "${WPCLI_COMMAND:-}" != "" ]; then
    # shellcheck disable=SC2086
    $WPCLI_COMMAND "$@"
    return
  fi

  if [ "${WP_CONTAINER:-}" != "" ]; then
    if [ "${WP_ALLOW_ROOT:-1}" = "1" ]; then
      container exec "$WP_CONTAINER" wp --allow-root --path="$WP_PATH" "$@"
    else
      container exec "$WP_CONTAINER" wp --path="$WP_PATH" "$@"
    fi
    return
  fi

  if [ "$APPLE_CONTAINER_RUNNER" = "1" ]; then
    container exec "$APPLE_CONTAINER_WP" wp --allow-root --path="$WP_PATH" "$@"
    return
  fi

  if command -v wp >/dev/null 2>&1; then
    wp --path="${LOCAL_WP_PATH:-$WP_PATH}" "$@"
    return
  fi

  if [ "${COMPOSE:-}" != "" ]; then
    if [ "${WP_ALLOW_ROOT:-0}" = "1" ]; then
      # shellcheck disable=SC2086
      $COMPOSE run --rm ${WPCLI_RUN_OPTIONS:-} "${WPCLI_SERVICE:-wpcli-mariadb}" --allow-root --path="$WP_PATH" "$@"
    else
      # shellcheck disable=SC2086
      $COMPOSE run --rm ${WPCLI_RUN_OPTIONS:-} "${WPCLI_SERVICE:-wpcli-mariadb}" --path="$WP_PATH" "$@"
    fi
    return
  fi

  echo "No WP-CLI runner found. Set WP_CONTAINER, WPCLI_COMMAND, COMPOSE, or install wp." >&2
  exit 1
}

if [ "$PLUGIN_ZIP" != "" ] && [ -f "$PLUGIN_ZIP" ]; then
  if [ "${WP_CONTAINER:-}" != "" ]; then
    CONTAINER_ZIP="/tmp/${PLUGIN_SLUG}-plugin-check.zip"
    container cp "$PLUGIN_ZIP" "${WP_CONTAINER}:${CONTAINER_ZIP}"
    container exec "$WP_CONTAINER" test -f "$CONTAINER_ZIP"
    run_wp plugin install "$CONTAINER_ZIP" --force --activate >/dev/null
  elif [ "$APPLE_CONTAINER_RUNNER" = "1" ]; then
    CONTAINER_ZIP="/tmp/${PLUGIN_SLUG}-plugin-check.zip"
    container cp "$PLUGIN_ZIP" "${APPLE_CONTAINER_WP}:${CONTAINER_ZIP}"
    container exec "$APPLE_CONTAINER_WP" test -f "$CONTAINER_ZIP"
    run_wp plugin install "$CONTAINER_ZIP" --force --activate >/dev/null
  else
    run_wp plugin install "$PLUGIN_ZIP" --force --activate >/dev/null
  fi
fi

run_wp plugin is-installed plugin-check >/dev/null 2>&1 || run_wp plugin install plugin-check --activate
run_wp plugin activate plugin-check >/dev/null

# shellcheck disable=SC2086
if run_wp plugin check "$PLUGIN_SLUG" $PLUGIN_CHECK_FLAGS >"$OUTPUT_FILE" 2>&1; then
  CHECK_STATUS=0
else
  CHECK_STATUS=$?
fi
cat "$OUTPUT_FILE"
if [ "$CHECK_STATUS" -ne 0 ]; then
  exit "$CHECK_STATUS"
fi
if awk '$3 == "ERROR" || $3 == "WARNING" { found = 1 } END { exit found ? 0 : 1 }' "$OUTPUT_FILE"; then
  echo "Plugin Check reported warnings or errors." >&2
  exit 1
fi
