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

The 0.1.0 split was validated with the clean `/wp-json/wp-context/v1` route surface, no legacy `/dual-native/v1` routes, ETag/Content-Digest validators, `If-None-Match` 304 behavior, and four read-only `wp-context` abilities executing through `input[...]`.

## Changelog

### 0.1.0

- Initial clean WP Context Abilities package.
- Adds post/page MR, Markdown, catalog, changed-content discovery, ETag/CID, Content-Digest, and read-only Abilities API registration.

