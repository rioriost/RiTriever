#!/bin/sh
set -eu

. "$(dirname "$0")/apple-container-stack.sh"
need_container

case "$STACK" in
  mariadb)
    PROBE_TABLE="ritriever_probe_$(php -r 'echo bin2hex(random_bytes(8));')"
    run_sql -e "CREATE TABLE ${PROBE_TABLE} (
  id INT NOT NULL AUTO_INCREMENT,
  label VARCHAR(32) NOT NULL,
  embedding VECTOR(3) NOT NULL,
  PRIMARY KEY (id),
  VECTOR INDEX (embedding) M=3 DISTANCE=cosine
);"
    trap 'run_sql -e "DROP TABLE IF EXISTS ${PROBE_TABLE}"' EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    run_sql <<SQL
SELECT VERSION() AS version;
INSERT INTO ${PROBE_TABLE} (label, embedding) VALUES
  ('x-axis', VEC_FromText('[1,0,0]')),
  ('y-axis', VEC_FromText('[0,1,0]')),
  ('near-x', VEC_FromText('[0.9,0.1,0]'));
EXPLAIN SELECT label, VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) AS distance
FROM ${PROBE_TABLE}
ORDER BY distance ASC
LIMIT 3;
SELECT label, VEC_DISTANCE_COSINE(embedding, VEC_FromText('[1,0,0]')) AS distance
FROM ${PROBE_TABLE}
ORDER BY distance ASC
LIMIT 3;
SQL
    ;;
  mysql)
    run_sql <<'SQL'
SELECT VERSION() AS version;
SELECT 'MySQL vector dialect is intentionally not assumed by RiTriever yet.' AS note;
SQL
    ;;
  *)
    echo "Usage: $0 mariadb|mysql" >&2
    exit 2
    ;;
esac
