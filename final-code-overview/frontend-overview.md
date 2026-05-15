# Frontend Overview

## Structure
- Stack: Next.js (App Router) in `apps/web`.
- Routes: `src/app/*` pages for auth, tenant operations, dialer, campaigns, CRM, analytics, billing, integrations.
- Shared UI: `src/components`.
- API layer: `src/lib/api.ts` and `src/lib/product-api.ts`.
- Real-time hook: `src/hooks/use-live-calls.ts`.
- Types: `src/types/*`.

## API Usage Model
- Frontend API helpers send bearer token and tenant context (`X-Tenant-Id`).
- Module-level adapters map UI actions to backend endpoint contracts.
- Errors are normalized in API utilities for consistent UX handling.

## Real-Time UI Behavior
- Call pages load initial data via REST.
- SSE stream updates are merged into client state for low-latency status changes.

## Access and UX
- App shell is permission-aware and supports access-denied states.
- Navigation and pages align to backend modules: tenant/team, providers, calls, campaigns, CRM, analytics, billing, and API tokens.

## Fallbacks
- Selected modules include tenant-scoped local storage fallback behavior when backend routes are unavailable in constrained environments.
