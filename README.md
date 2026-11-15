# AI Smart Search

Adds AI-powered semantic search to WordPress. Post content is split into
chunks, turned into vector embeddings by an external embedding service, and
stored in MySQL as compact INT8 vectors alongside a FULLTEXT keyword index.
No vector database is needed, so it runs on ordinary shared hosting.

Searches combine keyword and semantic ranking, can replace your theme's
built-in search, and can show an AI-written answer (with linked sources)
above the results.

## Requirements

- WordPress 6.8 or later
- PHP 8.2 or later
- MySQL 5.7+ / MariaDB 10.3+ with InnoDB (FULLTEXT on InnoDB)
- An embedding service reachable from the server (see [Configuration](#configuration))

## How it works

1. **Indexing.** When a post of an enabled post type is saved, a background
   WP-Cron job:
    - builds plain text from the title and content (shortcodes and HTML removed),
    - skips the post if the text hasn't changed since it was last indexed,
    - splits it into ~140-word chunks with a 20-word overlap,
    - requests embeddings 16 chunks at a time,
    - quantizes each 384-dimension vector to 384 bytes (INT8),
    - replaces the post's rows in a single transaction.

    Trashed, deleted, unpublished and password-protected posts are removed from
    the index.

2. **Search.** A MySQL FULLTEXT keyword search and a vector similarity scan
   each return their best-matching post (weak semantic matches are dropped),
   and the two lists are combined with weighted Reciprocal Rank Fusion
   (semantic 1.0, keyword 0.8). If the embedding service is unreachable,
   results fall back to keyword-only. Results are cached for 5 minutes.

3. **Answer** (optional). The top 5 results are sent to a chat model of your
   choice, which writes a short answer citing them as `[1]`, `[2]`, ….

## Configuration

Go to **AI Smart Search → Settings** (or click **Settings** under the plugin
on the Plugins screen) and fill in:

| Field        | Description                                                                              |
| ------------ | ---------------------------------------------------------------------------------------- |
| Endpoint URL | Embedding endpoint URL, http or https (required).                                        |
| API key      | Optional. Sent as `Authorization: Bearer <key>` only when set.                           |
| Model        | Model name, stored with each chunk. Changing it re-embeds posts on their next index run. |

The values are stored in the options `wpaismse_embedding_endpoint`,
`wpaismse_embedding_api_key` and `wpaismse_embedding_model`, so they can also
be set with WP-CLI:

```sh
wp option update wpaismse_embedding_endpoint https://embeddings.example.com/embed
wp option update wpaismse_embedding_model all-MiniLM-L6-v2
wp option update wpaismse_embedding_api_key your-key   # optional
```

## AI answers (chat model)

On **AI Smart Search → Chat**, configure the chat model that writes an answer
from the search results. It is separate from the embedding service.

| Field | Description |
|---|---|
| Endpoint URL | An OpenAI-compatible chat completions URL, e.g. `https://api.openai.com/v1/chat/completions` or a local Ollama / LM Studio server. |
| API key | Optional. Sent as `Authorization: Bearer <key>` only when set. |
| Model | The model name your endpoint expects. |
| Prompt | Instructions sent with the results. Use `{query}` for the visitor's search and `{results}` for the numbered search results. Clear it to use the default. |

The **Prompt preview** shows the prompt filled in with a sample question and
your latest posts, and updates as you type.

## On your site

Both switches are on **AI Smart Search → Search** (the page the top-level
menu opens).

- **Site search:** with **Use AI Smart Search for site search** on (on by
  default), your theme's search box and results page use
  keyword + semantic ranking, and each result's excerpt shows the passage
  that matched. If nothing matches, WordPress's own search is used.
- **AI answer above results:** switch on **Show an AI answer above search
  results** (off by default; needs a chat model) to add the answer box
  automatically, or place it yourself:
  - the **AI Answer** block (e.g. in a block theme's Search Results template), or
  - the `[ai_search_answer]` shortcode (classic themes, widgets).

  The answer loads after the results, is labelled as AI-generated, links its
  `[1]`, `[2]` citations to the source posts and lists them below. It only
  appears on search results pages when a chat model is configured.

## REST API

| Endpoint | Description |
|---|---|
| `GET /wp-json/wpaismse/v1/search?q=…&limit=10` | Hybrid keyword + semantic search results (title, URL, excerpt). |
| `POST /wp-json/wpaismse/v1/answer` with `{"q": "…"}` | An AI answer written from the top 5 results, with numbered sources. |

Both are public, cached for 5 minutes, and rate limited per visitor (30
searches and 10 answers per minute). On sites with plain permalinks use
`/?rest_route=/wpaismse/v1/search&q=…`.

## Post types

On **AI Smart Search → Post Types**, switch on the post types to index. Posts
and pages are on by default; any other public post type (products, events,
and so on) is optional. Only published content of enabled types is indexed.

- Switching a type **off** removes its content from the index right away.
- Switching a type **on** indexes its posts as they are saved. Run a
  re-index to add its existing posts.

## Excluding a post

To keep a single post out of AI search, open it in the editor and switch on
**Exclude from AI search** in the **AI Smart Search** panel of the post
sidebar (a checkbox box in the classic editor). The post is removed from the
index as soon as you save. Switch it off and save to index it again.

## Embedding dimensions

The plugin currently expects **384-dimension** vectors. INT8 quantization
also works for larger models such as 768 or 1536 dimensions, with the same
accuracy, but each search has to read and compare every vector, so the cost
grows with the size:

| Dimensions | Cosine after INT8 (avg / worst) | Top-10 matches float | Vector storage (30k chunks) | PHP scan of 30k vectors |
| ---------- | ------------------------------- | -------------------- | --------------------------- | ----------------------- |
| 384        | 0.99997 / 0.99993               | 10/10                | 11 MB                       | 0.16 s                  |
| 768        | 0.99997 / 0.99995               | —                    | 22 MB                       | 0.33 s                  |
| 1536       | 0.99997 / 0.99995               | 10/10                | 44 MB                       | 0.66 s                  |

Measured with random vectors on a Mac with PHP 8.5; shared hosting is
typically 2–5× slower. For large sites, a 384-dimension model such as
`all-MiniLM-L6-v2` keeps searches fast. Support for configuring the dimension
count is planned.

## Installation

1. Copy the plugin to `wp-content/plugins/wp-ai-smart-search`.
2. Start the embedding service and configure its URL (see
   [Configuration](#configuration)).
3. Activate **WP AI Smart Search** in **Plugins**. This creates the tables.
4. Click **Re-index all posts** on **AI Smart Search → Re-Index** to index
   existing content. After that, posts and pages are indexed automatically as
   they are saved.
5. Optional: set up a chat model on **AI Smart Search → Chat** and switch on
   the AI answer on **AI Smart Search → Search**.

## Indexing status

The **AI Smart Search → Re-Index** page shows the progress of a re-index
(posts processed, indexed, removed and failed, with the last error if any),
and how many posts and chunks are indexed and when
the index was last updated.

A re-index runs in small batches. While the Re-Index page is open it advances
from the page itself; when you leave, WP-Cron continues it in the background.
Every post is re-embedded, so a large site makes many requests to the
embedding service.

## License

GPL v2 or later.
