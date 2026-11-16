# Run or build the app against the live WebZefanya server (https://zefanya.shop/api).
#
#   .\tool\live.ps1                 # check the server, then flutter run (picks a device)
#   .\tool\live.ps1 -Device <id>    # run on a specific device (see `flutter devices`)
#   .\tool\live.ps1 -Apk            # build a release APK pointed at the live server
#   .\tool\live.ps1 -Check          # only check that the live server answers
#
# All settings come from env.webzefanya.production.json. Writes stay off there
# (allowWrites=false); Courier activation, sign-in, refresh and sign-out are
# always allowed.
param(
    [string]$Device = '',
    [switch]$Apk,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$defines = Join-Path $root 'env.webzefanya.production.json'
$base = (Get-Content $defines -Raw | ConvertFrom-Json).'zefanya.webzefanya.apiBaseUrl'

function Get-Status([string]$url) {
    try {
        return [int](Invoke-WebRequest -Uri $url -Headers @{ Accept = 'application/json' } -UseBasicParsing -TimeoutSec 15).StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        return 0
    }
}

Write-Host "Live server: $base"
$catalog = Get-Status "$base/buyer/products"
$courier = Get-Status "$base/courier/profile"
Write-Host "  catalog (public)           -> $catalog (want 200)"
Write-Host "  courier profile (no token) -> $courier (want 401; 404 means Courier is turned off)"

if ($catalog -ne 200) {
    Write-Host 'The live server is not answering. Start the WebZefanya server (and its tunnel) first.' -ForegroundColor Red
    exit 1
}
if ($courier -eq 404) {
    Write-Host 'Courier is disabled on the server (COURIER_ENABLED). Courier sign-in will not work.' -ForegroundColor Yellow
}
if ($Check) { exit 0 }

Push-Location $root
try {
    if ($Apk) {
        flutter build apk --release "--dart-define-from-file=$defines"
        Write-Host "APK: $root\build\app\outputs\flutter-apk\app-release.apk"
    } elseif ($Device) {
        flutter run -d $Device "--dart-define-from-file=$defines"
    } else {
        flutter run "--dart-define-from-file=$defines"
    }
} finally {
    Pop-Location
}
