# Local Docker test environment

This directory supports two isolated WordPress stacks for RiTriever development on the MacStudio Docker Desktop host.

## Stacks

- MariaDB stack: `wp-mariadb` + `db-mariadb`
  - Default URL: `http://192.168.1.35:8081`
  - Primary native-vector target.
- MySQL stack: `wp-mysql` + `db-mysql`
  - Default URL: `http://192.168.1.35:8082`
  - Candidate/compatibility target. Native vector search is disabled by default until the exact MySQL dialect is verified.
- Embedding mock: `embedding-mock`
  - Default URL from host: `http://192.168.1.35:18080/health`
  - Container URL used by WordPress: `http://embedding-mock:8080/embed`

## Quick start

From the repository root:

1. `cp .env.example .env`
2. `./scripts/docker-setup-stack.sh mariadb`
3. `./scripts/docker-vector-probe.sh mariadb`
4. `./scripts/docker-setup-stack.sh mysql`

Admin login for both stacks:

- User: `admin`
- Password: `password`

## Smoke tests

After setup, validate the default runtime posture:

1. `./scripts/docker-smoke-test.sh mariadb`
2. `./scripts/docker-smoke-test.sh mysql`

The MariaDB smoke test requires indexed chunks and both `[RAG]` / `[標準検索]` badges on a search page. The MySQL smoke test expects the plugin to be active with native vector search disabled by default.

## WordPress version compatibility

The reproducible compatibility targets install a temporary release-equivalent ZIP into isolated local Docker Compose projects:

1. `make wordpress-compat-baseline` — WordPress 7.0.4 + MariaDB.
2. `make wordpress-compat-stable` — WordPress 7.1 + MariaDB + Plugin Check.
3. `make wordpress-compat-matrix` — baseline and stable MariaDB versions.
4. `make wordpress-compat-mysql` — WordPress 7.1 + MySQL fallback.

Override versions with `WP_BASELINE_VERSION`, `WP_STABLE_VERSION`, or use `make wordpress-compat WP_VERSION=<version> WP_COMPAT_DB=mariadb|mysql`. These Compose stacks are local test helpers, not a production deployment path.

## Import WXR test data

Keep WXR exports untracked and do not add them to `.gitignore` if they are still needed locally. Import from a repository-relative path:

1. `./scripts/docker-import-wxr.sh mariadb riost.WordPress.2026-05-04.xml`
2. `./scripts/docker-import-wxr.sh mariadb riost.WordPress.2026-05-04.xml --delete-after-import`

The helper runs importer operations as uid/gid `33:33` to avoid `wp-content/upgrade` permission errors in the Docker WordPress volume.

## Reset

Use this when changing vector dimensions or database versions:

1. `./scripts/docker-reset.sh`
2. `./scripts/docker-setup-stack.sh mariadb`

## Notes

- The plugin is bind-mounted into both WordPress containers as `ritriever`.
- The setup script configures `custom_http` embeddings with a deterministic local mock, so no OpenAI API key is needed for smoke tests.
- MariaDB should be treated as the implementation target first. MySQL can remain unsupported if vector type/index/function behavior is incomplete or unstable.
- WXR exports are local test data. Do not commit them; delete them after import when they are no longer needed.
