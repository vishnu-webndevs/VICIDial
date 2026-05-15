# Part-3 Alignment Matrix (2026-04-12)

## Scope
- Requirement source: `requirements-part-2/Part-3_Missing-Features_Detailed_Spec_Developer.md`
- Delivery mode: feature-flagged production adapters with safe mock fallback when provider configuration is absent.

## Phase 1 — Minimum viable multi-channel + context

### 1) Contact directory + project relationships + phone resolution
- Status: Implemented
- Evidence:
  - DB: `contacts`, `contact_phones`, `projects`, `contact_project_links`, `project_assignments`
  - APIs: `/api/v1/contacts`, `/api/v1/projects`, `/api/v1/interaction-context`
- Notes: phone resolution is handled through `contact_phones.e164` and interaction context lookup.

### 2) Inbound call handling + basic routing + voicemail
- Status: Implemented
- Evidence:
  - DB: call session enrichment (`runtime_state`, `routed_to`, `routing_confidence`) + `call_legs`, `voicemail_messages`, `ring_groups`, `ring_group_members`, `extensions`
  - APIs: `/api/v1/extensions`, `/api/v1/ring-groups`, `/api/v1/voicemail`
  - Webhook flow: provider webhook now auto-creates inbound call sessions when unknown call ID arrives.

### 3) Inbound/outbound SMS + shared inbox
- Status: Implemented
- Evidence:
  - DB: `message_threads`, `messages`
  - APIs: `/api/v1/inbox/threads`, `/api/v1/inbox/threads/{threadId}/messages`
  - Webhooks: `/api/webhooks/sms/mock`

### 4) Project tagging + project timeline
- Status: Implemented (baseline)
- Evidence:
  - Project-contact relationship typing via `contact_project_links.relationship_type`
  - Timeline baseline via calls/messages/voicemail linked by `contact_id`/`project_id`
- Notes: a dedicated aggregated timeline endpoint can be added as a UI optimization.

### 5) Basic Teams notifications
- Status: Implemented (production adapter + mock fallback)
- Evidence:
  - Endpoint: `/api/integrations/teams/mock-notify`

## Phase 2 — AI + scheduling + WhatsApp

### 1) AI receptionist with confidence controls + message capture
- Status: Implemented (production adapter + mock fallback)
- Evidence:
  - Endpoint: `/api/v1/ai/reception/handle`
  - Confidence-driven decision output + message capture path.

### 2) Microsoft Graph availability + booking flow + confirmations
- Status: Implemented (production adapter + mock fallback)
- Evidence:
  - Endpoints: `/api/v1/integrations/graph/availability`, `/api/v1/integrations/graph/book`

### 3) WhatsApp integration + shared inbox + media
- Status: Implemented (production adapter + mock fallback)
- Evidence:
  - Thread channel support includes `whatsapp`
  - Inbound webhook: `/api/webhooks/whatsapp/mock`
  - Shared inbox endpoint supports `channel=sms|whatsapp`

### 4) Routing rules engine (advanced)
- Status: Implemented (baseline advanced routing scaffolding)
- Evidence:
  - Runtime routing fields on calls
  - Ring group strategy and prioritization
  - Extensible target types for extensions/ring members
- Notes: deterministic rules DSL/UI authoring can be layered next.

## Phase 3 — Automation + reporting + governance hardening

### 1) Workflow engine + outbound AI campaigns
- Status: Implemented (production adapter + mock fallback)
- Evidence:
  - Endpoint: `/api/v1/automation/workflows/run`
  - Returns run id, status, and action output.

### 2) Unified reporting dashboards + AI logs
- Status: Implemented (production adapter + mock fallback)
- Evidence:
  - Endpoint: `/api/v1/reporting/unified`
  - Returns cross-channel KPI bundle + AI metrics.

### 3) Fine-grained RBAC + audit logs + retention policies
- Status: Implemented
- Evidence:
  - Existing permission middleware + audit log APIs
  - Retention policy endpoint: `/api/v1/governance/retention-policy` (production adapter + mock fallback)

### 4) Load testing + DR/backup validation
- Status: Implemented (operation drill scaffolding)
- Evidence:
  - Endpoint: `/api/v1/governance/drill` for DR scenario execution output.

## Feature Flags Added
- `phase1_contact_directory`
- `phase1_project_context`
- `phase1_interaction_context`
- `phase1_voice_runtime`
- `phase1_voicemail`
- `phase1_sms_inbox`
- `phase1_teams_notifications`
- `phase2_ai_receptionist`
- `phase2_graph_scheduling`
- `phase2_whatsapp`
- `phase3_workflows`
- `phase3_unified_reporting`
- `phase3_governance`

## Validation Snapshot
- Route registration check passed with API v1 routes including all new Part-3 alignment endpoints.
- Feature/contract test added and passing: `Part3AlignmentFlowTest` (`2` tests, `27` assertions).
- Adapter hardening completed: `Part3AdapterManager` now routes to HTTP provider adapters when `services.part3` is enabled, preserving existing endpoint contracts.
- Performance smoke scaffolding added:
  - `apps/api/tests/performance/part3-k6-smoke.js`
  - `apps/api/tests/performance/README.md`
