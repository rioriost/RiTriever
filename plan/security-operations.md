# Security and Operations Plan

## Secrets

- Never hard-code API keys.
- Store keys in the WordPress options row initially; later support `wp-config.php` constants.
- Mask keys in admin UI.

## Data privacy

- Embedding APIs receive chunks of site content.
- Admin UI must warn when private/draft statuses are enabled.
- Password-protected posts are always excluded.
- Future filters should allow redacting custom fields before embedding.

## Database operations

- Native vector tables are not managed by `dbDelta()` because vector indexes may not be recognized.
- Use explicit DDL and log failures.
- Provide uninstall cleanup before public release.

## Performance

- Native vector index is required for supported mode.
- Query results are transient-cached.
- Background indexing is required before large sites.
- Keep chunk sizes bounded to prevent oversized embedding requests.
- All indexed source tables must be InnoDB. Pinned database sessions prevent wpdb
  reconnect/replay from breaking transaction and advisory-lock boundaries.
- Settings changes invalidate readiness without deleting vectors. Initialization
  is explicit, requires a backup, and may incur provider charges.

## Failure modes

- Embedding provider failure: leave previous embeddings intact and record postmeta last error.
- Vector schema unsupported: plugin should not rewrite search; diagnostics should show unsupported DB.
- Vector retrieval failure: core search carries the result set.
- Retrieval failure leaves the original main query and native pagination intact;
  it does not cache a truncated lexical candidate set as a successful hybrid result.
- Queue cancellation, generation changes, and concurrent edits fence stale writes.
  A recurring watchdog handles abandoned claims and bounded retry backoff.
- Uninstall deletes tracked query/live transients before their registries, including
  persistent-object-cache entries, without flushing unrelated cache namespaces.
