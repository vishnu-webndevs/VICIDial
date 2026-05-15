# Project Status (Point-by-Point)
Date: 2026-05-06  
Repo: `f:\xampp\htdocs\vicidial`

Goal: ek hi file me point-to-point list mil jaye ki **kya complete hai**, **kya partial hai**, aur **kya abhi pending hai**. Is file me dusri `.md` files ke URLs/links intentionally nahi diye gaye.

Legend:
- ✅ Completed (working end-to-end in repo)
- ⚠️ Partial (basic working, but major parts missing / limited provider / limited UI)
- ❌ Not Implemented (spec-level feature missing)

---

## 1) Completed (✅)

### 1.1 Core Platform (Multi-tenant SaaS)
- ✅ Auth: register, login, logout, forgot/reset password
- ✅ Tenant context: `X-Tenant-Id` based tenant resolution + access boundary
- ✅ RBAC: role/permission seeding + route permission middleware
- ✅ Team management: invite, accept invite, update membership, remove membership
- ✅ Audit logs: list endpoint + audit events
- ✅ Notifications: list + mark as read
- ✅ Global search endpoint
- ✅ API tokens: create/list/revoke
- ✅ API version negotiation by `Accept` (v1/v2) with `X-API-Version` response header

### 1.2 Providers & Calling (Outbound)
- ✅ Provider accounts CRUD + test connection
- ✅ Provider numbers sync/validation (Twilio flow)
- ✅ Agent number assignment (agent -> validated number)
- ✅ Outbound call initiation (async dispatch) + call session persistence
- ✅ Bulk outbound calling (API): create many call sessions + async dispatch (batch id returned)
- ✅ Call controls: retry/mute/hold/end/transfer (control endpoints exist)
- ✅ Provider webhook ingestion: call state updates + call event persistence
- ✅ Webhook logs: overview + delivery logs + replay
- ✅ Real-time SSE stream: call updates

### 1.3 CRM + Campaign Engine
- ✅ Leads: list/create/update
- ✅ Lead CSV import: create job + status polling
- ✅ Lead timeline: calls + messages + dispositions + callbacks (where available)
- ✅ Campaigns: create/update + start/pause/stop + status/queue views
- ✅ Campaign runner/tick job + queue processing + retry/backoff behavior (implemented in services/jobs)

### 1.4 Analytics + Billing (Core)
- ✅ Analytics: campaigns, agents, trends + dashboard summary endpoints
- ✅ Billing: plan catalog + subscription view/change
- ✅ Usage + invoices + payment methods (Stripe-backed plumbing exists)

### 1.5 “Part-3 style” Add-on Modules (Adapter-driven, feature-flagged)
These are implemented as endpoints + DB persistence; by default mock/sandbox mode works, and “live adapter mode” is supported via config.

- ✅ Contact directory (basic CRUD)
- ✅ Projects (basic CRUD) + link contacts + assign engineers
- ✅ Interaction context endpoint (phone -> contact/projects/recent calls/last thread)
- ✅ Shared inbox threads (SMS + WhatsApp) + send message
- ✅ WhatsApp opt-in enforcement (block outbound if opted-out)
- ✅ AI receptionist intent handling (event persistence + optional routing enrichment)
- ✅ Graph availability query persistence + booking persistence
- ✅ Calendar booking lifecycle (MVP): list/show/update/reschedule + cancel endpoints + basic conflict check
- ✅ Workflow definitions (store/list) + workflow run tracking
- ✅ Unified reporting endpoint + snapshot persistence
- ✅ Governance retention policy (store/show) + governance drill run + drill history

---

## 2) Partially Completed (⚠️)

### 2.1 Provider Coverage Gaps
- ⚠️ Twilio: outbound calling implemented
- ⚠️ Vonage: outbound call dispatch implemented (Voice API via JWT), but requires `application_id` + `private_key` (or precomputed `jwt`) and has limited parity vs Twilio
- ⚠️ Vonage: inbound/number validation support is limited

### 2.2 Messaging (Two different surfaces)
- ✅ Lead-scoped SMS/WhatsApp exists (direct Twilio messaging service) + inbound Twilio webhooks exist
- ✅ Lead-scoped bulk SMS/WhatsApp exists (API + queued jobs; requires queue worker running)
- ⚠️ Shared inbox SMS/WhatsApp exists (adapter-driven) with its own flow
- ✅ Unified Inbox UI exists (SMS + WhatsApp) via Conversations page

### 2.3 UI Coverage
- ⚠️ Some UI items are “telemetry-first” (feature exists in API but has limited dedicated screens)
- ⚠️ Search input and notification bell UX is present but not fully feature-complete (not all advanced behaviors implemented)

### 2.4 API Consistency
- ⚠️ Success response shapes are mixed (`{data:...}` vs `{success:true,data:...}` depending on controller)
- ✅ Exception/error envelopes are standardized for API errors (401/422/500 style)

### 2.5 Production Readiness (Repo vs Infra)
- ✅ In-repo hardening: request IDs, secure headers, rate limiting scaffolding, health endpoints, CI assets
- ⚠️ Infra/ops execution: many checks require real production environment and evidence (see Pending section)

---

## 3) Not Implemented (❌) / Pending (Major)

### 3.1 True Inbound Voice Platform Runtime (Spec-level)
⚠️ Implemented (MVP inbound flow), but not full spec-level inbound product:
- ✅ Twilio inbound voice webhook (TwiML) exists
- ✅ DTMF Gather for extension -> Extension lookup -> Ring Group dial -> Voicemail fallback
- ✅ Voicemail recording callback persists voicemail + links to call session
- ✅ Call leg record created for ring-group dial attempts

Still missing vs “full inbound platform”:
- ❌ Advanced transfer types (warm/consult), AI->human continuity model
- ❌ True call legs bridging model (bridged legs + per-leg status updates as first-class)
- ⚠️ Recording policy management (MVP): tenant-level policy endpoints + consent prompt + TwiML recording toggle; per-destination policies are pending
- ❌ Region/media edge strategy (AUS/Bali quality controls) as a product feature

### 3.2 SMS/WhatsApp Compliance + Automation (Spec-level)
- ✅ SMS STOP/START opt-out compliance handling (stored per-tenant per-number)
- ✅ Template system + variable substitution (basic)
- ⚠️ Delivery receipts pipeline (Twilio status callback updates message status + delivered_at), but deep failure analytics is missing
- ⚠️ SLA timers + assignment workflows (MVP): per-tenant SLA policy, due timestamps on threads, breach evaluation + notifications; advanced escalations are missing
- ⚠️ WhatsApp/SMS media storage + scanning/security pipeline (MVP): inbound Twilio media download -> local storage -> allowlist-based “scan_status”; antivirus + signed URLs + stricter ACLs are pending

### 3.3 AI Modules (Runtime)
- ⚠️ AI call artifacts (MVP): async job generates transcript/summary/QA score placeholders; real transcription model/provider integration is pending
- ⚠️ Human-in-the-loop approval flows (MVP): approval requests table + create/list/respond endpoints + webhook response link; true Teams interactive cards/bot integration is pending

### 3.4 Calendar / Teams (Deep Integration)
- ⚠️ Calendar booking lifecycle (MVP): list/show/update/reschedule + cancel endpoints + basic conflict check; full Graph event sync/edge cases are pending
- ⚠️ Teams workflows (MVP): notifications + approval requests + response endpoints; deep linking + native adaptive-card actions require real Teams bot setup

### 3.5 Governance + Data Compliance (Execution)
- ✅ Automated retention enforcement command + scheduler (redact or delete data older than retention_days)
- ⚠️ Legal hold controls (MVP): create/release holds (phone scope) and retention enforcement skips held phone data; broader scopes are pending
- ⚠️ DSR workflows (MVP): create/list/approve requests + scheduled processor for export/erase (with legal-hold safety); ROPA/DPIA artifacts as executable processes are pending

### 3.6 Release Governance (Needs real Git + deployment environment)
- ❌ Stable tag + protected release branch process cannot be verified in this workspace if Git source-of-truth is not attached
- ❌ Blue/green switching and rollback drills require actual infra to validate end-to-end

---

## 4) Quick “What can I use today?” (Practical)

### Works end-to-end today (✅)
- Tenant signup/login
- Add provider (Twilio), validate provider, sync numbers, validate number
- Create agents, assign validated numbers
- Create leads, import leads
- Create campaign, start campaign, monitor calls + analytics
- Use calls page + dialer controls
- Bulk calls (API) + view in Calls page
- Bulk SMS/WhatsApp (API) + view via Leads timeline or Conversations inbox (queue worker required)
- Use webhook logs + replay
- Inbound voice (Twilio): callers can enter extension, ring group dials, voicemail fallback

### Works only in limited mode (⚠️)
- Vonage-based calling (limited)
- WhatsApp/SMS inbox has assignment + SLA basics, but advanced escalations/SLA analytics are missing
