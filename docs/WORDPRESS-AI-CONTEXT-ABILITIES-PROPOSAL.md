# WordPress AI Context Abilities Proposal

Status: public draft / discussion artifact
Repository: `wp-context-abilities`
Reference implementation version: `0.1.0`
Public discussion: https://github.com/WordPress/ai/discussions/835

## Summary

WordPress AI tools need a small, authenticated, read-only way to retrieve current WordPress content context without relying on broad scraping, stale model knowledge, custom MCP surfaces, or mutation-capable APIs.

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

Today, an agent may guess routes, scrape rendered HTML, rely on stale training data, or use broad integration surfaces that are not specific to WordPress permissions and validators.

A small REST-first context surface can reduce that ambiguity.

## Design Principles

1. REST first
   - Use normal WordPress REST API authentication and permissions.
   - Prefer Application Passwords for external tools and automations.

2. Read-only by default
   - Context retrieval must not mutate WordPress content.
   - No write, suggestion-apply, snapshot-write, or block-update route is included in v0.

3. Abilities API aligned
   - When the WordPress Abilities API is available, register discoverable read-only abilities.
   - Abilities describe the context operations, but REST remains the transport.

4. Validator-aware
   - Return strong `ETag` values for machine representations.
   - Support `If-None-Match` and `304 Not Modified`.
   - Include `Content-Digest` where possible.

5. Permissioned
   - Use WordPress capabilities such as `edit_post` and `edit_posts`.
   - Do not expose unauthenticated public context routes in v0.

6. Small enough to evaluate
   - The surface should be compact and testable.
   - A validator script should be able to prove route availability, removed-route absence, validators, and ability execution.

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
- content-addressed validator / CID-style hash

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

This lets tools avoid crawling the entire site repeatedly.

### Cursor Semantics

The reference implementation currently treats `cursor` as a high-water mark for changed-content discovery, not as a next-page cursor. Clients should page through a fixed `since` window completely before storing the highest returned cursor for the next incremental sync.

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

## Relationship To Skills And Agent Guidance

Agent skills can help when they are current, targeted, and evaluated. But a skill alone cannot provide live WordPress state.

This proposal separates two concerns:

- `AGENTS.md` and documentation tell an agent how to use the current surface.
- REST and Abilities endpoints provide the live permissioned context.

The reference implementation includes both:

- `AGENTS.md`
- `docs/AGENT-USAGE.md`
- `scripts/validate-live.ps1`

This keeps agent guidance small, current, and testable.

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

The reference implementation has been validated on a live WordPress install at `llmpages.org`.

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

Latest validator result at the time of this note:

```text
checks: 27
failed: 0
ok: true
```

## Discussion Prompts

These prompts are included for external reviewers. They are not instructions, commitments, or implementation requirements.

Primary discussion question:

> Should WordPress AI tooling define a small read-only Structured Content Context Ability pattern, based on authenticated REST and optional Abilities API registration, so agents can retrieve current post/page context without guessing routes or depending on stale model knowledge?

How to use this draft:

> Treat this reference implementation as a discussion artifact, not as a request for immediate core merge.

Review prompts:

- Is the route/ability surface too small, too broad, or about right for v0?
- Should post/page context be the first standardized context surface?
- Which fields belong in a stable Machine Representation?
- Should Markdown projection be part of the same surface or separate?
- What should the recommended permission model be for AI tools using Application Passwords?
- What eval would prove this improves agent behavior compared with generic docs or skills alone?
