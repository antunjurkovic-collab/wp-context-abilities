# WP Context Abilities

## Description

Read-only structured WordPress context for AI workflows. v0 exposes post/page MR, Markdown, CID/ETag, catalog, and Abilities API surfaces.

## Installation

1. Upload the `wp-context-abilities` folder to `/wp-content/plugins/`.
2. Activate `WP Context Abilities` through the WordPress Plugins screen.

## Frequently Asked Questions

### Does this mutate WordPress content?

No. The v0 package exposes read-only context routes and abilities only.

### Does this require the Abilities API?

The REST routes work without it. The ability registrations are active only when WordPress provides the Abilities API functions.

### How are abilities executed?

Use the WordPress Abilities API input wrapper, for example:

`/wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?input[post_id]=123`

Direct parameters such as `?post_id=123` are not the documented call shape for ability execution.

### What was validated live?

The historical 0.1.0 smoke run checked route availability, ETag/Content-Digest
header presence, one `If-None-Match` 304 case, and four ability executions. It did
not prove digest correctness, content fidelity, complete discovery, or improved
agent outcomes. The 0.1.1 corrections have not been deployed or tested on hosts.

## Changelog

### 0.1.1

- Separate application CIDs from HTTP response ETags.
- Hash and emit one response serialization, including pretty JSON and final data filters.
- Parse complete If-None-Match entity-tags without splitting quoted commas or treating embedded asterisks as wildcards.
- Preserve modern List/List Item content and nested list indentation in Markdown.
- Retain inline image alt text, captions, and paragraph/heading/quote link targets in JSON and Markdown.
- Add `next_page` so empty permission-filtered pages do not terminate discovery.
- Limit discovery to best-effort post-timestamp filtering and clarify credential authority.
- Add focused local regressions using WordPress REST and block-parser classes.

### 0.1.0

- Initial clean WP Context Abilities package.
- Adds post/page MR, Markdown, catalog, changed-content discovery, ETag/CID, Content-Digest, and read-only Abilities API registration.

