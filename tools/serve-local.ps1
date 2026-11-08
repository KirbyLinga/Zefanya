param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8000,
    [string]$Php = 'php'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$publicPath = Join-Path $projectRoot 'web/public'
$routerPath = Join-Path $projectRoot 'web/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
if (-not (Test-Path -LiteralPath $routerPath -PathType Leaf)) {
    throw 'Install the backend dependencies before starting the local server.'
}

# Process-only limits: three 5 MB documents plus multipart form overhead.
Push-Location -LiteralPath $publicPath
try {
    & $Php '-d' 'upload_max_filesize=5M' '-d' 'post_max_size=20M' '-S' "127.0.0.1:$Port" $routerPath
    $serverExitCode = $LASTEXITCODE
}
finally {
    Pop-Location
}
exit $serverExitCode
