param(
    [Parameter(Mandatory = $true)]
    [string]$baseUrl,
    [int]$burstRequests = 40
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "[edge-verify] $Message"
}

Write-Step "Target: $baseUrl"
Write-Step "Validating security header presence through edge..."

$response = Invoke-WebRequest -Uri $baseUrl -Method GET -UseBasicParsing
$requiredHeaders = @(
    "X-Frame-Options",
    "X-Content-Type-Options",
    "Referrer-Policy"
)

foreach ($header in $requiredHeaders) {
    if (-not $response.Headers[$header]) {
        throw "Missing expected edge/app header: $header"
    }
}

Write-Step "Headers validated."
Write-Step "Running burst test to verify rate limiting/protective controls..."

$tooManyRequests = 0
for ($i = 0; $i -lt $burstRequests; $i++) {
    try {
        $r = Invoke-WebRequest -Uri $baseUrl -Method GET -UseBasicParsing -TimeoutSec 10
        if ($r.StatusCode -eq 429) {
            $tooManyRequests++
        }
    } catch {
        $statusCode = $_.Exception.Response.StatusCode.value__
        if ($statusCode -eq 429) {
            $tooManyRequests++
        }
    }
}

Write-Step "Burst requests: $burstRequests"
Write-Step "HTTP 429 responses observed: $tooManyRequests"
Write-Step "Review with WAF/edge metrics for bot and DDoS event correlation."
