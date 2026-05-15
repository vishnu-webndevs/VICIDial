# System Architecture Overview

Last updated: 2026-04-12

## Components

- `apps/web`: Next.js frontend (React) for SaaS user workflows and dashboards.
- `apps/api`: Laravel API for authentication, tenant isolation, RBAC, billing, dialer, webhooks.
- `docs/api/openapi-v1-v2.yaml`: versioned API contract with content negotiation.
- External integrations (feature-flagged): Stripe, SMS/WhatsApp, Teams, AI, Graph, workflow/governance adapters.

## Request Flow

1. Browser requests web app (Next.js).
2. Web app calls API (`/api/v1/*`) with bearer token and `X-Tenant-Id`.
3. API middleware pipeline applies:
   - API version negotiation (`Accept`).
   - request ID correlation (`X-Request-Id`).
   - secure headers.
   - auth + tenant resolution + permissions + usage quotas.
4. Controllers execute tenant-scoped business logic and return JSON envelopes.

## Security Boundaries

- Tenant boundary enforced by membership-driven tenant resolution.
- RBAC permissions gate route-level access.
- Auth routes use rate limiting policies.
- Security headers applied at app response layer.
- Sensitive integration paths controlled by feature flags and adapter policy.

## Operations and Availability

- Liveness endpoint: `/up` and `/api/health/live`.
- Readiness endpoint: `/api/health/ready` (database/cache dependency check).
- Blue/green traffic switching playbook: `ops/switch-traffic.ps1`.
- CI policies:
  - regression quality gate
  - production readiness pipeline with coverage, scans, lint/build, critical E2E.
