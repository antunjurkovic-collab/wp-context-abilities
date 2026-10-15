# WordPress AI Context Abilities Proposal

Status: public draft / discussion artifact
Repository: `wp-context-abilities`
Reference implementation version: `0.1.1` (locally tested; not hosted validation)
Public discussion: https://github.com/WordPress/ai/discussions/835

## Summary

This experiment tests whether a shared, authenticated WordPress content projection
reduces duplicated consumer-side normalization. Existing WordPress REST content
APIs and `core/content-query` are valid comparison baselines, not missing facilities.

This proposal defines a narrow pattern:

> Structured Content Context Abilities: authenticated REST endpoints plus optional WordPress Abilities API registrations for read-only post/page context.

The goal is not to create an agent runtime. The goal is to give agents a current, permissioned, WordPress-native context surface they can use safely.

## Motivation

Agents often need to answer questions such as:

- What posts/pages can this authenticated user work with?
- What is the current structured representation of a post?
- What Markdown view should an AI tool use for review or editing assistance?
- Has this content changed since the agent last fetched it?
- Can the agent revalidate cached context with an `ETag` instead of refetching everything?

The proposed benefit is consistent projection and conditional retrieval. A
small editorial pilot found equal factual accuracy; a broader quality benefit
over existing APIs remains unestablished.

A local synthetic retrieval comparison on 2026-10-15 found that Core REST with
`_fields` and `_embed` supplied all 13 expected editorial facts in one request.
The initial context projection lost three details; correcting image alt text,
caption, and paragraph link handling restored all 13. Core returned 5,701 body
bytes and context JSON returned 2,003, each in one request. Conditional context
requests returned 304 with no body. Consumer normalization produced identical
556-byte fact objects from either source. This establishes retrieval behavior
for that fixture.

A subsequent pilot gave the same editorial instructions to three fresh
`gpt-6.1-sol` runs using Core REST JSON, context JSON, and normalized context.
With one completion per input, each recovered all 13 facts correctly; all nine quoted passages were present
in its supplied context. Observations were comparable and recommended clearer
checklist completion criteria. This one-fixture pilot does not establish a
general quality advantage for an additional ability surface.

## Design Principles

1. REST first
   - Use normal WordPress REST API authentication and permissions.
   - Prefer Application Passwords for external tools and automations.

2. Read-only by default
   - Context retrieval must not mutate WordPress content.
   - No write, suggestion-apply, snapshot-write, or block-update route is included in v0.
   - This does not make the supplied Application Password read-only across WordPress; other endpoints retain the user's applicable authority.

3. Abilities API aligned
   - When the WordPress Abilities API is available, register discoverable read-only abilities.
   - Abilities describe the context operations, but REST remains the transport.

4. Validator-aware
   - Compute ordinary GET response ETags from the emitted bytes, independently of the application CID.
   - Support `If-None-Match` and `304 Not Modified`.
   - Hash and emit one serialization for `Content-Digest`; HEAD/304 omit it and JSONP/envelope wrappers receive no plugin-generated validators.

5. Permissioned
   - Use WordPress capabilities such as `edit_post` and `edit_posts`.
   - Do not expose unauthenticated public context routes in v0.

6. Small enough to evaluate
   - The surface should be compact and testable.
   - Distinguish route/ability smoke checks from byte-integrity, fidelity, and agent-effectiveness tests.

## Proposed v0 Surface

REST namespace:

```text
/wp-json/wp-context/v1
```

Routes:

```text
GET /wp-json/wp-context/v1/catalog
GET /wp-json/wp-context/v1/posts/{id}
GET /wp-json/wp-context/v1/posts/{id}/md
```

Abilities category:

```text
wp-context
```

Abilities:

```text
wp-context/get-post-context
wp-context/get-post-markdown
wp-context/list-content-context
wp-context/get-changed-content
```

Ability execution uses the WordPress Abilities API input wrapper:

```text
/wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?input[post_id]=123
```

## Machine Representation

The post/page Machine Representation should include enough context for AI tools to reason about content without requiring HTML scraping:

- stable resource identifier
- title
- status
- modified/published dates
- author metadata
- featured image metadata when available
- categories and tags
- content blocks
- text projection
- word count
- representation links
- application CID, separate from the HTTP representation validator

The exact schema can evolve, but it should remain deterministic and permissioned.

## Markdown Projection

A Markdown endpoint gives AI tools a simpler representation for review, summarization, editing suggestions, and diff workflows.

Markdown output should have its own validator because its bytes may differ from the structured machine representation.

## Changed Content Discovery

The changed-content ability should support basic incremental discovery:

```text
since=<ISO timestamp>
per_page=<n>
```

This filters post-modification timestamps; it is not a complete context change feed.

### Cursor Semantics

The reference implementation treats `cursor` as a timestamp high-water mark, not
as a next-page cursor. Clients follow `next_page` until null while keeping `since`
and other filters fixed, including across empty permission-filtered pages. The
highest returned timestamp is only a subsequent post-modification filter.

Offset pagination can shift under concurrent changes. Deletions, permission
changes, timestamp ties, and related author/taxonomy/image metadata updates are
not a complete change log. No zero-miss synchronization or embedding freshness
guarantee is claimed.

This should be reviewed if WordPress AI tooling standardizes a context discovery surface, because next-page pagination and high-water incremental sync are different contracts.

## Non-Goals

This proposal is not:

- an MCP-first proposal
- an agent runtime
- a write/update API
- a replacement for WordPress REST API
- a replacement for WordPress Abilities API
- a broad plugin compatibility layer
- a full AST implementation
- a general WordPress state engine
- a public unauthenticated content API

It is intentionally narrow: authenticated read-only content context for WordPress AI workflows.

## Reference Implementation

The `wp-context-abilities` plugin is a narrow reference implementation of this proposal.

Current scope:

- authenticated post/page Machine Representation
- Markdown projection
- catalog and changed-content discovery
- stable in-memory validators
- `ETag`, `If-None-Match`, and `Content-Digest`
- optional WordPress Abilities API registration
- repeatable live validator script

The plugin intentionally excludes older experimental surfaces such as snapshot routes, public read routes, block write routes, AI suggestion routes, and custom MCP runtime code.

## Live Validation

The original 0.1.0 reference implementation had a hosted smoke run.

Validation coverage:

- clean `/wp-json/wp-context/v1` REST route surface
- no legacy `/wp-json/dual-native/v1` route surface
- removed snapshot/public-read/AI-suggest/block-write routes return `404`
- catalog endpoint returns authenticated content index
- post Machine Representation returns `ETag` and `Content-Digest`
- Markdown endpoint returns `ETag` and `Content-Digest`
- `If-None-Match` returns `304 Not Modified`
- `wp-context` Abilities category is discoverable
- all four read-only abilities execute with `input[...]`

Historical smoke result, retained without extending its scope:

```text
checks: 27
failed: 0
ok: true
```

Those checks established header presence and route/ability behavior, not digest
correctness, projection fidelity, complete discovery, or better AI outcomes.
The local 0.1.1 corrections separate HTTP validators from CIDs, retain modern list
items and image/link details in Markdown, expose explicit pagination continuation, and serialize/hash
ordinary response bytes once, including pretty-printing and final data filters.
Later output-buffer, compression, or intermediary transformations are outside
that byte-identity boundary and must update/remove affected headers themselves.
There is no new hosted validation. The local one-fixture model pilot found equal
factual accuracy, with no independent human quality rating or broader model
benchmark.

## Evaluation Boundary

The reference implementation demonstrates reusable normalization and conditional
retrieval on one synthetic editorial fixture. Core REST supplied all expected
facts in one request, so no request-count advantage over that baseline was
shown. Better AI review quality and reduced real-world integration effort
remain unestablished. The WordPress AI `core/content-query` ability was not part
of this comparison.

Link-target preservation currently covers paragraphs, headings, and quotes, not
list items. Timestamp-filtered discovery is not a complete change feed. These
results do not establish inclusion of the proposed ability list in WordPress AI.

The [public results summary](../evidence/public-0.1.1/summary.json) distinguishes
the current parser regressions from the historical Docker and model runs.
