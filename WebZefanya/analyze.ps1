$lines = Get-Content clean.txt
$names=@()
for ($i=0; $i -lt $lines.Count; $i++) {
  if ($lines[$i] -cmatch 'FAILED') {
    $name = ($lines[$i] -replace '^\s*FAILED\s+','').Trim()
    $exc = ''
    if ($name -match '(QueryException|ValueError|ModelNotFoundException|AssertionFailedError|TypeError|RuntimeException)\s*$') { $exc = $matches[1] }
    $name = ($name -replace '\s+(QueryException|ValueError|ModelNotFoundException|AssertionFailedError|TypeError|RuntimeException)$','').Trim()
    $msg = ''
    for ($j=$i+1; $j -lt $lines.Count; $j++) {
      $t = $lines[$j].Trim()
      if ($t -eq '') { break }
      if ($t -match '^at ') { break }
      $msg = $t; break
    }
    $names += [pscustomobject]@{ Name=$name; Exc=$exc; Msg=$msg }
  }
}
Write-Output "TOTAL FAILED: $($names.Count)"
Write-Output '=== BY EXCEPTION ==='
$names | Group-Object Exc | Sort-Object Count -Descending | ForEach-Object { Write-Output ('{0,3}  {1}' -f $_.Count, $_.Name) }
Write-Output '=== BY MESSAGE ==='
$names | Group-Object Msg | Sort-Object Count -Descending | ForEach-Object { Write-Output ('{0,3}  {1}' -f $_.Count, $_.Name) }
Write-Output '=== ALL NAMES ==='
$names | ForEach-Object { Write-Output $_.Name }
