# Rollback Playbook - Blue/Green

Version: v2.0.0
Last-Updated: 2026-04-12

## One-Command Rollback
- Command:
  - `./ops/switch-traffic.ps1 -target blue -instant`

## Preconditions
- Blue environment remains deployment-ready and healthy.
- Database migrations for current release are backward-compatible or guarded by feature flags.
- Smoke checks for blue are available and executable.

## Procedure
1. Trigger instant switch command.
2. Validate health endpoints (`/up` and critical API route checks).
3. Run critical smoke tests for auth, dialer, and campaign status endpoints.
4. Freeze writes for newly introduced optional features if data divergence risk exists.
5. Record incident and rollback metadata in change/risk logs.

## Verification Checklist
- API error rate returns to pre-release baseline.
- p95 latency returns within budget (`<= 200ms` for priority endpoints).
- Page load for critical screens returns within budget (`<= 2s`).
- No tenant-isolation violations reported.

## Ownership
- DevOps Lead: execute switch and validate infrastructure health.
- QA Lead: execute smoke checks and attach evidence.
- Engineering Lead: approve incident closure and follow-up fixes.
