# WP Context Abilities

WP Context Abilities exposes read-only structured WordPress context for AI workflows through REST and, when available, the WordPress Abilities API.

The v0 package is intentionally narrow: it provides post/page content context only. The project name is broader so future versions can add other context providers such as media, taxonomies, navigation, templates, and plugin-defined public context without renaming the plugin.

## v0 Scope

- authenticated post/page Machine Representation (MR)
- Markdown projection
- stable in-memory CID/ETag validators
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

Response headers:

- `ETag: "<cid>"`
- `Last-Modified`
- `Cache-Control: max-age=0, must-revalidate`
- `Content-Digest` over final response bytes

Supports `If-None-Match` for `304 Not Modified`.

### GET `/wp-json/wp-context/v1/posts/{id}/md`

Returns Markdown projection for the same content context.

### GET `/wp-json/wp-context/v1/catalog`

Returns an authenticated index for discovery and incremental sync.

Query parameters:

- `since=ISO` or `cursor=ISO`
- `status=draft|publish|any`
- `types=post,page`
- `per_page=1..100`
- `page=1..n`

### Cursor Semantics

`cursor` is a high-water mark for the newest `modified` timestamp returned in the current response. It is not a next-page cursor.

Correct incremental sync pattern:

1. Choose a fixed `since` timestamp for the sync window.
2. Page through all results with that same `since` using `page=1..n` until no more items are returned.
3. Store the highest returned `cursor` as the next sync high-water mark only after the full window has been drained.

Do not request page 1, immediately store its `cursor`, and then use that cursor for the next request while older pages from the same window still exist; that can skip older changed items.

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

Validated on `llmpages.org` after the 0.1.0 split:

- clean REST route surface exposed under `/wp-json/wp-context/v1`
- legacy `/wp-json/dual-native/v1` route surface absent
- removed snapshot/public-read/AI-suggest/block-write routes return `404`
- MR and Markdown endpoints return strong `ETag` and `Content-Digest`
- `If-None-Match` returns `304 Not Modified`
- `wp-context` Abilities category is discoverable
- all four read-only abilities execute successfully through `input[...]`

## Determinism And Integrity

- CID is `sha256-<hex>` over canonical MR with sorted keys.
- Read-only REST and Ability calls compute CIDs in memory and do not persist post meta.
- Links are excluded from CID by default to avoid site-local URL churn affecting validators.
- Taxonomies are sorted by ID before hashing.
- Markdown ETags are computed over the final Markdown bytes after filters.
- 200 responses include `Content-Digest: sha-256=:<base64>:` where WordPress can emit final response bytes.

To exclude additional volatile fields from CID:

```php
add_filter('wpca_cid_exclude_keys', function(array $keys) {
    return array_merge($keys, array('modified', 'published', 'status'));
});
```




## Proposal

See `docs/WORDPRESS-AI-CONTEXT-ABILITIES-PROPOSAL.md` for the focused WordPress AI proposal: REST-first, Application-Password-compatible, read-only structured content context plus optional Abilities API registration.

## Agent-Readable Guidance

This repo includes `AGENTS.md` and `docs/AGENT-USAGE.md` so agents can discover the current REST-first, Application-Password-compatible surface without guessing deprecated routes or mutation endpoints.

## Live Validation Script

Run the live validation script against a WordPress install with the plugin active:

```powershell
$env:WPCA_APP_PASSWORD = 'application-password-without-spaces-or-with-spaces'
.\scripts\validate-live.ps1 -BaseUrl 'https://example.com' -User 'admin-user'
```

The script verifies REST routes, removed route 404s, validators, Abilities discovery, and ability execution through `input[...]`.

## Install

1. Copy `wp-context-abilities/` into `wp-content/plugins/`.
2. Activate `WP Context Abilities` in WP Admin.
3. Use application-password or REST nonce authentication for the default endpoints.

## Security And Permissions

- MR, Markdown, and catalog require authenticated WordPress users.
- Post context endpoints require `edit_post` for the requested post.
- Catalog requires `edit_posts` and filters each item by `edit_post`.
- There are no mutation endpoints in this package.

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
