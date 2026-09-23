#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
. scripts/apple-container-runtime.sh
need_container
: "${RITRIEVER_TEST_CONTAINER:?Set an explicitly isolated Apple Container WordPress name}"
case "$RITRIEVER_TEST_CONTAINER" in
  ritriever-test-*|ritriever-fix-*) ;;
  *) echo "Refusing to modify a container without a ritriever-test- or ritriever-fix- prefix." >&2; exit 2 ;;
esac

PLUGIN_PATH=/var/www/html/wp-content/plugins/ritriever
FIXTURE_PATH=/tmp/ritriever-integration
container exec "$RITRIEVER_TEST_CONTAINER" mkdir -p "$PLUGIN_PATH" "$FIXTURE_PATH"
COPYFILE_DISABLE=1 tar --no-xattrs --no-fflags --exclude=.DS_Store -cf - ritriever.php uninstall.php readme.txt includes assets languages |
  container exec -i "$RITRIEVER_TEST_CONTAINER" tar -xf - -C "$PLUGIN_PATH"
COPYFILE_DISABLE=1 tar --no-xattrs --no-fflags -cf - -C tests/integration embedding-fixture.php wordpress.php multisite.php faults.php search.php changes.php synonyms.php |
  container exec -i "$RITRIEVER_TEST_CONTAINER" tar -xf - -C "$FIXTURE_PATH"

run_wp() {
  container exec -e RITRIEVER_INTEGRATION_TEST=1 "$RITRIEVER_TEST_CONTAINER" \
    wp --allow-root --path=/var/www/html \
    --require="${FIXTURE_PATH}/embedding-fixture.php" "$@"
}

run_wp plugin activate ritriever
run_wp eval-file "${FIXTURE_PATH}/wordpress.php" prepare
run_wp ritriever backfill --start
run_wp ritriever backfill --all
run_wp ritriever backfill --start
run_wp ritriever backfill --all
run_wp eval-file "${FIXTURE_PATH}/faults.php"
run_wp eval-file "${FIXTURE_PATH}/changes.php"
run_wp eval-file "${FIXTURE_PATH}/search.php"
run_wp eval-file "${FIXTURE_PATH}/synonyms.php"
run_wp eval-file "${FIXTURE_PATH}/wordpress.php" verify
