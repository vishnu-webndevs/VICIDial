# Requirement Document - Workflow Documentation

Version: v2.0.0
Source: final-requirement/workflow-documentation.md
Last-Updated: 2026-04-12

# Workflow Documentation

## Call Flow
1. Authenticated user requests outbound call creation (`POST /calls`) with tenant context.
2. Backend validates request and idempotency key, resolves provider, creates `call_sessions`.
3. Call lifecycle events are stored in `call_events`.
4. Provider webhooks (`/webhooks/twilio`, `/webhooks/vonage`) update call state.
5. UI consumes list/detail APIs and live SSE stream for operator visibility.

## Campaign Execution Flow
1. User configures campaign pacing and targeting.
2. Starting a campaign creates/activates `campaign_runs` and seeds `dial_queue_items`.
3. `RunCampaignTickJob` triggers every 5 seconds while campaign run remains active.
4. `CampaignRunnerService` computes dispatch slots and assigns available agents.
5. `OutboundDialerService` attempts provider-based dialing for eligible queue items.
6. Queue items transition through `pending`, `processing`, `dialed`, `failed`, `completed`.
7. Run counters and campaign stats are refreshed continuously.

## Queue and Worker Flow
- Lead import:
  - Upload triggers `POST /leads/import`.
  - `ProcessLeadImportJob` validates rows and inserts valid leads in batches.
  - Progress/error summary is written back to `lead_import_jobs`.
- Campaign ticks:
  - Tick job performs one scheduling cycle then delayed self-dispatches while running.
- Billing sync:
  - Stripe events are persisted and asynchronously synchronized to billing entities.

## Agent Workflow
1. Agent updates presence via `POST /agents/session`.
2. Runner uses active sessions to allocate queue work.
3. Assignment records are created in `agent_assignments`.
4. Agents execute call actions through call-control endpoints.

## Analytics Workflow
1. Calls/campaign artifacts persist in transactional tables.
2. Analytics endpoints aggregate campaign, agent, and trend outputs.
3. Dashboard views consume aggregates for near-real-time operational insights.

