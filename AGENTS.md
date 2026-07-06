# AGENTS.md - WP Context Abilities

Purpose: agent-readable operating guide for WP Context Abilities.

This plugin exposes read-only structured WordPress content context through authenticated REST and WordPress Abilities API surfaces. Prefer Application Password + REST API access. Do not assume MCP is required.

## Current Surface

Base REST namespace:

- `/wp-json/wp-context/v1`

Supported routes:

- `GET /wp-json/wp-context/v1/catalog`
- `GET /wp-json/wp-context/v1/posts/{id}`
- `GET /wp-json/wp-context/v1/posts/{id}/md`

Supported Abilities category:

- `wp-context`

Supported abilities:

- `wp-context/get-post-context`
- `wp-context/get-post-markdown`
- `wp-context/list-content-context`
- `wp-context/get-changed-content`

Ability execution uses the WordPress Abilities API input wrapper:

- `/wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?input[post_id]=123`
- `/wp-json/wp-abilities/v1/abilities/wp-context/get-post-markdown/run?input[post_id]=123`
- `/wp-json/wp-abilities/v1/abilities/wp-context/list-content-context/run?input[per_page]=10`
- `/wp-json/wp-abilities/v1/abilities/wp-context/get-changed-content/run?input[since]=2026-01-01T00:00:00Z&input[per_page]=10`

Do not call abilities with direct parameters such as `?post_id=123`; use `input[...]`.

## Authentication

Use WordPress Application Password authentication or a valid REST nonce in an authenticated WordPress session.

For scripts, prefer Application Passwords:

```powershell
$pair = "user:application-password-without-spaces"
$auth = "Basic " + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes($pair))
Invoke-WebRequest -Headers @{ Authorization = $auth } -Uri "https://example.com/wp-json/wp-context/v1/catalog"
```

Keep secrets out of files. Use environment variables for app passwords.

## Read-Only Boundary

This plugin is read-only in v0.

Do not invent or call mutation routes. The plugin does not provide:

- write routes
- block update routes
- snapshot write routes
- AI suggestion write routes
- public unauthenticated context routes
- MCP server/runtime code
- agent action execution

Removed/unsupported routes should return `404`.

## Validators

Use validators correctly:

- MR and Markdown responses include strong `ETag` values.
- Responses may include `Content-Digest`.
- Use `If-None-Match` for revalidation.
- Treat `304 Not Modified` as success, not failure.

## Expected Agent Behavior

Agents should:

- discover content through `/catalog` or `list-content-context`
- fetch a specific post through `/posts/{id}` or `get-post-context`
- fetch Markdown through `/posts/{id}/md` or `get-post-markdown`
- respect authentication and permissions
- respect ETag/cache revalidation
- report missing permissions clearly
- avoid guessing hidden routes

Agents should not:

- use `/dual-native/v1` routes for this plugin
- use old snapshot/public-read/AI-suggest/block-write endpoints
- attempt content mutation through this plugin
- expose Application Passwords in logs or reports
- assume broad plugin, theme, media, or site-state support beyond v0 post/page context

## Validation

Run the live validator after install or update:

```powershell
$env:WPCA_APP_PASSWORD = 'application-password'
.\scripts\validate-live.ps1 -BaseUrl 'https://example.com' -User 'admin-user'
```

Expected result: all checks pass and JSON summary reports `ok: true`.

## Non-Claims

This is not:

- a general WordPress state engine
- an MCP replacement
- an agent runtime
- a mutation layer
- a full AST implementation
- a broad plugin compatibility layer

It is a narrow WordPress-native context surface for AI tools.
