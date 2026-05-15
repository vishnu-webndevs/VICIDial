# ADR-003: Blue-Green Deployment with One-Command Rollback

Date: 2026-04-12
Status: Accepted

## Context
- The roadmap requires guaranteed fast rollback with instant traffic switch capability.
- Incident blast radius must be reduced during release waves.

## Decision
- Adopt blue-green deployment strategy for release environments.
- Standard rollback command:
  - `./ops/switch-traffic.ps1 -target blue -instant`
- Keep database changes backward-compatible across switch windows.
- Require post-switch smoke verification before closing incident.

## Consequences
- Rollback SLA target (`<= 5 minutes`) becomes operationally realistic.
- Requires load balancer provider wiring (`LB_PROVIDER`, `LB_RESOURCE_ID`) in deployment environments.
- Release playbook must include validation and sign-off evidence after each switch.
