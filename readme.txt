=== RiTriever ===
Contributors: rioriost
Tags: search, semantic search, vector search, embeddings, rag
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds RAG-style vector retrieval to WordPress search using native database vector indexes and configurable embeddings.

== Description ==

RiTriever adds RAG-style vector retrieval to WordPress search. Published content is embedded, stored in a local vector table, and blended with standard WordPress search results.

The plugin is intended for WordPress sites that can use native database vector support, primarily MariaDB 11.7 or later. It can index posts, pages, and configured public post types, then use those vectors during front-end search.

Main features:

* RAG-style search blending with standard WordPress search.
* Native vector storage in the WordPress database.
* OpenAI and Azure OpenAI support for external embedding APIs.
* Local or self-hosted embedding support through Ollama, LM Studio, Infinity, TEI, or Custom HTTP endpoints.
* RAG target language selection from WordPress-supported locales.
* Admin diagnostics for database support, embedding providers, indexing progress, and live vector queries.

External API use is optional. WordPress 7.0 AI Client does not provide embedding generation, so RiTriever uses direct embedding APIs when external embeddings are configured. Post content and configured custom field values are sent to the selected provider to create embeddings.

== External services ==

RiTriever connects to external services only when you select an external embedding provider. It sends post titles, post excerpts, post content chunks, selected taxonomy terms, selected custom field values, and short admin test strings when indexing or testing embeddings. The vectors returned by the provider are stored in your WordPress database.

WordPress 7.0 includes the WordPress AI Client for supported AI capabilities, but the current AI Client announcement and implementation do not include an embeddings API. Because RiTriever requires text embeddings for vector search, it calls embedding providers directly instead of using WordPress AI Client. Reference: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/

OpenAI sends embedding requests to OpenAI for `text-embedding-3-small` or `text-embedding-3-large`. This service is provided by OpenAI: Terms of use https://openai.com/policies/terms-of-use/ and Privacy policy https://openai.com/policies/privacy-policy/.

Azure OpenAI sends embedding requests to your configured Azure OpenAI endpoint. This service is provided by Microsoft Azure: Terms of use https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure/MCA and Privacy statement https://privacy.microsoft.com/privacystatement.

Local or self-hosted endpoints, including Ollama, LM Studio, Infinity, TEI, and Custom HTTP, receive the same embedding inputs when selected. Review the terms, privacy policy, and network location for the endpoint you operate or configure.

== Installation ==

1. Upload the `ritriever` folder to `/wp-content/plugins/`, or install the release ZIP from the WordPress admin.
2. Activate `RiTriever` from the Plugins screen.
3. Open Settings -> RiTriever.
4. Run the database capability test.
5. Choose the RAG target language.
6. Configure and test the embedding provider.
7. Initialize the index.

== Frequently Asked Questions ==

= Which external embedding providers are supported? =

OpenAI and Azure OpenAI are supported as external hosted embedding providers.

= Can I use a local embedding server? =

Yes. Ollama, LM Studio, Infinity, TEI, and Custom HTTP endpoints are available for local or self-hosted embeddings.

= Does this plugin send content to external APIs? =

Only when an external embedding provider is configured. See the External services section for details.

= Does it require native vector database support? =

Yes. MariaDB 11.7 or later is the primary supported target for native vector columns and indexes.

= What happens when I change the target language or embedding model? =

The vector table must be rebuilt. Run initialization again after changing target language, provider, model, dimensions, or vector distance settings.

== Screenshots ==

No screenshots are included in this release.

== Changelog ==

= 0.2.3 =
* Resolve remaining Plugin Check SQL preparation warnings for dynamic queue, vector, and uninstall queries.

= 0.2.2 =
* Escape search title filter output consistently and prepare plugin table identifiers in direct SQL calls.

= 0.2.1 =
* Tighten WordPress.org review gates and clarify external embedding API documentation.

= 0.2.0 =
* Rename the public plugin name and WordPress.org slug to RiTriever.
* Add RAG target language selection based on WordPress-supported locales.
* Document why direct embedding APIs are used instead of WordPress AI Client.
* Add WordPress.org release gates for PHPCS, Plugin Check, readme, i18n, and package contents.

== Upgrade Notice ==

= 0.2.3 =
Plugin Check SQL warning cleanup. No reindexing is required.

= 0.2.2 =
Security hardening for WordPress.org review feedback. No reindexing is required.

= 0.2.1 =
Review-gate and documentation update. No reindexing is required.

= 0.2.0 =
Changing the target language or embedding settings requires reinitializing the vector index.
