# System Flow

## End-to-End
1. User authenticates and selects tenant context.
2. Frontend sends request with auth token and `X-Tenant-Id`.
3. Backend resolves tenant and enforces RBAC + usage limits.
4. Controllers execute domain logic through services and persist tenant-scoped data.
5. Async jobs process campaign ticks, imports, and billing/webhook synchronization.
6. Webhook endpoints ingest provider/payment events and update local state.
7. Frontend consumes REST + SSE to render operationally current views.

## Module Interactions
- Auth and tenant context gate all protected modules.
- RBAC and subscription state gate privileged and metered operations.
- Providers and calls power campaign dispatch and agent assignment.
- Calls/campaigns/leads feed analytics endpoints.
- Operational actions emit audit logs and notifications.
- API tokens/webhook logs support external integration surfaces.

## Reliability Patterns
- Idempotency keys for replay-safe write endpoints.
- Retry/backoff logic in queue-driven orchestration.
- Composite tenant indexes for high-frequency filtered queries.
- Clear separation across controller, service, and job layers.
