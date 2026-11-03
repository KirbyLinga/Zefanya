# scripts/check-secrets.ps1
# Run from anywhere; pass -RepoRoot to override.
# Exits 1 if tracked secret files found; 0 if clean.
param([string]$RepoRoot = (Split-Path $PSScriptRoot))
$patterns = @('*.env','*.key','*.pem','*google-services.json*','GoogleSignIn.xcconfig','*.sql','backups/*')
$tracked = git -C $RepoRoot ls-files -- $patterns 2>&1 |
    Where-Object { $_ -notmatch '\.xcconfig\.example' -and $_.Trim() -ne '' }
if ($tracked) { Write-Error "Secrets in git:`n  $($tracked -join "`n  ")"; exit 1 }
Write-Host 'OK: no secret files tracked.'; exit 0
