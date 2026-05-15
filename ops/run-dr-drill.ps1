param(
    [Parameter(Mandatory = $true)]
    [string]$environment,
    [Parameter(Mandatory = $true)]
    [string]$backupIdentifier,
    [Parameter(Mandatory = $true)]
    [string]$restoredEndpoint
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "[dr-drill] $Message"
}

$startedAt = Get-Date
Write-Step "Starting DR drill for environment: $environment"
Write-Step "Backup identifier: $backupIdentifier"
Write-Step "Restored endpoint: $restoredEndpoint"

Write-Step "Step 1/4: Verify restored service liveness"
$up = Invoke-WebRequest -Uri "$restoredEndpoint/up" -Method GET -UseBasicParsing -TimeoutSec 20
if ($up.StatusCode -ne 200) {
    throw "Liveness check failed at /up"
}

Write-Step "Step 2/4: Verify API readiness"
$ready = Invoke-WebRequest -Uri "$restoredEndpoint/api/health/ready" -Method GET -UseBasicParsing -TimeoutSec 20
if ($ready.StatusCode -ne 200) {
    throw "Readiness check failed at /api/health/ready"
}

Write-Step "Step 3/4: Capture timing metrics"
$endedAt = Get-Date
$rtoMinutes = [Math]::Round((New-TimeSpan -Start $startedAt -End $endedAt).TotalMinutes, 2)

Write-Step "Step 4/4: Persist evidence stub"
$evidenceDir = "c:\xampp\htdocs\vicidial\docs\operations\evidence"
if (-not (Test-Path $evidenceDir)) {
    New-Item -ItemType Directory -Path $evidenceDir | Out-Null
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$file = Join-Path $evidenceDir "dr-drill-$timestamp.md"
$content = @"
# DR Drill Evidence ($timestamp)

- Environment: $environment
- Backup Identifier: $backupIdentifier
- Restored Endpoint: $restoredEndpoint
- Started At (UTC): $($startedAt.ToUniversalTime().ToString("o"))
- Ended At (UTC): $($endedAt.ToUniversalTime().ToString("o"))
- Measured RTO (minutes): $rtoMinutes

## Validation

- [x] /up returned 200
- [x] /api/health/ready returned 200
- [ ] Data integrity validation attached
- [ ] Cross-region restore confirmation attached
- [ ] Sign-off by SRE + Security attached
"@

Set-Content -Path $file -Value $content
Write-Step "Evidence file created: $file"
