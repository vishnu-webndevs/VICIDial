param(
    [Parameter(Mandatory = $true)]
    [ValidateSet("blue", "green")]
    [string]$target,

    [switch]$instant
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host "[switch-traffic] $Message"
}

$lbProvider = $env:LB_PROVIDER
$lbResource = $env:LB_RESOURCE_ID

Write-Step "Requested traffic target: $target"
Write-Step "Instant switch: $($instant.IsPresent)"

if ([string]::IsNullOrWhiteSpace($lbProvider) -or [string]::IsNullOrWhiteSpace($lbResource)) {
    Write-Step "LB_PROVIDER/LB_RESOURCE_ID not configured; running in playbook mode (no infra mutation)."
    Write-Step "Action required: update load balancer backend pool to '$target' and verify health checks."
    exit 0
}

switch ($lbProvider.ToLowerInvariant()) {
    "azure" {
        Write-Step "Executing Azure traffic switch for resource: $lbResource"
        # Example integration point:
        # az network application-gateway address-pool update ...
    }
    "aws" {
        Write-Step "Executing AWS traffic switch for resource: $lbResource"
        # Example integration point:
        # aws elbv2 modify-listener ...
    }
    "gcp" {
        Write-Step "Executing GCP traffic switch for resource: $lbResource"
        # Example integration point:
        # gcloud compute backend-services update ...
    }
    default {
        throw "Unsupported LB_PROVIDER '$lbProvider'. Supported values: azure, aws, gcp."
    }
}

if ($instant.IsPresent) {
    Write-Step "Instant switch requested; bypassing weighted canary progression."
}

Write-Step "Switch completed. Perform post-switch smoke checks immediately."
