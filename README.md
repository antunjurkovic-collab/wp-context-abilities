# WP Context Abilities

WP Context Abilities exposes read-only structured WordPress context for AI workflows through REST and, when available, the WordPress Abilities API.

Version 0.1.1 is a locally tested reference implementation, not a new hosted
validation or a demonstration of better AI review quality.

The v0 package is intentionally narrow: it provides post/page content context only. The project name is broader so future versions can add other context providers such as media, taxonomies, navigation, templates, and plugin-defined public context without renaming the plugin.

## v0 Scope

- authenticated post/page Machine Representation (MR)
- Markdown projection
- application CIDs separate from HTTP byte validators
- catalog and changed-content discovery
- WordPress Abilities API registration when available

This package does not include write routes, snapshot routes, editor UI, custom MCP runtime code, public read routes, AI suggestion endpoints, or benchmark claims from older experimental builds.

## REST API

### GET `/wp-json/wp-context/v1/posts/{id}`

Returns JSON MR for a post/page the current user can edit.

Included fields:

- `rid`
- `cid`
- title, status, modified/published dates
- author and featured image metadata
- categories/tags sorted deterministically
- `blocks[]`
- `core_content_text`
- `word_count`
- representation links

Image blocks retain `altText` from the saved image HTML and a plain-text
`caption`. An explicitly empty HTML alt attribute stays empty; the block
attribute is a fallback only when HTML has no alt attribute. Paragraph, heading,
and quote blocks with inline anchors include `link_targets` (`text`/`url`) and
their Markdown projection. Those content link targets participate in the
application CID, separately from the top-level representation links.
List items preserve text and nesting, but their link destinations are not
currently preserved.

Response headers:

- `ETag: "sha256-<serialized-response-hash>"` (not the application `cid`)
- `Last-Modified`
- `Cache-Control: max-age=0, must-revalidate`
- `Content-Digest` over the same serialized bytes emitted by the plugin

Supports `If-None-Match` for `304 Not Modified`, using weak comparison of complete
quoted entity-tags. Commas inside a tag are data, and `*` is a wildcard only as
the complete field value. Malformed lists do not produce a plugin-generated 304.

For ordinary GET responses, serialization includes `_pretty`, WordPress JSON
encoding options, response links/embedding, and `rest_pre_echo_response` filters.
The plugin serializes once, then hashes and emits those bytes. HEAD and 304
responses have no body and omit `Content-Digest`. JSONP and `_envelope` requests
remain WordPress-owned responses without plugin-generated validators.

These identities cover the plugin's output, not arbitrary later output-buffer,
compression, web-server, or proxy transformations. A transforming layer must
recompute or remove affected integrity headers. No intermediary compatibility
claim follows from local tests.

### GET `/wp-json/wp-context/v1/posts/{id}/md`

Returns Markdown projection for the same content context.

### GET `/wp-json/wp-context/v1/catalog`

Returns an authenticated index for best-effort post-modification discovery.

Query parameters:

- `since=ISO` or `cursor=ISO`
- `status=draft|publish|any`
- `types=post,page`
- `per_page=1..100`
- `page=1..n`

### Cursor Semantics

`cursor` is a high-water mark for the newest `modified` timestamp returned in the current response. It is not a next-page cursor.

Pagination for a fixed timestamp filter:

1. Choose a fixed `since` timestamp for the sync window.
2. Follow `next_page` with the same `since`, `per_page`, and other filters until `next_page` is `null`.
3. Do not stop on an empty `items` array: a query page can contain only posts the user cannot edit. A full final query page can require one additional empty request.
4. Only after pagination completes may the highest returned `cursor` be retained as a subsequent post-modification filter.

Do not request page 1, immediately store its `cursor`, and then use that cursor for the next request while older pages from the same window still exist; that can skip older changed items.

This is not a snapshot or a complete change feed. Concurrent edits, permission
changes, and deletions can affect offset pagination; timestamps have finite
precision. Author, taxonomy, or image metadata can change the projection without
changing `post_modified_gmt`. Consumers needing a complete corpus must reconcile
it separately; the cursor does not guarantee zero misses or embedding freshness.

## Abilities API

On WordPress 6.9+ or the Abilities API plugin, this package registers category `wp-context` and these read-only abilities:

- `wp-context/get-post-context`
- `wp-context/get-post-markdown`
- `wp-context/list-content-context`
- `wp-context/get-changed-content`

MCP exposure should happen through the official WordPress MCP Adapter once the abilities are registered.

Ability execution uses the WordPress Abilities API input wrapper. For example:

```text
/wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?input[post_id]=123
/wp-json/wp-abilities/v1/abilities/wp-context/get-post-markdown/run?input[post_id]=123
/wp-json/wp-abilities/v1/abilities/wp-context/list-content-context/run?input[per_page]=10
/wp-json/wp-abilities/v1/abilities/wp-context/get-changed-content/run?input[since]=2026-01-01T00:00:00Z&input[per_page]=10
```

Direct parameters such as `?post_id=123` are intentionally not the documented call shape for ability execution; use `input[...]`.

## Live Validation

Historical 0.1.0 hosted smoke validation checked:

- clean REST route surface exposed under `/wp-json/wp-context/v1`
- legacy `/wp-json/dual-native/v1` route surface absent
- removed snapshot/public-read/AI-suggest/block-write routes return `404`
- MR and Markdown endpoints return `ETag` and `Content-Digest` headers
- `If-None-Match` returns `304 Not Modified`
- `wp-context` Abilities category is discoverable
- all four read-only abilities execute successfully through `input[...]`

That 27-check smoke result did not establish digest correctness, complete
projection fidelity, complete discovery, or improved agent outcomes. It is not
validation of the current correction on a hosted site.

## Determinism And Integrity

- CID is `sha256-<hex>` over canonical MR with sorted keys.
- Read-only REST and Ability calls compute CIDs in memory and do not persist post meta.
- Links are excluded from the application CID by default, but are included in the independently computed JSON HTTP ETag.
- Taxonomies are sorted by ID before hashing.
- Markdown HTTP ETags cover the emitted Markdown bytes after filters.
- Ordinary 200 GET responses include `Content-Digest: sha-256=:<base64>:` over those same bytes.
- The MR ability no longer returns an `etag` alias for its application CID. Catalog representation links likewise no longer label the CID as an HTTP validator. Save the ETag from the actual REST response instead.
- The Markdown ability's `etag` field identifies its `markdown` string only, not the surrounding ability JSON response or a separately filtered REST response.

To exclude additional volatile fields from CID:

```php
add_filter('wpca_cid_exclude_keys', function(array $keys) {
    return array_merge($keys, array('modified', 'published', 'status'));
});
```

## Public Discussion

This proposal is being discussed in WordPress AI Discussions:

https://github.com/WordPress/ai/discussions/835

## Proposal

See `docs/WORDPRESS-AI-CONTEXT-ABILITIES-PROPOSAL.md` for the focused WordPress AI proposal: REST-first, Application-Password-compatible, read-only structured content context plus optional Abilities API registration.

The existing `scripts/validate-live.ps1` checks REST routes, removed-route 404s,
header presence, Abilities discovery, and ability execution. It remains a smoke
test, not a fidelity or digest-correctness test.

## Local Regression Tests

```text
php tests/run-regressions.php /path/to/wordpress
```

The path supplies WordPress source classes; no WordPress installation, database,
credentials, or hosted requests are used. Tests execute the real block parser,
REST request/response classes, JSON options, and response-serving pipeline, with
in-memory doubles for database and host services. They cover link-only changes,
pretty JSON, late filters, conditional responses, nested and legacy lists,
permission-filtered pagination, and REST/Ability identity separation. This is
not a full installation, intermediary, or AI-effectiveness test.

The parser-only follow-up on 2026-10-15 passed 69/69 class regressions, including
18 new quoted-comma, malformed-list, and valid-list controls. Its fresh result is
`evidence/etag-quoted-comma-20261015/summary.json`. Earlier Docker and model reports
remain unchanged and bind the pre-parser-correction sources; this follow-up did
not rerun Docker or invoke a model.

Independent review accepted this bounded local 0.1.1 correction on 2026-10-15
after reproducing all 69 regressions and verifying the retained source bindings.
The demonstrated result is reusable normalization and conditional retrieval on
the fixture, not better AI review quality or reduced real-world integration
effort. This local acceptance is not a release or hosted-deployment result.

A concise [public results summary](evidence/public-0.1.1/summary.json) records the
current runtime identities and the separate historical experiments. It is an
extract from retained reports, not a new execution. Full historical run records
remain local; their SHA-256 identities are included for provenance.

### Disposable Docker HTTP Test

```text
py -3.13 -B tests/run-docker.py --output evidence/<new-run-id>/summary.json
```

This uses the script's pinned, already-cached WordPress and MariaDB images
(`--pull=never`), creates a new installation published only on loopback, and
copies the five runtime files into a normal plugin installation. It exercises
real Application Password authentication, REST and Abilities responses, body
hashes, Markdown lists, and permission-filtered pagination. The source mount is
read-only; only this run's containers, volumes, and network are removed afterward.
Use a new output path for each run; retained results are never overwritten.

On 2026-10-15, WordPress 7.0.2 / PHP 8.2.33 / MariaDB 11.4.12 passed all 47 Docker
suite checks, all 51 class regressions, and 16 installed-WordPress fidelity
checks. The historical report is identified by SHA-256 in the public results
summary. The fidelity
checks cover image alt precedence/emptiness, captions, link labels/destinations,
entity decoding, Markdown escaping, and content-link CID coverage. Runtime files
matched the installed plugin and remained unchanged during the tests. Earlier
results are retained separately.

These tests do not establish hosted/proxy compatibility, complete block
fidelity, complete change discovery, or improved AI review outcomes.

### Editorial Retrieval Comparison

```text
py -3.13 -B tests/run-docker.py --editorial-comparison --output evidence/<new-run-id>/summary.json
```

The 2026-10-15 comparison uses one synthetic draft with headings, nested lists,
an inline image/caption/link, author, taxonomy, and featured-image metadata.
The Core REST baseline uses `context=edit`, selected `_fields`, and
`_embed=author,wp:term,wp:featuredmedia` in one request, as supported by the
[WordPress REST API](https://developer.wordpress.org/rest-api/using-the-rest-api/global-parameters/).

| Retrieval | Client requests | Response body bytes | Expected facts available |
| --- | ---: | ---: | ---: |
| Core REST with embedding | 1 | 5,701 | 13/13 |
| Context JSON | 1 | 2,003 | 13/13 |
| Context JSON plus Markdown | 2 | 2,411 | 13/13 |

The first comparison found that context outputs omitted the inline image alt
text, caption, and internal link destination. The corrected mapper retains all
three in JSON and Markdown, and the comparison now requires all 13 facts to
match. The initial 10/13 result remains in the local historical records.

Both context representations returned 304 with zero body bytes on an unchanged
conditional follow-up; the Core response exposed no validator in this test and
returned its body again. After consumer normalization, all three paths produced
the same complete 556-byte fact object. This fixture demonstrates a smaller
server projection and conditional transfer, while equally prepared model
context can also be assembled from Core REST.

The result is summarized in the public results summary; the complete report and
eight synthetic task/response/fact artifacts remain in the local records. Five
alternating timing samples per variant are exploratory. No model was invoked in
this retrieval comparison, no review-quality or token
benefit was measured, and the WordPress AI `core/content-query` ability was not
part of this comparison. Runtime files remained unchanged and all disposable
Docker resources were removed.

### Same-Model Editorial Pilot

The separate 2026-10-15 pilot used three fresh invocations with the same
instructions, output schema, `gpt-6.1-sol` model, and low reasoning effort: Core
REST JSON, context JSON, and compact consumer-normalized context as a control.
There was one completion per input, on one synthetic draft.

Each completion returned all 13 facts correctly, with all nine editorial
evidence quotes present in the supplied context across the three answers.
The grader checked exact fact values and quote presence, not editorial quality;
no independent human quality rating was performed. The pilot is summarized in
the public results summary, with the full report retained locally.

This cannot establish a general quality, latency, or integration-effort
advantage. The plugin provides a reusable server projection and conditional
retrieval; equally complete review input can be prepared from Core REST.

## Install

1. Copy `wp-context-abilities/` into `wp-content/plugins/`.
2. Activate `WP Context Abilities` in WP Admin.
3. Use application-password or REST nonce authentication for the default endpoints.

## Security And Permissions

- MR, Markdown, and catalog require authenticated WordPress users.
- Post context endpoints require `edit_post` for the requested post.
- Catalog requires `edit_posts` and filters each item by `edit_post`.
- There are no mutation endpoints in this package.
- This does not restrict an Application Password to read-only access elsewhere in WordPress. The credential retains the user's applicable authority at other endpoints.

## Filters

- `wpca_mr($mr, $post_id)`
- `wpca_blocks($blocks, $post_id, $post)`
- `wpca_map_block($out, $raw_block)`
- `wpca_markdown($md, $mr, $req)`
- `wpca_catalog_args($args, $req)`
- `wpca_can_read_mr($allow, $id, $req)`
- `wpca_cid_exclude_keys($keys, $payload)`

## Positioning

Public proposal name:

> Structured Content Context Abilities for WordPress AI

This plugin is not an agent runtime, not a replacement for REST, not a mutation layer, and not a general WordPress state engine. It is a small WordPress-native context primitive for AI features and tools.

AST alignment is secondary and future-facing. The current package is AST-inspired, not a full AST implementation.
