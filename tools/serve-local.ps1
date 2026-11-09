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
$phpOptions = @('-d', 'upload_max_filesize=5M', '-d', 'post_max_size=20M')
& $Php '-r' 'exit(extension_loaded("gd") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) {
    # Breakdown evidence is re-encoded on the server; keep GD local to this process.
    $phpOptions += @('-d', 'extension=gd')
}
Push-Location -LiteralPath $publicPath
try {
    & $Php @phpOptions '-S' "127.0.0.1:$Port" $routerPath
    $serverExitCode = $LASTEXITCODE
}
finally {
    Pop-Location
}
exit $serverExitCode
