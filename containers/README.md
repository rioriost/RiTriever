# Apple Container test environment

Tests use Apple's `container` CLI exclusively. Docker is not a supported test
runtime. Legacy `COMPOSE`, `COMPOSE_FILE`, and `WPCLI_COMMAND` overrides are rejected
before any runtime is invoked. The former `docker-*.sh` scripts and Compose
manifests have been removed so they cannot select the wrong runtime.

`container-compose 1.1.0` has no `config`, `run`, or `exec` commands and is not a
drop-in replacement for those former flows. The scripts instead use native
`container run/exec/cp`, explicit readiness checks, and named resources.
There is no Compose validation step and no automatic fallback.

## Prerequisites

Install and start Apple Container on a supported Mac. Run:

```sh
make apple-container-check
```

This fails if the CLI or runtime is unavailable. It never silently skips the gate.
PHP, Composer, Node.js, curl, zip, and the macOS tar utility are used by the
remaining build/test helpers.

## Disposable compatibility matrix

```sh
make wordpress-compat-baseline
make wordpress-compat-stable
make wordpress-compat-matrix
make wordpress-compat-mysql
make wordpress-compat WP_VERSION=7.1 WP_COMPAT_DB=mariadb
```

Each run creates unique container/network/volume names, installs a release-equivalent
ZIP, pins the requested WordPress version, and removes only those resources on exit.
The stable target also runs Plugin Check. The matrix runs serially, including under
`make -j`, because the default host ports are shared.

Override `RITRIEVER_COMPAT_PORT` (18081) and
`RITRIEVER_COMPAT_EMBEDDING_PORT` (19080) for concurrent independent runs.
Database ports are not exposed to the host. HTTP ports bind to loopback only.
The local embedding mock needs no API key; its actual private container IP is
configured explicitly rather than assuming service-name DNS.

## Persistent manual stacks

```sh
sh scripts/apple-container-setup-stack.sh mariadb
sh scripts/apple-container-smoke-test.sh mariadb
sh scripts/apple-container-vector-probe.sh mariadb
sh scripts/apple-container-reset-stack.sh mariadb
```

Use `mysql` instead of `mariadb` for the fallback stack. Native vectors remain
disabled there. WordPress defaults to `http://127.0.0.1:8081` for MariaDB and port
8082 for MySQL. Both use `admin` / `password` for local testing only.
The embedding mock defaults to port 18080; use a different
`APPLE_CONTAINER_EMBEDDING_PORT` if both manual stacks run simultaneously.

`APPLE_CONTAINER_NETWORK`, `APPLE_CONTAINER_DB`, `APPLE_CONTAINER_WP`,
`APPLE_CONTAINER_MOCK`, `APPLE_CONTAINER_DB_VOLUME`, `APPLE_CONTAINER_WP_VOLUME`,
and the port variables select the manual resources. Supply the same overrides
to setup, smoke, probe, import, and reset. Reset deletes that stack's containers
and data volumes. No repository-wide bind mount is used: only distributable
plugin files and the embedding mock are copied or mounted.

`.env.example` documents supported overrides. Scripts do not automatically load
local `.env` files or old Compose settings.

## Import synthetic WXR data

On an already prepared manual stack:

```sh
sh scripts/apple-container-import-wxr.sh mariadb path/to/export.xml
sh scripts/apple-container-import-wxr.sh mariadb path/to/export.xml --delete-after-import
```

The helper copies only the selected file to a temporary container path, imports
it with WP-CLI, and removes the temporary copy. The optional deletion flag removes
the host file only after successful import. Do not commit private exports or use
them against an external embedding provider without reviewing the data.

## Existing WordPress for Plugin Check

Use `WP_CONTAINER=<apple-container-name> make plugin-check` or a locally installed
`wp` CLI with `LOCAL_WP_PATH`. Command-string and Compose runners are intentionally
unsupported. `make release` uses the same Apple-only runtime gate.
