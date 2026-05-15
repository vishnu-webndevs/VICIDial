# Performance Smoke (Part 3)

## Prerequisites
- Running API server
- Valid bearer token and tenant id
- `k6` installed locally

## Environment Variables
- `BASE_URL` (default `http://127.0.0.1:8000`)
- `API_TOKEN` (required)
- `TENANT_ID` (required)

## Run
```bash
k6 run tests/performance/part3-k6-smoke.js
k6 run tests/performance/part3-k6-autoscale.js
```

## Coverage
- Calls list endpoint
- Shared inbox thread list endpoint
- Unified reporting endpoint
- Governance drill endpoint
- Autoscale stress profile against high-traffic API paths
