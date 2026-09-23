# Search and Indexing Flow

## Indexing flow

1. Final post, selected metadata, or taxonomy changes enqueue a coalesced item; ignore autosaves/revisions.
2. A scheduled, CLI, or admin worker claims bounded work with a durable retry schedule.
3. Check synchronization/global-stop gates, index generation, and post eligibility.
4. Build a fresh document snapshot:
   - title
   - excerpt
   - stripped post content
5. Hash the content plus the shared embedding/extraction fingerprint.
6. Skip only when a current-generation receipt and matching stored chunks prove successful persistence.
7. Split at Unicode boundaries with overlap; prepend a stable locale context.
8. Call the embedding provider and validate HTTP status, cardinality, ordering, dimensions, and components.
9. In a pinned database transaction, lock source rows and recheck content, eligibility, generation, and claim.
10. Replace chunks, receipt, and success postmeta atomically. Failure preserves the previous committed data.
11. Advance queue state conditionally; retain retry/backoff wakeups and surface permanent failures.
12. Invalidate tracked caches using a query generation and only mark a verified complete index ready.

## Query flow

1. Front-end main search triggers `pre_get_posts`.
2. After earlier `pre_get_posts` callbacks finish, search mode, index readiness,
   and supported query-context gates run on the effective main-query arguments.
3. Query cache lookup.
4. Vector retrieve:
   - embed user query
   - native vector distance query
   - group chunk results by `post_id`
5. Standard search retrieve:
   - secondary `WP_Query` with `fields=ids`
6. Preserve original inclusion/exclusion and query context when filtering both ID lists through `PostFilter`.
7. Merge via reciprocal rank fusion.
8. Build source map:
   - `rag`
   - `core`
   - `both`
9. Rewrite main query with `post__in` + `orderby=post__in`.
10. Suppress WordPress `s` LIKE clause for rewritten query.
11. Render title badges if enabled.

Unsupported grammar/SQL hooks and operational retrieval failures do not rewrite the main query. Earlier `pre_get_posts` callbacks do not disable retrieval merely by being registered: their effective constraints are copied and checked for candidates. Callbacks at RiTriever's final priority remain unsupported because their ordering cannot be assumed. Native fallback preserves result count and pagination. Failures are not cached as normal hybrid results. Unrelated titles pass through byte-for-byte; decorated titles retain output escaping.

## Badge behavior

- `[RAG][標準検索]` when found by both retrieval paths.
- `[RAG]` when found only by vector retrieval.
- `[標準検索]` when found only by standard search.
- Controlled by `display_source_badges`.
