# Implementation Roadmap - Requirements Part 2

Version: v2.0.0
Source Set: requirements-part-2/*.md
Last-Updated: 2026-04-12
Owner: Engineering + Product + QA + Security

## 1) Executive Summary
- This roadmap pivots delivery to `requirements-part-2` as the active requirement baseline.
- Scope includes all functional domains: Auth/Tenant, Dialer, CRM, Campaigns, RBAC, Billing, Realtime, Analytics, and AI Modules (V2).
- Delivery model is risk-first and dependency-ordered, with mandatory runtime feature flags, API backward compatibility (`v1` and `v2` by `Accept` header), and CI-enforced regression gates.
- Target quality gates: unit-test coverage >= 90% line coverage per service, critical-path e2e smoke coverage, performance budgets (`page load <= 2s`, `API p95 <= 200ms` for priority endpoints), and OWASP Top-10 controls.

## 2) Requirement Inventory and Ranking

### Ranking Method
- Business Value: 1 (low) to 5 (critical revenue/retention impact)
- Technical Risk: 1 (low) to 5 (high uncertainty/regression risk)
- Dependency Weight: 1 (independent) to 5 (blocks many streams)
- Priority Score: `(Business Value * 2) + Dependency Weight - Technical Risk`

### Ranked Backlog
| ID | Requirement | Type | BV | Risk | Dep | Priority | Depends On |
|---|---|---|---:|---:|---:|---:|---|
| FR-01 | Auth + Tenant Context | Functional | 5 | 2 | 5 | 13 | None |
| FR-05 | RBAC + Team + Audit Visibility | Functional | 5 | 3 | 5 | 12 | FR-01 |
| FR-02 | Dialer Core + Call Controls | Functional | 5 | 4 | 4 | 10 | FR-01, FR-05 |
| FR-03 | CRM Leads + Import | Functional | 4 | 3 | 4 | 9 | FR-01, FR-05 |
| FR-04 | Campaign Lifecycle + Queue Dispatch | Functional | 5 | 4 | 4 | 10 | FR-02, FR-03 |
| FR-07 | Real-Time SSE Call Stream | Functional | 4 | 3 | 3 | 8 | FR-02 |
| FR-06 | Billing + Usage + Stripe Sync | Functional | 4 | 4 | 3 | 7 | FR-01, FR-05 |
| FR-08 | Analytics (campaign/agent/trends) | Functional | 4 | 3 | 3 | 8 | FR-02, FR-03, FR-04 |
| FR-09 | AI Modules (V2 runtime) | Functional | 3 | 5 | 2 | 3 | FR-02, FR-07, FR-08 |
| NFR-01 | Multi-tenancy isolation | Non-Functional | 5 | 3 | 5 | 12 | FR-01 |
| NFR-02 | API consistency + compatibility (`v1` -> `v2`) | Non-Functional | 5 | 4 | 5 | 11 | FR-01 |
| NFR-03 | Performance budgets | Non-Functional | 5 | 3 | 4 | 11 | FR-02, FR-07 |
| NFR-04 | Security baseline (OWASP, JWT rotation, rate limits) | Non-Functional | 5 | 3 | 5 | 12 | FR-01, FR-05 |
| NFR-05 | Regression-proof release governance | Non-Functional | 5 | 2 | 5 | 13 | All |

## 3) Dependency-Ordered Implementation Waves

### Wave 0 (Stabilization Foundation)
- FR-01 Auth/Tenant, FR-05 RBAC, NFR-01, NFR-02, NFR-04.
- Deliverables:
  - Unified auth/tenant middleware contract.
  - Permission matrix and audit event taxonomy.
  - API version negotiation by `Accept` header: `application/vnd.wnddialer.v1+json` and `application/vnd.wnddialer.v2+json`.
  - Security hardening baseline (token rotation, request validation, throttling).

### Wave 1 (Core Operations)
- FR-02 Dialer, FR-03 CRM, FR-07 Realtime.
- Deliverables:
  - Stable outbound call lifecycle and controls.
  - Lead lifecycle and CSV import reliability.
  - Realtime event streaming with client reconciliation.

### Wave 2 (Revenue and Scale)
- FR-04 Campaigns, FR-06 Billing, FR-08 Analytics.
- Deliverables:
  - Campaign engine with queue orchestration and retry controls.
  - Stripe-backed subscription and invoice integrity.
  - Operational analytics and trend dashboards.

### Wave 3 (V2 Intelligence)
- FR-09 AI modules + NFR-03 optimization hardening.
- Deliverables:
  - Transcription/summarization/sentiment/QA scoring rollout behind flags.
  - Latency and throughput hardening under production traffic profiles.

## 4) Granular Requirement Specifications

### FR-01 Auth and Tenant
- Acceptance Criteria:
  - Tenant registration provisions owner membership and tenant context in one transaction.
  - Login/logout/reset-password flows return deterministic status codes and structured errors.
  - Tenant and voice-profile updates are permission-gated and audit-logged.
  - Cross-tenant access attempts always return `403`.
- Impacted Microservices:
  - `api-gateway` (Laravel routes/middleware), `identity-service`, `tenant-service`, `audit-service`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `ALTER TABLE memberships ADD COLUMN is_owner BOOLEAN NOT NULL DEFAULT FALSE;`
    - `CREATE INDEX idx_memberships_tenant_user ON memberships(tenant_id, user_id);`
  - Rollback:
    - `DROP INDEX idx_memberships_tenant_user;`
    - `ALTER TABLE memberships DROP COLUMN is_owner;`
- REST Contract (v2):
  - `POST /api/auth/register`
    - Request DTO: `{ companyName, ownerName, email, password, timezone }`
    - Response `201`: `{ success, data: { user, tenant, token }, error: null, meta }`
    - Errors: `409 EMAIL_EXISTS`, `422 VALIDATION_FAILED`.
  - `GET /api/tenant`
    - Header: `X-Tenant-Id`
    - Response `200`: tenant object.
- GraphQL:
  - Not in current scope; no GraphQL endpoint introduced in v2.
- Test Targets:
  - Unit: auth service, tenant resolver, permission checks >= 90% line coverage.
  - Integration: register/login/tenant update flows and audit logs.
- UI/UX Deliverables:
  - Wireframes: registration wizard, tenant settings, voice profile settings.
  - Accessibility: WCAG 2.2 AA, keyboard-only form completion, focus order, contrast >= 4.5:1.
  - Breakpoints: `360`, `768`, `1024`, `1440`.

### FR-02 Dialer Core
- Acceptance Criteria:
  - Outbound call creation persists `call_sessions` and initial `call_events`.
  - Call controls (`retry/mute/hold/end`) are idempotent and state-valid.
  - Export endpoint includes tenant-scoped records only.
  - Provider webhook duplicates do not create duplicate state transitions.
- Impacted Microservices:
  - `call-orchestrator`, `provider-adapter`, `event-store`, `web-frontend`, `realtime-sse`.
- DB Migrations:
  - Forward:
    - `ALTER TABLE call_sessions ADD COLUMN provider_latency_ms INT NULL;`
    - `CREATE INDEX idx_call_sessions_tenant_created ON call_sessions(tenant_id, created_at DESC);`
  - Rollback:
    - `DROP INDEX idx_call_sessions_tenant_created;`
    - `ALTER TABLE call_sessions DROP COLUMN provider_latency_ms;`
- REST Contract (v2):
  - `POST /api/calls`
    - Request DTO: `{ to, from, providerAccountId?, metadata?, idempotencyKey }`
    - Response `201`: `{ callId, status, provider, startedAt }`
    - Errors: `409 IDEMPOTENCY_CONFLICT`, `422 INVALID_PHONE`, `429 RATE_LIMITED`.
  - `POST /api/calls/{id}/hold`
    - Request DTO: `{ reason? }`
    - Response `200`: updated call state.
    - Errors: `409 INVALID_STATE_TRANSITION`.
- GraphQL:
  - Not in scope for this phase.
- Test Targets:
  - Unit: state machine rules, webhook deduping >= 90%.
  - Contract tests: `/calls`, call-controls, webhook payload parsing.
- UI/UX Deliverables:
  - Wireframes: live call console, call detail timeline, export dialog.
  - A11y: ARIA live regions for call state updates, keyboard shortcuts with discoverability.
  - Breakpoints: `360`, `768`, `1280`.

### FR-03 CRM Leads
- Acceptance Criteria:
  - Tenant-scoped lead CRUD supports status/tags/notes/owner.
  - CSV import produces deterministic job status and row-level error report.
  - Import remains resumable/retry-safe for transient failures.
- Impacted Microservices:
  - `crm-service`, `import-worker`, `notification-service`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `ALTER TABLE leads ADD COLUMN source VARCHAR(64) NULL;`
    - `CREATE INDEX idx_leads_tenant_status ON leads(tenant_id, status);`
  - Rollback:
    - `DROP INDEX idx_leads_tenant_status;`
    - `ALTER TABLE leads DROP COLUMN source;`
- REST Contract (v2):
  - `POST /api/leads/import`
    - `multipart/form-data` file + mapping descriptor.
    - Response `202`: `{ importJobId, status: "queued" }`
    - Errors: `422 INVALID_CSV`, `413 FILE_TOO_LARGE`.
- Test Targets:
  - Unit: CSV parser, dedupe matcher >= 90%.
  - Integration: import workflow + retry path.
- UI/UX Deliverables:
  - Wireframes: leads list/filter panel, import wizard, error-report panel.
  - A11y: table semantics, screen-reader labels for filter chips.
  - Breakpoints: `360`, `768`, `1024`, `1440`.

### FR-04 Campaigns
- Acceptance Criteria:
  - Campaign state transitions (`draft/running/paused/stopped`) enforce valid transitions.
  - Queue generation aligns with targeting and pacing configuration.
  - Failed dial attempts follow retry/backoff policy and are auditable.
- Impacted Microservices:
  - `campaign-service`, `queue-worker`, `dialer-service`, `agent-session-service`.
- DB Migrations:
  - Forward:
    - `ALTER TABLE campaigns ADD COLUMN pacing_strategy VARCHAR(32) DEFAULT 'balanced';`
    - `CREATE INDEX idx_queue_tenant_status_nextattempt ON dial_queue_items(tenant_id, status, next_attempt_at);`
  - Rollback:
    - `DROP INDEX idx_queue_tenant_status_nextattempt;`
    - `ALTER TABLE campaigns DROP COLUMN pacing_strategy;`
- REST Contract (v2):
  - `POST /api/campaigns/{id}/start` -> `202` with run id.
  - `GET /api/campaigns/{id}/queue` -> paginated queue statuses.
  - Error payload (all): `{ error: { code, message, details?, traceId } }`.
- Test Targets:
  - Unit: dispatch allocation and backoff calculators >= 90%.
  - Integration: scheduler tick to queue transition tests.
- UI/UX Deliverables:
  - Wireframes: campaign builder, run monitor, queue inspector.
  - A11y: status color not sole signal; textual state badges.
  - Breakpoints: `768`, `1024`, `1440`.

### FR-05 RBAC and Team
- Acceptance Criteria:
  - Permission middleware denies unauthorized access on all protected routes.
  - Team invite/accept/update/remove paths are audit-logged with actor and tenant.
  - Audit log UI is visible only to privileged roles.
- Impacted Microservices:
  - `authz-service`, `team-service`, `audit-service`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `CREATE TABLE role_permission_overrides (...)`
    - `CREATE INDEX idx_audit_tenant_event_time ON audit_logs(tenant_id, event_type, created_at DESC);`
  - Rollback:
    - `DROP INDEX idx_audit_tenant_event_time;`
    - `DROP TABLE role_permission_overrides;`
- REST Contract (v2):
  - `POST /api/team/invitations`
    - Request DTO: `{ email, roleId, expiresAt }`
    - Response `201`: invite details.
  - `GET /api/audit-logs`
    - Query: `{ actorId?, eventType?, from?, to?, page }`
    - Response `200`: paged events.
- Test Targets:
  - Unit: permission resolver matrix >= 90%.
  - Security tests: privilege escalation attempts blocked.
- UI/UX Deliverables:
  - Wireframes: team roster, role assignment modal, audit explorer.
  - A11y: accessible modal focus trap and keyboard close behavior.
  - Breakpoints: `768`, `1280`.

### FR-06 Billing
- Acceptance Criteria:
  - Plan change updates subscription and usage entitlements atomically.
  - Stripe webhook replay is idempotent.
  - Invoice and usage pages reflect synchronized billing state.
- Impacted Microservices:
  - `billing-service`, `stripe-webhook-worker`, `usage-meter-service`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `ALTER TABLE subscriptions ADD COLUMN entitlement_snapshot JSON NULL;`
    - `CREATE UNIQUE INDEX ux_stripe_event_unique ON stripe_webhook_events(provider_event_id);`
  - Rollback:
    - `DROP INDEX ux_stripe_event_unique;`
    - `ALTER TABLE subscriptions DROP COLUMN entitlement_snapshot;`
- REST Contract (v2):
  - `POST /api/subscription/change-plan`
    - Request DTO: `{ targetPlanId, prorationMode }`
    - `200` success, `409 PLAN_CONFLICT`, `402 PAYMENT_REQUIRED`.
  - `POST /api/webhooks/stripe`
    - `200` always for accepted events; failed validation `401`.
- Test Targets:
  - Unit: invoice mapper, proration calculator >= 90%.
  - Integration: webhook replay and state sync.
- UI/UX Deliverables:
  - Wireframes: plan selector, payment methods, invoice history.
  - A11y: semantic headings in billing summary and accessible table actions.
  - Breakpoints: `360`, `768`, `1024`.

### FR-07 Real-Time System
- Acceptance Criteria:
  - SSE stream reconnect logic prevents duplicate UI events.
  - Stream latency for call state changes is <= 1s median in production-like tests.
- Impacted Microservices:
  - `realtime-sse-service`, `call-event-publisher`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `CREATE TABLE stream_offsets (...)` (if durable cursor needed).
  - Rollback:
    - `DROP TABLE stream_offsets;`
- REST Contract (v2):
  - `GET /api/realtime/calls/stream`
    - Headers: `Accept: text/event-stream`
    - Events: `call.updated`, `call.ended`.
- Test Targets:
  - Unit: stream serialization >= 90%.
  - E2E: network drop/reconnect smoke tests.
- UI/UX Deliverables:
  - Wireframes: live status chips, stream health indicator.
  - A11y: non-visual state announcement strategy.
  - Breakpoints: `360`, `768`, `1280`.

### FR-08 Analytics
- Acceptance Criteria:
  - Campaign/agent/trend metrics match source transactional records within tolerance.
  - Dashboard filters are tenant-safe and performant at target scale.
- Impacted Microservices:
  - `analytics-service`, `aggregation-worker`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `CREATE MATERIALIZED VIEW campaign_kpi_daily ...` (or summary table alternative).
    - `CREATE INDEX idx_campaign_stats_tenant_day ON campaign_daily_stats(tenant_id, day);`
  - Rollback:
    - `DROP INDEX idx_campaign_stats_tenant_day;`
    - `DROP MATERIALIZED VIEW campaign_kpi_daily;`
- REST Contract (v2):
  - `GET /api/analytics/campaigns`, `/agents`, `/trends`
    - Query DTO: `{ from, to, campaignId?, agentId?, tz }`
    - `200` returns typed arrays and summary objects.
- Test Targets:
  - Unit: aggregators and time-bucket calculators >= 90%.
  - Contract tests for analytics payload shape.
- UI/UX Deliverables:
  - Wireframes: KPI cards, trend charts, filter tray.
  - A11y: chart fallback tables and descriptive captions.
  - Breakpoints: `360`, `768`, `1024`, `1440`.

### FR-09 AI Modules (V2)
- Acceptance Criteria:
  - Transcription, summarization, and scoring are feature-flagged and disabled by default.
  - AI failure does not block primary calling workflows.
  - Generated artifacts are tenant-scoped and redact configured sensitive fields.
- Impacted Microservices:
  - `ai-orchestrator`, `transcription-worker`, `scoring-service`, `call-orchestrator`, `web-frontend`.
- DB Migrations:
  - Forward:
    - `CREATE TABLE ai_artifacts (...)`
    - `CREATE INDEX idx_ai_artifacts_tenant_call ON ai_artifacts(tenant_id, call_session_id);`
  - Rollback:
    - `DROP INDEX idx_ai_artifacts_tenant_call;`
    - `DROP TABLE ai_artifacts;`
- REST Contract (v2):
  - `POST /api/ai/summaries/generate`
    - Request DTO: `{ callSessionId, mode }`
    - `202` queued, `409 FEATURE_DISABLED`, `422 INVALID_CALL`.
- Test Targets:
  - Unit: prompt composer and redaction pipeline >= 90%.
  - Security tests: no secret leakage in prompts/logs.
- UI/UX Deliverables:
  - Wireframes: AI summary panel, confidence indicators, regenerate action.
  - A11y: confidence labels with text equivalents.
  - Breakpoints: `768`, `1024`, `1440`.

## 5) Cross-Cutting NFR Controls

### Performance Budgets
- Web:
  - Initial dashboard load <= 2s on standard broadband profile.
  - Largest Contentful Paint <= 2.5s for core operator pages.
- API:
  - p95 <= 200ms for read-heavy endpoints (`/calls`, `/analytics/*`, `/leads`) under normal load.
  - p95 <= 350ms for write endpoints with external provider interaction.
- Realtime:
  - Event propagation median <= 1s; p95 <= 2s.

### Security Controls
- OWASP Top-10 compliance checklist integrated into CI security stage.
- JWT/Token controls:
  - Short-lived access token + refresh rotation.
  - Refresh token invalidation on logout/password reset.
- Rate limiting:
  - Auth endpoints: `10 req/min/IP`.
  - Webhooks: provider-specific signature + `120 req/min/provider`.
  - Mutation endpoints: tenant-scoped burst and sustained limits.
- Additional:
  - CSP, HSTS, secure cookies, secrets vault usage, PII masking in logs.

### API Compatibility
- Maintain `v1` and `v2` simultaneously by `Accept` header negotiation.
- Deprecation strategy:
  - `Deprecation` and `Sunset` response headers for v1.
  - Contract test suite for v1 and v2 parity where required.

## 6) Release Governance and Regression Prevention

### Git and Branching Strategy
- Tag last stable commit:
  - `git tag -a stable-pre-v2 -m "Last stable before requirements-part-2 rollout"`
  - `git push origin stable-pre-v2`
- Create dedicated release branch:
  - `git checkout -b release/requirements-part-2-v2`
  - `git push -u origin release/requirements-part-2-v2`

### Feature Flag and Kill-Switch Policy
- Every new feature must be wrapped with runtime flag:
  - Example keys: `ff.auth.v2`, `ff.dialer.v2`, `ff.ai.summaries`.
- Kill switch must disable feature without redeploy (config service or admin toggle).
- Flag audit events required for every toggle change.

### CI Quality Gates (PR Required)
- Lint/type/security scan must pass.
- Unit tests >= 90% line coverage per touched service.
- Contract tests for API envelopes and version behavior.
- Cypress smoke tests for critical paths:
  - Auth, outbound call create/control, lead import, campaign start/pause, billing summary.
- Merge blocked unless all checks are green.

### One-Command Rollback Playbook (Blue-Green)
- Precondition:
  - Blue and Green environments synced with DB backward-compatible migrations.
- Traffic switch command:
  - `./ops/switch-traffic.ps1 -target blue -instant`
- Validation:
  - Health checks + synthetic smoke run within 2 minutes.
- Recovery SLA:
  - <= 5 minutes rollback from incident detection.

## 7) Required Delivery Artifacts
- Integrated codebase with feature-flagged implementation.
- Updated OpenAPI/Swagger for `v1` and `v2` endpoints.
- Updated Postman collections for both API versions.
- README updates for setup, flags, rollout, and rollback.
- ADR set:
  - API version negotiation ADR.
  - Feature flag and kill-switch ADR.
  - Blue-green deployment ADR.
- Test assets:
  - Jest/Mocha unit suites >= 90% line coverage.
  - Cypress critical-path smoke suite.
- Production readiness checklist with sign-offs:
  - QA, Security, Product owners.
  - Explicit coexistence validation for legacy (`v1`) and new (`v2`) features.

## 8) Milestones and Timeline (Indicative)
- M0 (Week 0-1): Foundation, branch/tag/flags/versioning scaffolding.
- M1 (Week 2-4): Auth/RBAC + dialer core stabilization.
- M2 (Week 5-7): CRM + campaigns + realtime hardening.
- M3 (Week 8-9): Billing + analytics.
- M4 (Week 10-11): AI modules behind flags + performance hardening.
- M5 (Week 12): Full regression, security sign-off, blue-green rehearsal, go-live.

## 9) Risks and Mitigations
- Risk: Regression in call lifecycle state handling.
  - Mitigation: state-machine invariant tests + shadow traffic validation.
- Risk: Stripe webhook duplication/out-of-order events.
  - Mitigation: idempotency keys + event sequencing checks + replay harness.
- Risk: Performance degradation in analytics queries.
  - Mitigation: pre-aggregation strategy + query budget alerts.
- Risk: Feature flag drift across environments.
  - Mitigation: environment flag snapshots in CI/CD and release checklist.
- Risk: Security drift during fast delivery.
  - Mitigation: mandatory SAST/DAST + threat-model review per wave.

## 10) Traceability to Source Files
- Functional baseline:
  - `01-feature-auth-and-tenant-v2.0.0.md` ... `09-feature-ai-modules-v2.0.0.md`
- API baseline:
  - `10-api-specification-v2.0.0.md`
- Data baseline:
  - `11-database-schema-v2.0.0.md`
- Architecture/workflow baselines:
  - `12-system-overview-v2.0.0.md`
  - `13-workflow-documentation-v2.0.0.md`
