# Agent Usage

WP Context Abilities gives agents a small read-only WordPress context surface. The intended access pattern is Application Password authentication against REST and WordPress Abilities API endpoints.

## Recommended Auth

Use a dedicated WordPress user with the minimum role needed to read/edit the target posts. Use an Application Password for automation.

PowerShell example:

```powershell
$base = 'https://example.com'
$user = 'agent-user'
$pass = $env:WPCA_APP_PASSWORD -replace ' ', ''
$auth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("$user`:$pass"))
$headers = @{ Authorization = $auth }
Invoke-WebRequest -UseBasicParsing -Headers $headers -Uri "$base/wp-json/wp-context/v1/catalog?per_page=5"
```

Do not commit Application Passwords. Rotate them after tests if they were pasted into chat or logs.

## REST Calls

List context:

```text
GET /wp-json/wp-context/v1/catalog?per_page=5
```

Fetch Machine Representation:

```text
GET /wp-json/wp-context/v1/posts/123
```

Fetch Markdown:

```text
GET /wp-json/wp-context/v1/posts/123/md
```

Revalidate with ETag:

```text
GET /wp-json/wp-context/v1/posts/123
If-None-Match: "sha256-..."
```

`304 Not Modified` means the cached representation is still current.

## Cursor / Incremental Sync

The `cursor` value is a high-water mark for the newest `modified` timestamp returned in a response. It is not a next-page cursor.

Safe sync pattern:

1. Start from a fixed `since` timestamp.
2. Fetch all pages for that same `since` value.
3. After the full result window is drained, store the highest returned `cursor` for the next sync.

Unsafe pattern:

```text
GET catalog?since=T&page=1
store response.cursor immediately
GET catalog?cursor=response.cursor
```

That can skip older changed items that were on later pages of the original `since=T` window.

## Abilities Calls

Abilities use `input[...]` parameters:

```text
GET /wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?input[post_id]=123
GET /wp-json/wp-abilities/v1/abilities/wp-context/get-post-markdown/run?input[post_id]=123
GET /wp-json/wp-abilities/v1/abilities/wp-context/list-content-context/run?input[per_page]=10
GET /wp-json/wp-abilities/v1/abilities/wp-context/get-changed-content/run?input[since]=2026-01-01T00:00:00Z&input[per_page]=10
```

Direct parameters such as `?post_id=123` are not the documented ability call shape.

## What Agents Should Avoid

Do not use or invent these routes:

- `/wp-json/dual-native/v1/*`
- `/wp-json/wp-context/v1/posts/{id}/snapshot`
- `/wp-json/wp-context/v1/public/posts/{id}`
- `/wp-json/wp-context/v1/posts/{id}/ai/suggest`
- `/wp-json/wp-context/v1/posts/{id}/blocks`

They are not part of this plugin's v0 contract.

## Validation Command

```powershell
$env:WPCA_APP_PASSWORD = 'application-password'
.\scripts\validate-live.ps1 -BaseUrl 'https://example.com' -User 'agent-user'
```

The validator checks REST routes, removed-route 404s, ETag/Content-Digest behavior, Abilities discovery, and ability execution.
