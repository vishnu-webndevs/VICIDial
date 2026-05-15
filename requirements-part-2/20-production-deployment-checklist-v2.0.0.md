# Production Deployment Checklist - Requirements Part 2

Version: v2.0.0
Last-Updated: 2026-04-12
Release Branch: `release/requirements-part-2-v2`

## Pre-Deployment
- [ ] Stable baseline tagged (`stable-pre-v2`) in source-of-truth repository.
- [ ] Release branch created and protected.
- [ ] Feature flags defined for all v2 features and defaulted to safe values.
- [ ] Backward-compatible API version negotiation validated (`v1` and `v2`).
- [ ] DB migration forward and rollback scripts verified in staging.
- [ ] Security checks complete (SAST, dependency scan, OWASP review).
- [ ] Performance budget checks pass (page load <= 2s, API p95 <= 200ms for priority endpoints).

## CI and Quality Gates
- [ ] PR checks all pass (`api tests`, `web lint`, `critical smoke`, security gates).
- [ ] Unit coverage target >= 90% for touched services.
- [ ] Contract tests pass for error envelope and version behavior.
- [ ] Realtime and webhook idempotency tests pass.

## Release and Rollback Readiness
- [ ] Blue and green environments healthy and synchronized.
- [ ] Rollback command tested: `./ops/switch-traffic.ps1 -target blue -instant`.
- [ ] Incident channel and on-call roster confirmed.
- [ ] Post-deploy smoke script available and validated.

## Coexistence Validation (Legacy + New)
- [ ] Legacy `v1` clients validated on core flows.
- [ ] `v2` clients validated with `Accept` header negotiation.
- [ ] Tenant isolation verified across both versions.
- [ ] Feature-flag off state leaves legacy behavior intact.

## Formal Sign-Off
| Role | Name | Decision | Date | Notes |
|---|---|---|---|---|
| QA Owner |  | Approved / Rejected |  |  |
| Security Owner |  | Approved / Rejected |  |  |
| Product Owner |  | Approved / Rejected |  |  |
