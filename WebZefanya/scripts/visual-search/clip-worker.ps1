param([ValidateSet('Start', 'Stop', 'Status')][string]$Action = 'Status')
$ErrorActionPreference = 'Stop'
$clipRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$clipScript = Join-Path $clipRoot 'scripts/visual-search/clip_runtime.py'
$clipHome = Join-Path $clipRoot 'storage/app/private/visual-search/clip-runtime'
$clipPidFile = Join-Path $clipHome 'worker.pid'
$clipProcess = $null
if (Test-Path -LiteralPath $clipPidFile) {
    $clipPid = [int](Get-Content -LiteralPath $clipPidFile -Raw)
    $candidate = Get-CimInstance Win32_Process -Filter "ProcessId=$clipPid" -ErrorAction SilentlyContinue
    if ($candidate -and $candidate.CommandLine -and $candidate.CommandLine.Replace('\', '/').Contains($clipScript.Replace('\', '/'))) {
        $clipProcess = $candidate
    }
}
if ($Action -eq 'Stop') {
    if ($clipProcess) { Stop-Process -Id $clipProcess.ProcessId }
    Write-Output 'Recognition worker stopped. Laravel retains photo-comparison fallback.'
    return
}
if ($Action -eq 'Status') {
    if ($clipProcess) {
        Invoke-RestMethod -Uri 'http://127.0.0.1:8766/health' -TimeoutSec 2 | ConvertTo-Json -Depth 4
    } else { Write-Output 'Recognition worker is not running.' }
    return
}
if ($clipProcess) { Write-Output 'Recognition worker is already running.'; return }
if (Get-NetTCPConnection -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue) {
    throw 'Port 8766 is already in use; no existing process was changed.'
}
if (!(Test-Path -LiteralPath (Join-Path $clipHome 'model/onnx/vision_model_int8.onnx')) -or
    !(Test-Path -LiteralPath (Join-Path $clipHome '../clip-runtime.key'))) {
    throw 'The approved private model installation and runtime key are required.'
}
$clipPythonAlias = (Get-Command python.exe).Source
$clipPython = (& $clipPythonAlias -c 'import sys; print(sys.executable)').Trim()
$started = Start-Process -FilePath $clipPython -ArgumentList @($clipScript) -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $clipHome 'worker-out.txt') -RedirectStandardError (Join-Path $clipHome 'worker-err.txt')
$started.Id | Set-Content -LiteralPath $clipPidFile
Write-Output "Recognition worker started with PID $($started.Id). Check Status after startup."
