# Implementation Roadmap

This is the original staged roadmap. Scaffold, background queue, admin diagnostics,
and extraction controls below are now implemented. The current integrity work,
completed acceptance evidence, and explicitly deferred quality experiments are
tracked in [`docs/gpt-6-astra-review-plan-2026-09-18.md`](../docs/gpt-6-astra-review-plan-2026-09-18.md).

## Phase 0 — Scaffold

- Plugin bootstrap.
- Settings schema.
- MariaDB vector schema DDL.
- Embedding providers.
- Post indexing hooks.
- Search interceptor.
- Badge display.
- Plan documents.

## Phase 1 — MariaDB 11.7 validation

- Run schema creation on MariaDB 11.7/11.8.
- Confirm `VECTOR INDEX` syntax under `CREATE TABLE` and `ALTER TABLE`.
- Confirm `VEC_DISTANCE()` uses the index with `EXPLAIN`.
- Tune `M` and `top_k`.
- Confirm inserts/updates with WordPress `$wpdb` encoding.

## Phase 2 — MySQL 9.x dialect

- Verify exact vector type syntax.
- Verify vector literal syntax.
- Verify vector index syntax and optimizer usage.
- Implement `MySqlVectorDialect` or ship a default DDL through the existing filter.
- Add diagnostics that fail closed when index usage is unavailable.

## Phase 3 — Production indexing

- Add async/background indexing queue.
- Add WP-CLI progress reporting and resume support.
- Add content extraction filters.
- Add ACF/custom field inclusion controls.
- Add taxonomy filtering to vector query.

## Phase 4 — Admin/diagnostics

- Full settings page.
- DB capability test button.
- Embedding provider test button.
- Live vector query test.
- Index coverage dashboard.
- Failed post indexing list.

## Phase 5 — Search quality

- RRF tuning controls.
- optional min lexical rank / min vector score.
- snippets from best chunk.
- query expansion option.
- Japanese tokenizer / normalization options for lexical search.
