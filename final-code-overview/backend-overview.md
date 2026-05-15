# Backend Overview

## Structure
- Stack: Laravel API application in `apps/api`.
- Controllers: `app/Http/Controllers/Api/V1`.
- Middleware: tenant resolution, permission checks, usage quota checks.
- Services: campaigns, providers, billing, idempotency, usage, auditing.
- Jobs: campaign ticking, lead imports, Stripe webhook sync.
- Migrations: tenant-scoped relational model with operational indexing.

## Runtime Pattern
- Routes defined in `routes/api.php`.
- Middleware aliases configured in `bootstrap/app.php`.
- Protected flow typically chains `auth:sanctum`, `tenant.resolve`, quota, then permission middleware.

## Core Service Domains
- Campaign engine: queue dispatch and pacing (`CampaignRunnerService`).
- Dialing orchestration: provider resolution and call creation (`OutboundDialerService`).
- Billing integration: Stripe signature validation and lifecycle sync.
- Reliability: idempotent request handling and audit logging.

## Async and Real-Time
- `RunCampaignTickJob` loops campaign execution in delayed intervals.
- `ProcessLeadImportJob` performs validated CSV ingestion in batches.
- `RealtimeController` exposes SSE stream for live call updates.

## Isolation and Security
- All tenant-owned entities are queried by resolved `tenant_id`.
- Permission middleware gates feature access at route level.
- Quota middleware enforces usage boundaries on metered operations.
