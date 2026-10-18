# Rebuilds the Claude handoff bundles from the live source tree.
#
#   powershell -ExecutionPolicy Bypass -File handoff\rebuild-bundles.ps1
#
# Re-run after changing any backend file, so the bundle Claude reads is the code
# you actually have. Writes 01-api-surface.md, 02-api-tests.md and
# 00-file-manifest.md next to this script.
#
# Lists are folders/globs where the whole folder belongs in the bundle, and
# explicit paths where a file must be left out on purpose: app\Models\Seller\
# Product.php and ProductImage.php are a duplicate class and an empty file.
# See README.md.
$ErrorActionPreference = 'Stop'

$here  = $PSScriptRoot
$web   = Join-Path (Split-Path -Parent $here) 'web'
$fence = ([string][char]96) * 3

function New-Builder { $script:SB = New-Object System.Text.StringBuilder }
function Add-Line([string]$t) { [void]$script:SB.AppendLine($t) }

function Resolve-Entry([string]$entry) {
    $full = Join-Path $web $entry
    if (-not (Test-Path -LiteralPath $full)) {
        return @($entry)   # fall through, so Add-Group reports it NOT FOUND
    }
    $item = Get-Item -LiteralPath $full
    if (-not $item.PSIsContainer) {
        return @($entry)   # a plain file: keep the path exactly as written
    }
    # Directory: take every file below it. Never -Recurse from a *file* path -
    # `Get-ChildItem <file> -Recurse` walks the whole tree beside it (it pulled
    # in 152 vendor\*\composer.json before this branch existed), and vendor must
    # never reach the bundle.
    return @(Get-ChildItem -LiteralPath $item.FullName -Recurse -File |
             Where-Object { $_.FullName -notmatch '\\(vendor|node_modules)\\' } |
             Sort-Object FullName |
             ForEach-Object { $_.FullName.Substring($web.Length + 1) })
}

function Add-Group([string]$title, [string[]]$entries) {
    Add-Line ('## ' + $title); Add-Line ''
    foreach ($entry in $entries) {
        foreach ($f in (Resolve-Entry $entry)) {
            $full = Join-Path $web $f
            if (Test-Path $full) {
                $raw  = Get-Content $full -Raw -Encoding UTF8
                $n    = @(Get-Content $full -Encoding UTF8).Count
                $body = if ($raw) { $raw.TrimEnd() } else { '(empty file)' }
                Add-Line ('### web/' + $f + '  (' + $n + ' lines)'); Add-Line ''
                Add-Line ($fence + 'php'); Add-Line $body; Add-Line $fence; Add-Line ''
            } else {
                # Deliberately loud: an absent file is a fact about this repo.
                # config/cors.php and config/sanctum.php are absent by design on
                # Laravel 11+, and saying so stops edits to files that do not exist.
                Add-Line ('### NOT FOUND: web/' + $f); Add-Line ''
            }
        }
    }
}

function Save-Bundle([string]$name) {
    [System.IO.File]::WriteAllText((Join-Path $here $name), $script:SB.ToString(),
        (New-Object System.Text.UTF8Encoding($false)))   # UTF-8, no BOM
    Write-Host ('wrote ' + $name)
}

# ---------------------------------------------------------------- bundle 1
$groups1 = [ordered]@{
    'Wiring' = @('bootstrap\app.php', 'config\filesystems.php', 'config\cors.php',
                 'config\sanctum.php', 'routes\api.php', '.env.example', 'composer.json')
    'API controllers - app/Http/Controllers/Api' = @('app\Http\Controllers\Api')
    'Shared services - both doors call these'    = @('app\Services')
    'The other door - the web checkout, for comparison' = @(
        'app\Http\Controllers\Buyer\CheckoutController.php',
        'app\Http\Controllers\Buyer\CartController.php')
    'API resources - the wire shape' = @('app\Http\Resources')
    'Form requests the API validates with' = @(
        'app\Http\Requests\Buyer\StoreBuyerRegistrationRequest.php',
        'app\Http\Requests\Logistics\StoreLogisticsRegistrationRequest.php')
    'Models the API touches' = @(
        'app\Models\Product.php', 'app\Models\ProductImage.php', 'app\Models\CartItem.php',
        'app\Models\Order.php', 'app\Models\OrderItem.php', 'app\Models\SellerOrder.php',
        'app\Models\Payment.php', 'app\Models\InventoryTransaction.php',
        'app\Models\Voucher.php', 'app\Models\Buyer\Buyer.php', 'app\Models\Seller\Seller.php',
        'app\Models\Shared\Category.php', 'app\Models\Shared\User.php',
        'app\Models\Logistics\LogisticsProvider.php')
    'Enums'      = @('app\Enums')
    'Migrations' = @('database\migrations')
    'Seeders - the demo data the app is developed against' = @('database\seeders')
}

New-Builder
Add-Line '# Zefanya Laravel backend - API surface'
Add-Line ''
Add-Line 'The server behind the Flutter app. Two doors into one database: the API (`/api/*`) and the web app.'
Add-Line ''
foreach ($k in $groups1.Keys) { Add-Group $k $groups1[$k] }
Save-Bundle '01-api-surface.md'

# ---------------------------------------------------------------- bundle 2
$groups2 = [ordered]@{
    'Service tests - the placing rules, with no controller in the path' = @('tests\Feature\Services')
    'API tests, including the seeder tests'                            = @('tests\Feature\Api')
}

New-Builder
Add-Line '# Zefanya Laravel backend - the tests that prove the API'
Add-Line ''
Add-Line 'Run: cd web; php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/Api tests/Feature/Services'
Add-Line ''
foreach ($k in $groups2.Keys) { Add-Group $k $groups2[$k] }
Save-Bundle '02-api-tests.md'

# ---------------------------------------------------------------- manifest
$sb = New-Object System.Text.StringBuilder
[void]$sb.AppendLine('# File manifest - every backend file in these bundles')
[void]$sb.AppendLine()
foreach ($b in @('01-api-surface.md', '02-api-tests.md')) {
    [void]$sb.AppendLine('## ' + $b); [void]$sb.AppendLine()
    foreach ($l in (Get-Content (Join-Path $here $b) -Encoding UTF8)) {
        if ($l -like '## *') {
            [void]$sb.AppendLine(); [void]$sb.AppendLine('### ' + $l.Substring(3).Trim()); [void]$sb.AppendLine()
        } elseif ($l -like '### web/*') {
            [void]$sb.AppendLine('- web/' + $l.Substring(8).Trim())
        }
    }
    [void]$sb.AppendLine()
}
[System.IO.File]::WriteAllText((Join-Path $here '00-file-manifest.md'), $sb.ToString(),
    (New-Object System.Text.UTF8Encoding($false)))
Write-Host 'wrote 00-file-manifest.md'