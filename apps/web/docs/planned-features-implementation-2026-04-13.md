# Planned Features Implementation Plan (2026-04-13)

## Scope and Source
- Source roadmap artifact: `C:\xampp\htdocs\vicidial\features\missing.png`.
- Planned items identified and implemented:
  1. AI Receptionist Intent Handling
  2. WhatsApp Messaging Channel
  3. Microsoft Graph Booking Sync
  4. Unified Reporting Layer
  5. Workflow Automation Engine
  6. Advanced Governance Controls
- Architecture constraints followed:
  - Multi-tenant enforcement via `tenant_id` and tenant middleware.
  - Backward-compatible mock/live adapter contract (`Part3AdapterManager`).
  - Feature-flag gating via `config/features.php`.
  - UUID primary keys and Eloquent model patterns.

## Current Status Summary
| Feature | Roadmap Status | Implementation Status | Notes |
|---|---|---|---|
| AI Receptionist Intent Handling | planned | implemented | Persisted AI event records and optional call routing enrichment |
| WhatsApp Messaging Channel | planned | implemented | Inbound/outbound support plus opt-in compliance controls |
| Microsoft Graph Booking Sync | planned | implemented | Availability query and booking persistence with adapter passthrough |
| Unified Reporting Layer | planned | implemented | KPI computation, snapshots, and provider-aware mode handling |
| Workflow Automation Engine | planned | implemented | Workflow definition CRUD-lite and run execution tracking |
| Advanced Governance Controls | planned | implemented | Retention policy state and drill execution history |

## Shared Database Changes
- Migration: `apps/api/database/migrations/2026_04_13_010000_add_planned_feature_tables.php`
- New tables:
  - `ai_reception_events`
  - `graph_availability_queries`
  - `graph_bookings`
  - `workflow_definitions`
  - `workflow_runs`
  - `report_snapshots`
  - `retention_policies`
  - `governance_drills`
  - `whatsapp_opt_ins`
- Backward compatibility:
  - Existing message/call/thread data model remains unchanged.
  - New tables are additive and nullable where integration data may be missing.

## Feature Specifications

### 1) AI Receptionist Intent Handling
- **Database**
  - Table: `ai_reception_events`
  - Stores transcript, confidence threshold, decision, confidence score, recommended route, provider mode, metadata, processing timestamp.
- **API**
  - `POST /api/v1/ai/reception/handle`
  - Validates tenant scope and caller E.164 format.
  - Response includes persisted `event_id`.
- **UI**
  - Included in roadmap status telemetry (`/analytics` page, roadmap section).
  - Future UI extension: event browser with transcript filtering.
- **Business Logic**
  - Calls adapter for AI decisioning (`mock` or `live`).
  - Persists decision payload.
  - If decision is `auto_route`, enriches latest matching `CallSession` routing state.
- **Security**
  - Tenant mismatch guard returns `403 TENANT_MISMATCH`.
  - Input length and confidence constraints enforce payload hygiene.
  - Feature flag `phase2_ai_receptionist` prevents accidental rollout.

### 2) WhatsApp Messaging Channel
- **Database**
  - Table: `whatsapp_opt_ins` with unique key (`tenant_id`, `counterparty_number`).
- **API**
  - Existing: `/api/webhooks/whatsapp/mock`, `/api/v1/inbox/threads/{threadId}/messages`.
  - New: `POST /api/v1/inbox/whatsapp-opt-in`.
- **UI**
  - Current visibility through analytics roadmap panel.
  - Future UI extension: inbox-level consent badge/toggle per thread.
- **Business Logic**
  - Inbound WhatsApp auto-creates opt-in record (`source=inbound_message`).
  - Outbound WhatsApp blocked with `WHATSAPP_OPT_IN_REQUIRED` when opted out.
  - Preserves SMS behavior and provider adapter wiring.
- **Security**
  - Opt-in updates require tenant-authenticated route and payload tenant verification.
  - E.164 regex on WhatsApp numbers.
  - Channel-specific enforcement avoids cross-channel regressions.

### 3) Microsoft Graph Booking Sync
- **Database**
  - `graph_availability_queries` stores search windows and returned slots.
  - `graph_bookings` stores booking linkage and provider booking IDs.
- **API**
  - `POST /api/v1/integrations/graph/availability`
  - `POST /api/v1/integrations/graph/book`
  - Optional `availability_query_id` links booking to query lineage.
- **UI**
  - Status telemetry integrated in analytics roadmap panel.
  - Future UI extension: calendar timeline and slot picker.
- **Business Logic**
  - Adapter response persisted for auditability and retry diagnostics.
  - Booking responses return durable `record_id` in addition to provider identifiers.
- **Security**
  - Tenant-bound record creation and query scoping.
  - Date and duration validation constraints protect provider APIs.
  - Feature flag `phase2_graph_scheduling` gates rollout.

### 4) Unified Reporting Layer
- **Database**
  - `report_snapshots` stores computed/live KPI and AI aggregates.
- **API**
  - `GET /api/v1/reporting/unified?tenant_id=...&from=...&to=...`
  - Persists each generated snapshot and returns `snapshot_id`.
- **UI**
  - Analytics page now includes roadmap implementation status cards.
  - Existing analytics dashboards remain unchanged.
- **Business Logic**
  - Computes KPI totals from calls/messages/voicemails and AI event counts.
  - Uses provider payload in `live` mode, otherwise internal computed data.
  - Preserves mode compatibility values (`live`, `mock`, `computed`).
- **Security**
  - Permission `analytics.view` on endpoint.
  - Tenant input matching and scoped queries.
  - Additive snapshot persistence avoids destructive report rewrites.

### 5) Workflow Automation Engine
- **Database**
  - `workflow_definitions` with unique workflow key per tenant.
  - `workflow_runs` captures input/output, mode, and run lifecycle.
- **API**
  - New: `GET /api/v1/automation/workflows`
  - New: `POST /api/v1/automation/workflows`
  - Existing: `POST /api/v1/automation/workflows/run`
- **UI**
  - Current status reflected in analytics roadmap panel.
  - Future UI extension: workflow builder and run timeline.
- **Business Logic**
  - Definitions are upserted by `workflow_key`.
  - Runs are persisted before and after adapter execution.
  - Disabled workflow guard returns `WORKFLOW_DISABLED`.
- **Security**
  - Tenant mismatch guard and per-tenant uniqueness on workflow keys.
  - Input/steps payload validation limits abuse surface.
  - Feature flag `phase3_workflows` controls exposure.

### 6) Advanced Governance Controls
- **Database**
  - `retention_policies` (one record per tenant).
  - `governance_drills` for scenario execution and RTO/RPO outcomes.
- **API**
  - Existing: `POST /api/v1/governance/retention-policy`
  - New: `GET /api/v1/governance/retention-policy`
  - Existing: `POST /api/v1/governance/drill`
  - New: `GET /api/v1/governance/drills`
- **UI**
  - Current status visible in analytics roadmap panel.
  - Future UI extension: governance center for policy/drill history.
- **Business Logic**
  - Retention policy is upserted and versioned by timestamps/metadata.
  - Drill requests create queued record, then update outcome with adapter results.
- **Security**
  - Permission-protected tenant routes.
  - Scenario allow-list and retention constraints.
  - Feature flag `phase3_governance`.

## Additional Status Endpoint
- Endpoint: `GET /api/v1/features/planned/status`
- Purpose: machine-readable state for all formerly planned roadmap items.
- Payload fields:
  - `feature_key`
  - `status`
  - `evidence_count`
- Designed for dashboarding and release-readiness automation.

## Development Milestones and Deliverables
1. **Foundation (Completed)**
   - Deliverables:
     - Planned-feature migration and model layer.
     - Feature flag compatibility preserved.
2. **Backend Logic and API (Completed)**
   - Deliverables:
     - Persistence wired into AI, Graph, Workflow, Reporting, Governance, WhatsApp.
     - New supporting endpoints (workflows/governance/status/opt-in).
3. **UI Visibility (Completed)**
   - Deliverables:
     - Analytics page roadmap section.
     - Product API client support for planned feature status.
4. **Quality and Regression (Completed)**
   - Deliverables:
     - Expanded feature tests for new endpoints and opt-in enforcement.
     - Diagnostics and targeted test execution.

## Comprehensive Test Cases
1. **AI Reception Success**
   - Given valid tenant + transcript
   - When posting to `/ai/reception/handle`
   - Then returns `202`, includes `event_id`, and persists event row.
2. **AI Tenant Mismatch Rejection**
   - Given authenticated tenant A and payload tenant B
   - When posting to AI endpoint
   - Then returns `403 TENANT_MISMATCH`.
3. **WhatsApp Opt-Out Enforcement**
   - Given WhatsApp thread with opt-in set to false
   - When sending outbound thread message
   - Then returns `422 WHATSAPP_OPT_IN_REQUIRED`.
4. **WhatsApp Inbound Auto-Consent**
   - Given inbound WhatsApp webhook
   - Then creates/retains opt-in record with `source=inbound_message`.
5. **Graph Availability + Booking Persistence**
   - Given valid time window and booking payload
   - Then query/booking records are persisted and linked when `availability_query_id` provided.
6. **Workflow Definition Lifecycle**
   - Given workflow definition payload
   - When posting and listing workflows
   - Then upsert works and list reflects persisted definition.
7. **Workflow Disabled Guard**
   - Given existing workflow with `active=false`
   - When run endpoint is invoked
   - Then returns `422 WORKFLOW_DISABLED`.
8. **Unified Reporting Snapshot**
   - Given reporting request with optional date filters
   - Then returns response with `snapshot_id` and persists KPI snapshot.
9. **Governance Retention Read/Write**
   - Given retention policy update
   - Then `POST` writes and `GET` returns policy for tenant.
10. **Governance Drill History**
    - Given a drill execution request
    - Then returns queued/completed response and appears in drill list.
11. **Planned Feature Status Inventory**
    - Given authenticated tenant
    - When requesting `/features/planned/status`
    - Then returns six feature entries with implementation evidence counts.
12. **Adapter Compatibility**
    - Given live adapter enabled
    - Then endpoints continue to emit provider mode and preserve fallback behavior.

## Backward Compatibility Notes
- Existing mock endpoints and adapter interfaces remain intact.
- Existing API paths are unchanged; added endpoints are additive.
- Data migration is non-destructive and isolated from existing phase1 tables.
- Unified reporting keeps `mock` mode semantics for current tests/integrations.
