# Requirement Document - API Specification

Version: v2.0.0
Source: final-requirement/api-specification.md
Last-Updated: 2026-04-12

# API Specification

## Conventions
- Base path: `/api/v1`.
- Protected routes: Sanctum auth + tenant resolution.
- Tenant header: `X-Tenant-Id`.
- Typical error envelope: `{ "error": { "code": "...", "message": "..." } }`.
- Permission enforcement is route-middleware based (`permission:*`).

## Authentication
- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/forgot-password`
- `POST /auth/reset-password`
- `POST /auth/logout`
- `GET /auth/me`

## Tenant and Team
- `GET /tenant` (`tenant.view`)
- `PATCH /tenant` (`tenant.update`)
- `GET /tenant/voice-profile` (`voice_profile.view`)
- `PATCH /tenant/voice-profile` (`voice_profile.manage`)
- `GET /team/members` (`team.view`)
- `POST /team/invitations` (`team.invite`, `role.assign`)
- `PATCH /team/members/{id}` (`team.update`, `role.assign`)
- `DELETE /team/members/{id}` (`team.remove`)
- `POST /team/invitations/{token}/accept`
- `GET /audit-logs` (`audit.view`)

## Billing
- `GET /plans`
- `GET /subscription` (`billing.view`)
- `POST /subscription/change-plan` (`billing.manage`)
- `GET /billing/usage` (`usage.view`)
- `GET /billing/invoices` (`invoice.view`)
- `GET /billing/payment-methods` (`billing.manage`)
- `POST /billing/payment-methods` (`billing.manage`)
- `POST /billing/setup-intent` (`billing.manage`)
- `POST /webhooks/stripe`

## Providers and Calls
- `GET /providers` (`provider.view`)
- `POST /providers` (`provider.create`)
- `PATCH /providers/{id}` (`provider.update`)
- `POST /providers/{id}/test-connection` (`provider.test`)
- `GET /providers/failover-policy` (`failover.view`)
- `PATCH /providers/failover-policy` (`failover.manage`)
- `POST /calls` (`call.initiate`)
- `GET /calls` (`call.view`)
- `GET /calls/export` (`call.export`)
- `GET /calls/{id}` (`call.view`)
- `POST /calls/{id}/retry` (`call.retry`)
- `POST /calls/{id}/mute` (`call.initiate`)
- `POST /calls/{id}/hold` (`call.initiate`)
- `POST /calls/{id}/end` (`call.initiate`)
- `GET /realtime/calls/stream` (`call.view`)
- `POST /webhooks/twilio`
- `POST /webhooks/vonage`

## CRM and Campaigns
- `GET /leads` (`call.view`)
- `POST /leads` (`call.initiate`)
- `PATCH /leads/{id}` (`call.initiate`)
- `POST /leads/import` (`call.initiate`)
- `GET /leads/import-jobs/{id}` (`call.view`)
- `GET /campaigns` (`call.view`)
- `POST /campaigns` (`call.initiate`)
- `PATCH /campaigns/{id}` (`call.initiate`)
- `POST /campaigns/{id}/start` (`call.initiate`)
- `POST /campaigns/{id}/pause` (`call.initiate`)
- `POST /campaigns/{id}/stop` (`call.initiate`)
- `GET /campaigns/{id}/status` (`call.view`)
- `GET /campaigns/{id}/queue` (`call.view`)
- `POST /agents/session` (`call.initiate`)

## Analytics, Notifications, Search, API Tokens
- `GET /analytics/campaigns` (`analytics.view`)
- `GET /analytics/agents` (`analytics.view`)
- `GET /analytics/trends` (`analytics.view`)
- `GET /webhooks/delivery-logs` (`webhook.view`)
- `GET /notifications` (`tenant.view`)
- `PATCH /notifications/{id}/read` (`tenant.view`)
- `GET /search` (`tenant.view`)
- `GET /api-tokens` (`api_token.view`)
- `POST /api-tokens` (`api_token.create`)
- `DELETE /api-tokens/{id}` (`api_token.revoke`)

## Request and Response Format
- Write operations use JSON except file upload paths (`multipart/form-data` for lead import).
- List operations return `data[]` and pagination metadata where relevant.
- Detail operations return `data{}` object payloads.
- Common error classes: `401`, `403`, `404`, `409`, `422`, `429`.

