# Part 3 — Missing Features (Implementation-Ready) — Developer Spec

Audience: Engineering + technical stakeholders  
Scope: Detailed feature definitions for the “Part-3 implementation” phase to reach a production-grade communication platform.

---

## 0) Guiding Principles (applies to all features)

### 0.1 Canonical entities (shared across voice + SMS + WhatsApp)
- **Contact**: A person or organization that communicates with the business.
- **Project**: The job/engagement the contact is calling about.
- **Engineer (Staff User)**: Internal staff member; may be assigned to projects.
- **Communication**: A unified record for any interaction (call, SMS, WhatsApp message, voicemail, AI interaction).
- **Conversation Thread**: A grouping for messages by channel + counterparty (and optionally project).
- **Interaction Context**: The real-time “session” context used during inbound calls (caller, projects, last interactions, routing candidates).

### 0.2 Non-functional requirements (NFRs)
- **Reliability**: Webhook ingestion is idempotent, replay-safe, and resilient to provider retries.
- **Observability**: Correlation IDs, structured logs, metrics, and traces for every external event.
- **Security**: Signed webhooks, least-privilege credentials, encryption at rest for recordings/messages, and audit logs for sensitive actions.
- **Compliance**: Recording consent policy, data retention policies, and access controls per channel.
- **Latency**: Inbound call routing decisions should be made within provider timeouts (e.g., Twilio voice webhook response budget).

### 0.3 Standard API conventions (fixing “API consistency issues”)
- **Response envelope**
  - `success: boolean`
  - `data: object | null`
  - `error: { code: string, message: string, details?: any } | null`
  - `meta: { requestId: string, pagination?: {...} }`
- **Validation**: Input schemas for every endpoint + webhook; reject unknown fields where appropriate.
- **Idempotency**: For provider webhooks and outbound sends, support `Idempotency-Key`.

---

## 1) Voice Platform — Gaps & Required Features

### 1.1 Inbound call handling system (core telephony runtime)
**Goal**: Accept inbound PSTN calls, create a call session, attach context, and route/transfer appropriately.

**Key capabilities**
- Provider webhook endpoints (e.g., Twilio Voice) for:
  - Call initiated / ringing / answered / completed
  - Recording status events
  - DTMF / gather inputs (if IVR used)
- **Call Session state machine**
  - States (example): `initiated → collecting_context → routing → ringing → connected → transferring → completed / failed`
  - Persist transitions with timestamps and actors (system/AI/human)
- **Real-time context fetch**
  - Identify caller by phone number → contact(s) → project(s) → assigned engineer(s)
  - Fetch last N interactions across channels
- **Provider response generation**
  - Generate TwiML/voice instructions (or equivalent) for ring, queue, transfer, voicemail, AI handoff

**Data/events**
- `CallSession`: `id, providerCallSid, from, to, direction, status, startAt, endAt, recordingPolicyId, routedTo, projectId?, contactId?, confidence, metadata`
- Events: `call.session.created`, `call.session.routed`, `call.session.connected`, `call.session.ended`

**Acceptance criteria**
- Inbound call results in a persisted session with deterministic final state.
- Same webhook delivered twice does not create duplicate sessions or duplicate transfers.

---

### 1.2 Extension-based routing
**Goal**: Support calling extensions (e.g., 101, 203) and map to users/ring groups.

**Requirements**
- Extension directory:
  - Unique extension per user and per ring group
  - Reserved extensions (e.g., 0 operator, 999 emergency handling rule)
- DTMF capture flow (if inbound DID connects to IVR):
  - Gather digits with timeout + retries
  - Partial match handling (e.g., 2 digits typed, waiting)
- Routing decision:
  - Exact match → user or ring group
  - No match → fallback (operator/ring group/AI receptionist)

**APIs**
- `POST /extensions` create mapping
- `GET /extensions/:ext` resolve target

**Edge cases**
- Caller enters invalid extension repeatedly → voicemail / AI / operator
- Target is offline/after-hours → fallback

---

### 1.3 Ring groups / routing groups
**Goal**: Ring multiple targets with configurable strategy.

**Group strategies**
- Simultaneous ring
- Sequential ring
- Round-robin with memory (distribute calls)
- Priority + fallback

**Group configuration**
- Members (users, external numbers)
- Ring timeout per member
- Max queue time, max retries
- Office-hours schedule (inherits or overrides global)

**Acceptance criteria**
- Calls ring according to configured strategy and terminate cleanly.
- Group membership edits take effect without redeploy.

---

### 1.4 Advanced call transfer logic
**Goal**: Transfer calls between AI ↔ human, human ↔ human, group ↔ individual, with consultative options.

**Transfer types**
- Blind transfer
- Warm/consult transfer (announce to agent, then bridge)
- Escalation transfer (AI hands off with context)

**Implementation notes**
- Maintain continuity: preserve `CallSession` and create `CallLegs`:
  - `CallLeg`: `id, sessionId, from, to, status, startedAt, endedAt, bridgedToLegId?`
- Track “who initiated transfer” and reason (busy, after-hours, project owner, manual).

**Acceptance criteria**
- A transferred call retains session context and is visible as one conversation.

---

### 1.5 Voicemail or structured message capture
**Goal**: Provide a reliable fallback when no agent is available.

**Modes**
- Classic voicemail recording (audio file)
- Structured intake (DTMF or AI-guided): name, callback number, project reference, urgency, message

**Requirements**
- Configurable greeting per DID/ring group/after-hours
- Store voicemail media securely
- Notify assigned engineer/team via Teams + email/SMS (configurable)

**Acceptance criteria**
- Voicemail saved, transcribed (optional), linked to contact/project when possible.

---

### 1.6 Call recording policy management
**Goal**: Decide when calls are recorded and enforce consent + retention rules.

**Policy configuration**
- Always record / never record / record inbound only / record when AI involved
- Geographic rules (e.g., based on caller country code) if required
- Consent prompt required (play message; allow opt-out)
- Retention period + deletion workflow

**System behavior**
- Apply policy at call start and on transfer (policy can change by destination)
- Persist: `RecordingPolicy`, `CallRecording` metadata (provider recording SID, storage location, duration)

---

### 1.7 Geo-optimized call quality (Australia & Bali support)
**Goal**: Minimize latency and packet loss for AUS + Bali endpoints.

**Requirements**
- Provider region selection (e.g., Twilio Edge locations / media regions)
- Evaluate:
  - Where your compute runs (webhooks + AI orchestration)
  - Where media is anchored (SIP/media region)
- Measure MOS/jitter/RTT if available; store call quality metrics.

**Acceptance criteria**
- Documented routing/media region strategy; dashboards show call quality per region.

---

## 2) Shared Contact Directory — Gaps & Required Features

### 2.1 Centralized company-wide contact directory
**Goal**: Single source of truth for contacts used across voice + SMS + WhatsApp.

**Capabilities**
- CRUD contacts with dedupe:
  - Normalize phone numbers (E.164)
  - Merge contacts workflow with audit trail
- Directory search (name, phone, company, tags, project)
- Import/export (CSV) with mapping

**Data model (minimum)**
- `Contact`: `id, displayName, phones[], emails[], company?, role?, tags[], notes?, createdAt, updatedAt`
- `ContactPhone`: `contactId, e164, label, isPrimary`

---

### 2.2 Multi-field contact structure (company, role, project, engineer, etc.)
**Goal**: Capture business context used for routing and interaction screens.

**Fields**
- Company: legal name, trading name, ABN/ID (optional), address (optional)
- Role: stakeholder type (owner, tenant, manager, supplier)
- Project associations: project role, site address, unit number, access notes
- Engineer associations: assigned engineer, backup engineer, preferred contact method

**Validation**
- Structured enums where possible; freeform allowed but tagged.

---

### 2.3 Contact ↔ Project ↔ Engineer relationships
**Goal**: Many-to-many relationships enabling context and routing.

**Data model**
- `Project`: `id, name, siteAddress, status, priority, ownerContactId?, createdAt`
- `ProjectAssignment`: `projectId, engineerId, role(primary|backup), activeFrom, activeTo`
- `ContactProjectLink`: `contactId, projectId, relationshipType, isPrimary`

**Acceptance criteria**
- Given a phone number, system can produce candidate projects and assigned engineers.

---

### 2.4 Caller identification by phone number
**Goal**: Resolve inbound number to known contact(s) and apply dedupe rules.

**Rules**
- Normalize to E.164 with locale defaults
- Handle shared numbers (company line) mapping to multiple contacts with confidence scoring
- Blocklist/allowlist support

---

### 2.5 Contextual project visibility during interactions
**Goal**: During a call/message, show likely project(s), last actions, and recommended next step.

**Implementation**
- Context service endpoint:
  - `GET /interaction-context?from=+61...&channel=voice`
  - returns: contact candidates, project candidates, assigned engineers, recent comms
- Cache for short periods (seconds) to reduce repeated DB hits during call flow

---

## 3) Project-Based Communication Tagging

### 3.1 Link calls to project IDs
**Goal**: Every call can be tagged to a project (auto or manual).

**Behavior**
- Auto-tag if unambiguous contact→project mapping exists
- If multiple candidates, attach `projectCandidates[]` with confidence scores
- Allow human/AI to set final `projectId` during or after call

**Acceptance criteria**
- Project timeline shows calls with recordings/notes/summaries.

---

### 3.2 Link SMS to project context
Same as 3.1, but for SMS threads and individual messages.

**Notes**
- Tag at thread-level (default) plus allow per-message overrides if needed.

---

### 3.3 Link WhatsApp messages to projects
Same as 3.1 and 3.2 for WhatsApp Business conversations.

---

### 3.4 Automatic project detection based on caller data
**Signals**
- Caller phone number → contact → project links
- Recent interactions (last called about Project X within N days)
- In-message hints (AI/NLP): address, job number, engineer name
- Inbound DID mapping (a DID dedicated to a project or region)

**Output**
- `projectCandidates: [{projectId, confidence, reasons[]}]`

---

### 3.5 Manual confirmation system for low-confidence matches
**Goal**: Prevent incorrect tagging and routing.

**Mechanisms**
- For staff UI: prompt “Which project is this about?” with top candidates + search
- For AI receptionist: ask clarifying question when confidence below threshold
- Store the clarification result for learning/analytics

---

### 3.6 Searchable communication history by project
**Goal**: Unified timeline for each project across channels.

**Requirements**
- Filters: date range, channel, staff, outcome, tags
- Full-text search across notes and AI summaries (respect RBAC)
- Export (CSV/PDF) for reporting if needed

---

## 4) AI Receptionist System

### 4.1 AI voice assistant (Retell AI or equivalent)
**Goal**: Handle inbound calls conversationally before routing or taking a message.

**Integration**
- AI provider connection (SIP/webhook/media stream depending on vendor)
- Tool/function calling:
  - `lookupContact(phone)`
  - `lookupProjects(contactId)`
  - `checkAvailability(engineerId)`
  - `createMessage(payload)`
  - `transferCall(target)`

**Operational controls**
- AI on/off per DID/ring group
- After-hours behavior: AI-only vs ring-first

---

### 4.2 Natural language conversation handling
**Capabilities**
- Identify intent: new job request, existing job update, emergency, scheduling, billing, general enquiry
- Collect required fields based on intent
- Confirm critical facts (address, callback, project) before action

**Acceptance criteria**
- AI can complete an intake flow without human for defined intents.

---

### 4.3 Caller recognition and context fetching
**Behavior**
- On call start, AI requests context from platform context service
- AI uses context to greet (without leaking sensitive info)

**Privacy**
- Mask sensitive fields by role; do not read internal notes aloud.

---

### 4.4 Intelligent routing decisions
**Goal**: AI selects correct human/team or chooses message-taking.

**Signals**
- Project ownership/assignment
- Engineer availability and office hours
- Call urgency
- Caller sentiment (optional)

---

### 4.5 Structured message capture via AI
**Minimum message schema**
- Caller name (if unknown)
- Callback number
- Project (confirmed or candidate)
- Summary of issue/request
- Urgency + preferred contact method + best time

**Output**
- Create a `MessageTicket` or `FollowUpTask` linked to project/contact and notify assignee.

---

### 4.6 AI-generated call summaries
**Artifacts**
- `CallSummary`: structured fields + freeform narrative
- Action items: `[{ownerId?, dueAt?, action}]`

**Where used**
- Project timeline
- Staff inbox (triage)
- Reporting on common issues

---

### 4.7 Confidence-based response control
**Goal**: Avoid incorrect assumptions and risky automation.

**Controls**
- Confidence thresholds per action type:
  - Low-risk (ask clarification) vs high-risk (transfer to specific person, create booking)
- “Human-in-the-loop” approvals for certain actions (e.g., booking or sending quotes)
- Fallback to message-taking when uncertain

**Acceptance criteria**
- The system never silently commits to a project/engineer when confidence < threshold.

---

## 5) SMS Communication System

### 5.1 Inbound SMS handling
**Requirements**
- Provider webhooks for inbound SMS
- Create/update `MessageThread` for the contact + number
- Auto-tag to project candidates
- Notify shared inbox

---

### 5.2 Outbound SMS capability
**Requirements**
- Send SMS via provider API
- Support templates + variables (project name, booking time)
- Delivery receipts and failure reasons
- Rate limiting and opt-out compliance (“STOP” handling)

---

### 5.3 Shared team visibility (inbox system)
**Capabilities**
- Shared inbox view (unassigned vs assigned)
- Assign thread to engineer/team
- Internal notes and @mentions
- SLA timers (time since last customer message)

---

### 5.4 SMS linked to contacts and projects
**Behavior**
- Thread-level default `contactId` + `projectId`
- User can re-tag; audit trail captured

---

### 5.5 Automated SMS workflows (reminders, confirmations)
**Examples**
- Appointment reminders (T-24h, T-2h)
- “We received your request” auto-ack
- “Technician en route” (manual trigger or geofence integration later)

**Workflow engine needs**
- Triggers: calendar event created, call ended, status changed
- Conditions: office hours, contact preference, project status
- Actions: send SMS, notify Teams, create task

---

### 5.6 Post-call follow-up messaging
**Goal**: After calls, automatically send recap and next steps where appropriate.

**Inputs**
- Call outcome + summary + next appointment
- Consent toggles and opt-out

---

## 6) WhatsApp Integration

### 6.1 WhatsApp Business API integration
**Requirements**
- Business verification and number registration (ops)
- Webhook ingestion for inbound messages
- Template message management (for outbound outside 24-hour window)

---

### 6.2 Inbound/outbound WhatsApp messaging
**Features**
- Two-way messaging
- Read receipts, delivery receipts
- Conversation window compliance

---

### 6.3 Media support (images, files)
**Requirements**
- Download/store media securely
- Virus scanning hook (recommended)
- Link media to message record; access controlled by RBAC

---

### 6.4 Shared team inbox
Same capabilities as SMS inbox, with WhatsApp-specific metadata.

---

### 6.5 Message history linked to contacts/projects
Unified timeline across channels.

---

## 7) Intelligent Routing & Transfer Engine

### 7.1 Routing inputs & rules
**Routing dimensions**
- Project ownership / assignment
- Engineer availability (calendar + status)
- Office hours (global + per engineer)
- Location/region
- Skills/tags (e.g., electrical, plumbing, warranty)

**Rule system**
- Priority-ordered rules with conditions + actions
- “Explainability”: record which rule fired and why

**Data model**
- `RoutingRule`: `id, name, priority, conditions(json), action(json), enabled`
- `RoutingDecision`: `sessionId/threadId, chosenTarget, candidates, reasons, createdAt`

---

### 7.2 Fallback routing logic
**Fallback chain**
- Primary engineer → backup engineer → ring group → AI message-taking → voicemail

**Acceptance criteria**
- No call is dropped without a terminal outcome and audit trail.

---

### 7.3 Smart transfer suggestions
**Goal**: Suggest best target during a live call to staff/AI.

**Signals**
- Last handler of project
- Availability + idle time
- Historical resolution rates (later phase)

---

### 7.4 After-hours routing workflows
**Options**
- Emergency-only routing (on-call)
- AI intake + next-business-day scheduling
- Voicemail + SMS ack

---

## 8) Calendar Integration (Microsoft Graph)

### 8.1 Engineer availability checking
**Requirements**
- Graph OAuth app + delegated/application permissions (depending on org policy)
- Query free/busy for engineer calendars
- Cache availability snapshots for short windows

**Acceptance criteria**
- Routing engine can avoid transferring to unavailable engineers.

---

### 8.2 Time slot suggestions
**Behavior**
- Find next N available slots given constraints (duration, working hours, travel buffer)
- Return to AI or UI for proposing to customer

---

### 8.3 Calendar event creation
**Requirements**
- Create event with:
  - Subject, location, description, attendees (optional)
  - Link to project + conversation thread
- Store `externalEventId` and sync status

---

### 8.4 Automated booking workflows
**Flows**
- Inbound request → propose slots → confirm slot → create event → send confirmation (SMS/WhatsApp) → notify Teams

**Edge cases**
- Slot taken between suggestion and booking → re-suggest
- Customer requests reschedule → modify event + notify

---

### 8.5 SMS/WhatsApp confirmation after booking
**Requirements**
- Channel preference per contact
- Template-based confirmation + calendar details

---

## 9) Microsoft Teams Integration

### 9.1 Teams bot or webhook integration
**Capabilities**
- Post notifications to channel or user
- Deep links back to conversation/project
- Configure per team/project routing

---

### 9.2 Interactive approval workflows
**Examples**
- “Confirm project match?” when confidence low
- “Approve booking?” for high-risk automation
- “Approve outbound AI call list?” before sending

---

### 9.3 Adaptive Cards for quick responses
**Card actions**
- Assign to me
- Set project
- Trigger callback task
- Send templated reply

---

### 9.4 Context-based notifications to engineers
**Rules**
- Notify primary engineer on inbound call from their project contact
- Notify backup engineer if primary no-answer
- Notify on new voicemail/message ticket

---

## 10) Structured Message Taking & Notes

### 10.1 Structured message forms
**Goal**: Standardize intake for humans (when AI not used or as fallback).

**Fields**
- Caller/contact, project, issue category, urgency, callback preference, attachments, internal notes

**Acceptance criteria**
- Messages created via form are searchable and reportable.

---

### 10.2 AI-based call summaries
Covered in 4.6, but ensure summaries can be generated:
- On-demand (“Generate summary” button)
- Automatically after call end (if recording/transcript available)

---

### 10.3 Follow-up tracking (callback, tasks)
**Data model**
- `FollowUpTask`: `id, type(callback|quote|schedule|other), ownerId, dueAt, status, linkedTo(call/thread/project/contact)`

---

### 10.4 Notification system for assigned staff
**Channels**
- Teams, email, SMS (internal), push (if mobile app later)
- Rules by urgency and after-hours

---

### 10.5 Action tracking (next steps, deadlines)
**Requirements**
- Task lists per project
- SLA timers and escalation if overdue

---

## 11) Outbound AI Workflows

### 11.1 Automated outbound calling system
**Goal**: AI places calls to contacts for routine operations (confirmations, reminders, collections, updates).

**Requirements**
- Outbound call campaigns with throttling
- Compliance: time-of-day restrictions and opt-out
- Call outcome capture: answered, voicemail, failed, reschedule requested

---

### 11.2 Task/queue-based workflow engine
**Core**
- Durable queue (e.g., DB-backed jobs) with retries + dead-letter
- Workflow definitions: triggers + steps + branching

---

### 11.3 Configurable scripts
**Needs**
- Script templates per workflow type
- Variables from project/contact context
- Versioning + approval history

---

### 11.4 AI voice personas
**Controls**
- Persona selection by brand/region (AUS vs Bali tone)
- Rate, accent, formality

---

### 11.5 Escalation to human agents
**When**
- Customer requests human
- AI uncertainty high
- Payment/complaints keywords (policy-based)

---

### 11.6 Workflow result tracking
**Artifacts**
- `WorkflowRun`, `WorkflowStepRun`, outcomes, costs (AI minutes), and conversions

---

## 12) Unified Communication Reporting

### 12.1 SMS and WhatsApp reporting
**Metrics**
- Volume, response times, resolution times
- Delivery failures
- Template performance

---

### 12.2 Project-based reporting
**Views**
- Communications per project per week
- Outstanding follow-ups
- Customer satisfaction proxy metrics (optional later)

---

### 12.3 AI interaction logs
**Requirements**
- Log prompts/tools invoked, confidence, outcomes, human overrides
- PII-safe storage and redaction rules

---

### 12.4 Workflow outcome tracking
**Metrics**
- Completion rate, escalation rate, time-to-complete
- Per-workflow ROI analysis (time saved)

---

### 12.5 Multi-filter reporting
**Filters**
- Staff, contact, project, channel, outcome, date range, tags, location

---

## 13) Integration Layer

### 13.1 Twilio (Voice, SMS, WhatsApp)
**Required pieces**
- Webhook endpoints + signature validation
- Sending APIs (SMS/WhatsApp)
- Voice call control (TwiML, status callbacks, recording)
- Media handling (recordings, WhatsApp media)

---

### 13.2 Retell AI (or equivalent)
**Required pieces**
- Real-time call connection mechanism
- Tool invocation bridge to your platform APIs
- Session state sync + transcripts/summaries retrieval

---

### 13.3 Microsoft Graph (Calendar)
Covered in section 8.

---

### 13.4 Microsoft Teams
Covered in section 9.

---

### 13.5 External CRM / project systems
**Requirements**
- Sync contacts/projects with mapping and conflict resolution
- Webhook ingestion from CRM changes
- Data provenance tracking (“source of truth” field)

---

## 14) Security & Governance

### 14.1 Advanced role-based access control (fine-grained)
**Roles**
- Admin, Manager, Engineer, Reception, Read-only, External (optional)

**Permissions**
- Per channel: view/send/export
- Per project: restrict visibility to assigned staff
- Per media: recordings and attachments access policy

---

### 14.2 Data privacy handling for communication records
**Controls**
- Field-level masking (phone/email partial)
- Redaction of AI logs
- Retention policies per channel

---

### 14.3 Backup & disaster recovery strategy
**Requirements**
- DB backups (point-in-time recovery)
- Media storage backups (recordings, attachments)
- Runbooks and periodic restore tests

---

### 14.4 Credential and API key management system
**Requirements**
- Central secrets manager
- Rotation policies
- Environment separation (dev/stage/prod)

---

### 14.5 Full audit logging across all communication channels
**Audit events**
- View/export recording
- Send message
- Change routing rule/policy
- Merge contacts
- Re-tag project

---

## 15) API & System Consistency Issues (fix list)

### 15.1 Standardized API response format
Covered in 0.3; must be applied everywhere.

### 15.2 Frontend-backend contract alignment
**Requirements**
- OpenAPI specs generated and versioned
- Contract tests in CI

### 15.3 Missing endpoints (e.g., call analytics mismatch)
**Process**
- Inventory UI needs vs API availability
- Create endpoints or remove dead UI paths

### 15.4 Input validation for external webhooks
**Requirements**
- Provider signature verification
- Schema validation + rate limiting
- Dead-letter store for failed events + replay tool

---

## 16) Testing & Reliability

### 16.1 Negative test scenarios
**Examples**
- Unknown phone number inbound
- Provider sends out-of-order events
- Duplicate webhooks
- AI tool-call timeout

---

### 16.2 Webhook failure handling tests
**Requirements**
- Simulate provider retries
- Ensure idempotency + safe reprocessing

---

### 16.3 API contract testing
**Requirements**
- Snapshot tests for response envelopes and key endpoints
- Consumer-driven contracts for frontend

---

### 16.4 Load and performance testing
**Targets**
- Burst inbound calls
- High message throughput
- Reporting queries and full-text search

---

## Recommended Build Phases (MVP → Production)

### Phase 1 — Minimum viable multi-channel + context
1. Contact directory + project relationships + phone resolution
2. Inbound call handling + basic routing + voicemail
3. Inbound/outbound SMS + shared inbox
4. Project tagging + project timeline
5. Basic Teams notifications

### Phase 2 — AI + scheduling + WhatsApp
1. AI receptionist with confidence controls + message capture
2. Microsoft Graph availability + booking flow + confirmations
3. WhatsApp integration + shared inbox + media
4. Routing rules engine (advanced)

### Phase 3 — Automation + reporting + governance hardening
1. Workflow engine + outbound AI campaigns
2. Unified reporting dashboards + AI logs
3. Fine-grained RBAC + audit logs + retention policies
4. Load testing + DR/backup validation

