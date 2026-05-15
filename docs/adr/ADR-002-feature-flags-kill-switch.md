# ADR-002: Runtime Feature Flags and Kill-Switch

Date: 2026-04-12
Status: Accepted

## Context
- Requirements-part-2 features must be rollout-safe and instantly disable-able on incidents.
- Full redeploy rollback is slower than runtime toggles.

## Decision
- Introduce centralized runtime feature flags in API config (`config/features.php`).
- Keep all new feature flags disabled by default in `.env.example`.
- Associate each requirement stream with explicit flag keys (for example `FF_DIALER_V2`, `FF_ANALYTICS_V2`).
- Define kill-switch policy: disabling a flag must immediately remove feature access without data corruption.

## Consequences
- Rollout can proceed in tenant-safe phases.
- Incident response improves due to immediate disable behavior.
- Release checklist must include environment flag snapshot verification to prevent drift.
