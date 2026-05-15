# Requirement Document - Database Schema

Version: v2.0.0
Source: final-requirement/database-schema.md
Last-Updated: 2026-04-12

# Database Schema

## Design
- UUID primary keys across app entities.
- Tenant isolation via `tenant_id` on tenant-owned tables.
- Foreign keys for integrity and composite indexes for tenant-scoped filtering.

## Identity and Access Tables
- `users`
- `tenants`
- `tenant_settings`
- `roles`
- `permissions`
- `role_permissions`
- `memberships`
- `audit_logs`
- `personal_access_tokens`
- `password_reset_tokens`
- `sessions`

## Billing and Usage Tables
- `plans`
- `subscriptions`
- `usage_meters`
- `usage_events`
- `invoices`
- `payment_methods`
- `stripe_webhook_events`

## Provider and Calling Tables
- `provider_accounts`
- `call_sessions`
- `call_events`
- `idempotency_keys`

## CRM and Campaign Tables
- `leads`
- `lead_import_jobs`
- `campaigns`
- `campaign_runs`
- `dial_queue_items`
- `agent_sessions`
- `agent_assignments`
- `campaign_hourly_stats`
- `campaign_daily_stats`

## Operational Tables
- `notifications`
- Queue/cache infra: `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`

## Key Relationships
- Tenant ownership: `tenants` -> memberships, subscriptions, providers, calls, campaigns, leads.
- RBAC: `roles` <-> `permissions` through `role_permissions`; `memberships` bind user-role-tenant.
- Calling: `call_sessions` -> `call_events`; `provider_accounts` -> `call_sessions`.
- Campaigns: `campaigns` -> `campaign_runs` -> `dial_queue_items` -> `leads` and `call_sessions`.
- Billing: `tenants` -> usage/invoices/payment_methods/subscriptions.

## Index Strategy
- Composite indexes primarily on (`tenant_id`, status/time columns).
- Uniqueness where needed for tenant-bound memberships and integration tokens.
- Query-path indexes for queue availability, call timelines, and analytics windows.

## AI Scope Note
- AI-specific persistent schema is intentionally deferred to future migrations when V2 AI modules are activated.

