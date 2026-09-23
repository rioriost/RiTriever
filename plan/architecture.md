# RiTriever Architecture Plan

The implemented integrity changes and their acceptance criteria are recorded in
[`docs/gpt-6-astra-review-plan-2026-09-18.md`](../docs/gpt-6-astra-review-plan-2026-09-18.md).
This document describes the current module boundaries; MySQL native retrieval remains disabled.

## Goal

Create a separate WordPress plugin that performs native-database semantic retrieval using MariaDB 11.7+ (or a MySQL 9.x installation with verified vector type + vector index support), then blends RAG results with standard WordPress search results.

## Core modules

1. **Settings**
   - Search mode: `off`, `a_b_admin`, `full`.
   - Embedding provider: OpenAI, Azure OpenAI, or local/custom HTTP endpoint. WordPress AI Client is not used because WordPress 7.0 does not provide an embeddings API.
   - Embedding dimensions/model.
   - Native vector index parameters: distance (`cosine` or `euclidean`) and `M`.
   - Badge display option.

2. **Vector schema/capability layer**
   - Detect MariaDB vs MySQL and exact version.
   - MariaDB 11.7+: create `VECTOR(n)` column and `VECTOR INDEX`.
   - MySQL 9.x: require a dialect-specific DDL filter until exact syntax and optimizer behavior are verified on target.

3. **Embedding layer**
   - `EmbeddingProviderInterface`.
   - `OpenAiEmbeddingProvider`.
   - `CustomHttpEmbeddingProvider` for Infinity, Ollama proxies, local services, or vendor-specific embedding APIs.

4. **Indexing layer**
   - Coalesce final post saves, selected metadata, and taxonomy changes into the durable queue.
   - Extract post title, excerpt, and stripped content.
   - Chunk text with overlap.
   - Embed each chunk.
   - Validate all vectors before atomically replacing chunks, a storage receipt, and success metadata.
   - Check source content, index generation, and queue claim at the commit boundary.
   - Keep settings changes non-destructive; explicit initialization creates a new generation.

5. **Retrieval/search layer**
   - Vector retrieval: query embedding → `ORDER BY VEC_DISTANCE*()` → top K post IDs.
   - Lexical retrieval: bounded secondary `WP_Query` with native search.
   - Reciprocal rank fusion.
   - Main query rewrite: `post__in` + `orderby=post__in`.
   - Optional source badges above result title.
   - Incomplete indexes, unsupported query contexts, and retrieval errors leave native search unchanged.
   - Cache entries carry index/query generations and are tracked across database and persistent object caches.

## Why copy only selected code from wp_rag_search_plugin?

Reused concepts:
- `pre_get_posts` rewrite pattern.
- `posts_search` suppression for rewritten queries.
- source badges.
- query cache shape.
- post eligibility logic.
- settings/defaults/logging shape.

Not reused directly:
- Dify providers.
- Dify diagnostics.
- Dify workflow proxy.
- provider HTTP retrieve contracts.

The new project's center is a local native-vector repository, not an external RAG provider.
