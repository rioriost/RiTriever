#!/bin/sh
set -eu

ACTION="${1:-up}"
ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

APPLE_CONTAINER_NETWORK="${APPLE_CONTAINER_NETWORK:-ritriever-net}"
APPLE_CONTAINER_DB="${APPLE_CONTAINER_DB:-ritriever-db}"
APPLE_CONTAINER_WP="${APPLE_CONTAINER_WP:-ritriever-wp}"
APPLE_CONTAINER_DB_VOLUME="${APPLE_CONTAINER_DB_VOLUME:-ritriever_mariadb_data}"
APPLE_CONTAINER_WP_VOLUME="${APPLE_CONTAINER_WP_VOLUME:-ritriever_wp_html}"
APPLE_CONTAINER_DB_IMAGE="${APPLE_CONTAINER_DB_IMAGE:-mariadb:${RITRIEVER_MARIADB_VERSION:-11.8}}"
APPLE_CONTAINER_WP_IMAGE="${APPLE_CONTAINER_WP_IMAGE:-wordpress:php8.3-apache}"
APPLE_CONTAINER_WPCLI_IMAGE="${APPLE_CONTAINER_WPCLI_IMAGE:-wordpress:cli-php8.3}"
APPLE_CONTAINER_WP_PORT="${APPLE_CONTAINER_WP_PORT:-8081}"
WP_PATH="${WP_PATH:-/var/www/html}"
WP_URL="${WP_URL:-http://127.0.0.1:${APPLE_CONTAINER_WP_PORT}}"

WP_DB_NAME="${WP_DB_NAME:-wordpress}"
WP_DB_USER="${WP_DB_USER:-wordpress}"
WP_DB_PASSWORD="${WP_DB_PASSWORD:-wordpress}"
WP_DB_ROOT_PASSWORD="${WP_DB_ROOT_PASSWORD:-root}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-password}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.test}"
WORDPRESS_VERSION="${RITRIEVER_WORDPRESS_VERSION:-}"

need_container() {
  if ! command -v container >/dev/null 2>&1; then
    echo "Apple container CLI not found." >&2
    exit 1
  fi
}

ensure_network() {
  container network inspect "$APPLE_CONTAINER_NETWORK" >/dev/null 2>&1 ||
    container network create "$APPLE_CONTAINER_NETWORK" >/dev/null
}

ensure_volume() {
  container volume inspect "$1" >/dev/null 2>&1 ||
    container volume create "$1" >/dev/null
}

container_exists() {
  container inspect "$1" >/dev/null 2>&1
}

start_existing() {
  container start "$1" >/dev/null 2>&1 || true
}

run_wpcli() {
  container exec "$APPLE_CONTAINER_WP" wp --allow-root --path="$WP_PATH" "$@"
}

ensure_wordpress_version() {
  if [ "$WORDPRESS_VERSION" = "" ]; then
    return
  fi
  run_wpcli core download --version="$WORDPRESS_VERSION" --force --skip-content >/dev/null
  actual_version="$(run_wpcli core version)"
  if [ "$actual_version" != "$WORDPRESS_VERSION" ]; then
    echo "Expected WordPress ${WORDPRESS_VERSION}, got ${actual_version}." >&2
    exit 1
  fi
}

ensure_wp_cli() {
  if container exec "$APPLE_CONTAINER_WP" sh -lc "command -v wp" >/dev/null 2>&1; then
    return
  fi
  container exec "$APPLE_CONTAINER_WP" sh -lc "curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp"
}

set_db_host_config() {
  db_host_value="$(db_host)"
  container exec -i \
    -e "RITRIEVER_DB_HOST=${db_host_value}" \
    -e "RITRIEVER_WP_CONFIG=${WP_PATH}/wp-config.php" \
    "$APPLE_CONTAINER_WP" php <<'PHP'
<?php
$file = getenv('RITRIEVER_WP_CONFIG') ?: '/var/www/html/wp-config.php';
$host = getenv('RITRIEVER_DB_HOST');
if (!is_string($host) || $host === '' || !is_readable($file)) {
    exit(1);
}
$config = file_get_contents($file);
if (!is_string($config)) {
    exit(1);
}
$replacement = "define( 'DB_HOST', '" . addcslashes($host, "\\'") . "' );";
$count = 0;
$updated = preg_replace("/define\\(\\s*'DB_HOST'\\s*,\\s*.*?\\);/", $replacement, $config, 1, $count);
if (!is_string($updated) || $count !== 1) {
    exit(1);
}
file_put_contents($file, $updated);
PHP
}

wait_for_db() {
  i=0
  while [ "$i" -lt 60 ]; do
    if container exec "$APPLE_CONTAINER_DB" mariadb-admin ping -h 127.0.0.1 -u"$WP_DB_USER" -p"$WP_DB_PASSWORD" --silent >/dev/null 2>&1; then
      return
    fi
    i=$((i + 1))
    sleep 2
  done
  echo "Timed out waiting for MariaDB container ${APPLE_CONTAINER_DB}." >&2
  container logs "$APPLE_CONTAINER_DB" >&2 || true
  exit 1
}

db_host() {
  ip="$(container inspect "$APPLE_CONTAINER_DB" | awk -F '"' '/"ipv4Address"/ { print $4; exit }' | sed 's#\\/#/#g' | cut -d/ -f1)"
  if [ "$ip" = "" ]; then
    echo "Unable to determine IP address for ${APPLE_CONTAINER_DB}." >&2
    exit 1
  fi
  printf "%s:3306\n" "$ip"
}

wait_for_wordpress_files() {
  i=0
  while [ "$i" -lt 60 ]; do
    if container exec "$APPLE_CONTAINER_WP" test -f "${WP_PATH}/wp-load.php" >/dev/null 2>&1; then
      return
    fi
    i=$((i + 1))
    sleep 2
  done
  echo "Timed out waiting for WordPress files in ${APPLE_CONTAINER_WP}." >&2
  container logs "$APPLE_CONTAINER_WP" >&2 || true
  exit 1
}

up() {
  need_container
  ensure_network
  ensure_volume "$APPLE_CONTAINER_DB_VOLUME"
  ensure_volume "$APPLE_CONTAINER_WP_VOLUME"

  if container_exists "$APPLE_CONTAINER_DB"; then
    start_existing "$APPLE_CONTAINER_DB"
  else
    container run -d \
      --name "$APPLE_CONTAINER_DB" \
      --network "$APPLE_CONTAINER_NETWORK" \
      --mount "type=volume,source=${APPLE_CONTAINER_DB_VOLUME},target=/var/lib/mysql" \
      -e "MARIADB_DATABASE=${WP_DB_NAME}" \
      -e "MARIADB_USER=${WP_DB_USER}" \
      -e "MARIADB_PASSWORD=${WP_DB_PASSWORD}" \
      -e "MARIADB_ROOT_PASSWORD=${WP_DB_ROOT_PASSWORD}" \
      "$APPLE_CONTAINER_DB_IMAGE" \
      --character-set-server=utf8mb4 \
      --collation-server=utf8mb4_unicode_ci >/dev/null
  fi
  wait_for_db

  if container_exists "$APPLE_CONTAINER_WP"; then
    start_existing "$APPLE_CONTAINER_WP"
  else
    container run -d \
      --name "$APPLE_CONTAINER_WP" \
      --network "$APPLE_CONTAINER_NETWORK" \
      -p "127.0.0.1:${APPLE_CONTAINER_WP_PORT}:80" \
      --mount "type=volume,source=${APPLE_CONTAINER_WP_VOLUME},target=${WP_PATH}" \
      -e "WORDPRESS_DB_HOST=$(db_host)" \
      -e "WORDPRESS_DB_NAME=${WP_DB_NAME}" \
      -e "WORDPRESS_DB_USER=${WP_DB_USER}" \
      -e "WORDPRESS_DB_PASSWORD=${WP_DB_PASSWORD}" \
      -e "WORDPRESS_DEBUG=1" \
      -e "WORDPRESS_CONFIG_EXTRA=define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );" \
      "$APPLE_CONTAINER_WP_IMAGE" >/dev/null
  fi
  wait_for_wordpress_files
  ensure_wp_cli
  ensure_wordpress_version
  if container exec "$APPLE_CONTAINER_WP" test -f "${WP_PATH}/wp-config.php" >/dev/null 2>&1; then
    set_db_host_config
  fi

  if ! run_wpcli core is-installed >/dev/null 2>&1; then
    run_wpcli core install \
      --url="$WP_URL" \
      --title="RiTriever" \
      --admin_user="$WP_ADMIN_USER" \
      --admin_password="$WP_ADMIN_PASSWORD" \
      --admin_email="$WP_ADMIN_EMAIL" \
      --skip-email >/dev/null
  elif [ "$WORDPRESS_VERSION" != "" ]; then
    run_wpcli core update-db >/dev/null
  fi

  echo "Apple Container WordPress is ready at ${WP_URL}"
  echo "Use APPLE_CONTAINER_NETWORK=${APPLE_CONTAINER_NETWORK} APPLE_CONTAINER_WP_VOLUME=${APPLE_CONTAINER_WP_VOLUME} for WP-CLI checks."
}

down() {
  need_container
  container delete --force "$APPLE_CONTAINER_WP" >/dev/null 2>&1 || true
  container delete --force "$APPLE_CONTAINER_DB" >/dev/null 2>&1 || true
  container network delete "$APPLE_CONTAINER_NETWORK" >/dev/null 2>&1 || true
}

reset() {
  down
  container volume delete "$APPLE_CONTAINER_WP_VOLUME" >/dev/null 2>&1 || true
  container volume delete "$APPLE_CONTAINER_DB_VOLUME" >/dev/null 2>&1 || true
}

status() {
  need_container
  container list --all | grep -E "(${APPLE_CONTAINER_DB}|${APPLE_CONTAINER_WP})" || true
}

case "$ACTION" in
  up)
    up
    ;;
  down)
    down
    ;;
  reset)
    reset
    ;;
  status)
    status
    ;;
  *)
    echo "Usage: $0 [up|down|reset|status]" >&2
    exit 2
    ;;
esac
