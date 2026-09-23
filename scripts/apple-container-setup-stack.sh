#!/bin/sh
set -eu
. "$(dirname "$0")/apple-container-stack.sh"
need_container

sh "$ROOT_DIR/scripts/apple-container-wordpress.sh" up
if container inspect "$APPLE_CONTAINER_MOCK" >/dev/null 2>&1; then
  container start "$APPLE_CONTAINER_MOCK" >/dev/null
else
  container run -d --name "$APPLE_CONTAINER_MOCK" --network "$APPLE_CONTAINER_NETWORK" \
    -p "127.0.0.1:${APPLE_CONTAINER_EMBEDDING_PORT}:8080" \
    --mount "type=bind,source=${ROOT_DIR}/containers/embedding-mock,target=/app,readonly" \
    -e "EMBEDDING_DIMENSIONS=$DIMENSIONS" \
    php:8.3-cli php -S 0.0.0.0:8080 /app/server.php >/dev/null
fi
tries=0
until curl -fsS "http://127.0.0.1:${APPLE_CONTAINER_EMBEDDING_PORT}/health" >/dev/null; do
  tries=$((tries + 1))
  if [ "$tries" -ge 30 ]; then echo "Embedding mock did not become ready." >&2; exit 1; fi
  sleep 1
done
MOCK_IP="$(container_ip "$APPLE_CONTAINER_MOCK")"
test -n "$MOCK_IP"

if [ -n "${RITRIEVER_PLUGIN_ZIP:-}" ]; then
  container cp "$RITRIEVER_PLUGIN_ZIP" "${APPLE_CONTAINER_WP}:/tmp/ritriever-test.zip"
  run_wp plugin install /tmp/ritriever-test.zip --force >/dev/null
else
  container exec "$APPLE_CONTAINER_WP" mkdir -p "${WP_PATH}/wp-content/plugins/ritriever"
  COPYFILE_DISABLE=1 tar --no-xattrs --no-fflags --exclude=.DS_Store -cf - -C "$ROOT_DIR" \
    ritriever.php uninstall.php readme.txt README.md LICENSE includes assets languages |
    container exec -i "$APPLE_CONTAINER_WP" tar -xf - -C "${WP_PATH}/wp-content/plugins/ritriever"
fi
if run_wp plugin is-active ritriever >/dev/null 2>&1; then run_wp plugin deactivate ritriever >/dev/null; fi
SEARCH_MODE=off
SYNC_ENABLED=false
if [ "$STACK" = mariadb ]; then SEARCH_MODE=full; SYNC_ENABLED=true; fi
run_wp option update ritriever_settings \
  "{\"embedding_provider\":\"custom_http\",\"custom_embedding_preset\":\"custom\",\"custom_embedding_endpoint\":\"http://${MOCK_IP}:8080/embed\",\"custom_embedding_model\":\"custom-http-${DIMENSIONS}\",\"embedding_dimensions\":${DIMENSIONS},\"search_mode\":\"${SEARCH_MODE}\",\"sync_enabled\":${SYNC_ENABLED},\"post_types\":[\"post\",\"page\"],\"post_statuses\":[\"publish\"],\"display_source_badges\":true,\"vector_distance\":\"cosine\",\"vector_index_m\":8,\"top_k\":20,\"min_score\":0}" \
  --format=json >/dev/null
run_wp plugin activate ritriever
run_wp post create --post_status=publish --post_title="Native vector search smoke test" \
  --post_content="RiTriever stores local embeddings in a native vector column and blends semantic retrieval with standard WordPress search." >/dev/null
run_wp post create --post_status=publish --post_title="Standard WordPress search smoke test" \
  --post_content="This vector search fixture checks lexical fallback, source badges, and result ordering." >/dev/null
if [ "$STACK" = mariadb ]; then
  run_wp ritriever backfill --start
  run_wp ritriever backfill --all
fi
echo "${STACK} Apple Container stack ready: ${WP_URL}"
