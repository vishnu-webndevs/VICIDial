# Production Load Testing and Autoscaling Verification

Last updated: 2026-04-12

## Objective

- Validate API performance under production-like concurrency.
- Verify autoscaling policy reacts within expected windows.

## Automation

- Workflow: `.github/workflows/load-test-autoscale.yml`
- k6 profile: `apps/api/tests/performance/part3-k6-autoscale.js`
- Summary artifact: `k6-autoscale-summary.json`

## Prerequisites

- Staging environment with autoscaling enabled.
- Secret `LOAD_TEST_API_TOKEN` set in GitHub.
- Valid tenant ID for test scope.

## Verification Steps

1. Trigger workflow with staging API URL and tenant ID.
2. Observe:
   - p95 and p99 latency thresholds
   - request failure rate thresholds
3. Correlate with infrastructure metrics:
   - scale-out event timestamps
   - instance/pod count trend
   - CPU/memory utilization trend
4. Confirm scale-in behavior after traffic ramp down.

## Evidence Checklist

- k6 summary artifact from workflow run.
- Cloud autoscaling event logs.
- Metric dashboard export showing scale-out and recovery.
- Signed result stating pass/fail vs SLO targets.
