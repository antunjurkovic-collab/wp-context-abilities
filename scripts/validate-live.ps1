param(
    [Parameter(Mandatory=$true)]
    [string]$BaseUrl,

    [Parameter(Mandatory=$true)]
    [string]$User,

    [Parameter(Mandatory=$false)]
    [string]$AppPassword = $env:WPCA_APP_PASSWORD,

    [Parameter(Mandatory=$false)]
    [int]$PostId = 0
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($AppPassword)) {
    throw 'AppPassword is required. Pass -AppPassword or set WPCA_APP_PASSWORD.'
}

$BaseUrl = $BaseUrl.TrimEnd('/')
$AppPassword = ($AppPassword -replace ' ', '')
$auth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("$User`:$AppPassword"))
$headers = @{ Authorization = $auth }
$results = New-Object System.Collections.Generic.List[object]

function Invoke-WpcaRequest {
    param(
        [Parameter(Mandatory=$true)][string]$Method,
        [Parameter(Mandatory=$true)][string]$Path,
        [hashtable]$ExtraHeaders = $null
    )

    $h = @{} + $headers
    if ($ExtraHeaders) {
        foreach ($key in $ExtraHeaders.Keys) { $h[$key] = $ExtraHeaders[$key] }
    }

    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl + $Path) -Headers $h -Method $Method -TimeoutSec 30 -ErrorAction Stop
        return [pscustomobject]@{ Status = [int]$response.StatusCode; Content = $response.Content; Headers = $response.Headers }
    } catch {
        $resp = $_.Exception.Response
        if ($resp) {
            $reader = New-Object IO.StreamReader($resp.GetResponseStream())
            return [pscustomobject]@{ Status = [int]$resp.StatusCode; Content = $reader.ReadToEnd(); Headers = $resp.Headers }
        }
        throw
    }
}

function Add-Check {
    param([string]$Name, [bool]$Ok, [string]$Detail = '')
    $script:results.Add([pscustomobject]@{ name = $Name; ok = $Ok; detail = $Detail }) | Out-Null
}

$catalog = Invoke-WpcaRequest GET '/wp-json/wp-context/v1/catalog?per_page=5'
$catalogJson = $catalog.Content | ConvertFrom-Json
$items = @($catalogJson.items)
if ($PostId -le 0 -and $items.Count -gt 0) { $PostId = [int]$items[0].rid }
Add-Check 'catalog_200' ($catalog.Status -eq 200) "status=$($catalog.Status), count=$($items.Count)"
Add-Check 'catalog_digest_present' ([bool]$catalog.Headers['Content-Digest']) ''
Add-Check 'catalog_has_post' ($PostId -gt 0) "post_id=$PostId"

$post = Invoke-WpcaRequest GET "/wp-json/wp-context/v1/posts/$PostId"
$postJson = $null
if ($post.Content) { $postJson = $post.Content | ConvertFrom-Json }
$etag = [string]$post.Headers['ETag']
Add-Check 'post_mr_200' ($post.Status -eq 200) "status=$($post.Status)"
Add-Check 'post_mr_cid_present' ([bool]$postJson.cid) ''
Add-Check 'post_mr_etag_present' (-not [string]::IsNullOrWhiteSpace($etag)) $etag
Add-Check 'post_mr_digest_present' ([bool]$post.Headers['Content-Digest']) ''

$notModified = Invoke-WpcaRequest GET "/wp-json/wp-context/v1/posts/$PostId" @{ 'If-None-Match' = $etag }
Add-Check 'post_mr_if_none_match_304' ($notModified.Status -eq 304) "status=$($notModified.Status)"

$markdown = Invoke-WpcaRequest GET "/wp-json/wp-context/v1/posts/$PostId/md"
Add-Check 'post_markdown_200' ($markdown.Status -eq 200) "status=$($markdown.Status), bytes=$($markdown.Content.Length)"
Add-Check 'post_markdown_etag_present' ([bool]$markdown.Headers['ETag']) ([string]$markdown.Headers['ETag'])
Add-Check 'post_markdown_digest_present' ([bool]$markdown.Headers['Content-Digest']) ''

$removedRoutes = @(
    "/wp-json/wp-context/v1/posts/$PostId/snapshot",
    "/wp-json/wp-context/v1/public/posts/$PostId",
    "/wp-json/wp-context/v1/posts/$PostId/ai/suggest",
    "/wp-json/wp-context/v1/posts/$PostId/blocks"
)
foreach ($route in $removedRoutes) {
    $removed = Invoke-WpcaRequest GET $route
    Add-Check "removed_route_404:$route" ($removed.Status -eq 404) "status=$($removed.Status)"
}

$root = Invoke-WpcaRequest GET '/wp-json/'
$rootJson = $root.Content | ConvertFrom-Json
$routeNames = @($rootJson.routes.PSObject.Properties.Name)
$wpContextRoutes = @($routeNames | Where-Object { $_ -like '/wp-context/v1*' })
$dualNativeRoutes = @($routeNames | Where-Object { $_ -like '/dual-native/v1*' })
Add-Check 'wp_context_routes_present' ($wpContextRoutes.Count -ge 4) "count=$($wpContextRoutes.Count)"
Add-Check 'dual_native_routes_absent' ($dualNativeRoutes.Count -eq 0) "count=$($dualNativeRoutes.Count)"

$categories = Invoke-WpcaRequest GET '/wp-json/wp-abilities/v1/categories'
$categoriesJson = $categories.Content | ConvertFrom-Json
$wpContextCategory = @($categoriesJson | Where-Object { $_.slug -eq 'wp-context' })
Add-Check 'ability_category_present' ($categories.Status -eq 200 -and $wpContextCategory.Count -eq 1) "status=$($categories.Status)"

$abilities = Invoke-WpcaRequest GET '/wp-json/wp-abilities/v1/abilities?category=wp-context&per_page=100'
$abilitiesJson = @($abilities.Content | ConvertFrom-Json)
$abilityNames = @($abilitiesJson | ForEach-Object { $_.name })
$expectedAbilities = @(
    'wp-context/get-post-context',
    'wp-context/get-post-markdown',
    'wp-context/list-content-context',
    'wp-context/get-changed-content'
)
foreach ($ability in $expectedAbilities) {
    Add-Check "ability_registered:$ability" ($abilityNames -contains $ability) ($abilityNames -join ',')
}

$abilityRuns = @(
    "/wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?input%5Bpost_id%5D=$PostId",
    "/wp-json/wp-abilities/v1/abilities/wp-context/get-post-markdown/run?input%5Bpost_id%5D=$PostId",
    '/wp-json/wp-abilities/v1/abilities/wp-context/list-content-context/run?input%5Bper_page%5D=2',
    '/wp-json/wp-abilities/v1/abilities/wp-context/get-changed-content/run?input%5Bsince%5D=1970-01-01T00%3A00%3A00Z&input%5Bper_page%5D=2'
)
foreach ($run in $abilityRuns) {
    $runResult = Invoke-WpcaRequest GET $run
    Add-Check "ability_run_200:$run" ($runResult.Status -eq 200) "status=$($runResult.Status)"
}

$directParam = Invoke-WpcaRequest GET "/wp-json/wp-abilities/v1/abilities/wp-context/get-post-context/run?post_id=$PostId"
Add-Check 'ability_direct_param_rejected' ($directParam.Status -eq 400) "status=$($directParam.Status)"

$failed = @($results | Where-Object { -not $_.ok })
foreach ($result in $results) {
    $status = if ($result.ok) { 'PASS' } else { 'FAIL' }
    if ($result.detail) { "$status $($result.name) - $($result.detail)" } else { "$status $($result.name)" }
}

$summary = [pscustomobject]@{
    ok = ($failed.Count -eq 0)
    base_url = $BaseUrl
    post_id = $PostId
    checks = $results.Count
    failed = $failed.Count
    failed_names = @($failed | ForEach-Object { $_.name })
}

''
$summary | ConvertTo-Json -Depth 5

if ($failed.Count -gt 0) { exit 1 }
