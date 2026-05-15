# Requirement Document - System Overview

Version: v2.0.0
Source: final-requirement/system-overview.md
Last-Updated: 2026-04-12

# System Overview

## Product Summary
WND Dialer is a multi-tenant SaaS outbound calling platform with a Laravel API backend and a Next.js frontend. It supports tenant onboarding, RBAC, provider-based calling, campaign automation, CRM leads, analytics, billing, notifications, and API-token integrations.

## Architecture Summary
- Frontend: `apps/web` (Next.js app router, dashboard and operator flows).
- Backend: `apps/api` (Laravel REST APIs under `/api/v1` and webhook ingress under `/webhooks/*`).
- Real-time: Server-Sent Events at `/api/v1/realtime/calls/stream`.
- Async: Queue jobs for campaign ticks, lead imports, and Stripe webhook synchronization.
- Data: Relational schema with tenant isolation using `tenant_id`.

## Module Overview
- Auth and Tenant: registration, login, profile, tenant context, voice profile.
- RBAC and Team: permissions, memberships, invitations, audit trails.
- Billing and Quotas: plans, subscriptions, invoices, usage meters, payment methods.
- Providers and Calls: provider account setup, call lifecycle, call controls, webhook events.
- CRM and Campaigns: lead CRUD/import, campaign lifecycle, queue/agent orchestration.
- Analytics and Operations: campaign/agent trends, webhook logs, notifications, search.
- Integrations: API token creation/revocation and external webhook processing.

## Security and Tenant Model
- Tenant resolution uses `X-Tenant-Id` + active membership (`tenant.resolve` middleware).
- Permission checks use route middleware (`permission:*`) with platform-admin override.
- Usage limits use `usage.quota:*` middleware for metered operations.
- Idempotency is enforced for selected mutation endpoints.
- Credential fields are masked in API responses; operational changes are audit-logged.

## Production Deployment Shape
- API and web apps are independently deployable (`apps/api`, `apps/web`).
- Queue workers are required for campaign ticks, imports, and webhook processing.
- Stripe and provider webhook endpoints must be reachable from public internet.

## AI Modules Status
- V1 production runtime does not require AI modules.
- V2 roadmap scope includes transcription, summarization, QA scoring, and voice-bot automation on top of existing call/event pipelines.

