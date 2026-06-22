[OPEN] Debug Session: auto-dialer-outbound-calls

## Symptom
- Expected:
- Actual:
- Impact:
- Regression window (what changed recently):

## Reproduction Steps
1.
2.
3.

## Environment
- Web URL:
- API URL:
- Browser:
- Tenant ID:
- Campaign ID:
- Provider (Twilio/Vonage/etc):

## Hypotheses
1.
2.
3.
4.
5.

## Instrumentation Plan
- Client (web): capture dialer/campaign start actions and API responses.
- Server (api): capture campaign tick/dispatch, provider call creation, and any gating failures.

## Evidence Log Links
- Pre-fix:
- Post-fix:

## Findings
- Confirmed:
- `twilio.gather` is being recorded, which proves the IVR action callback executes successfully.
- `gatherResult()` updated lead metadata/status but did not advance `CallSession.status`, so the UI kept showing the old `queued` state when provider completion callbacks were missing/delayed.
- Provider status callbacks remain a secondary risk if `APP_URL` is not publicly reachable, because the canonical `/api/webhooks/twilio` completion event may still not arrive.
- Rejected:
- The issue is not in lead qualification logic; the lead update already succeeds.
- The issue is not in call creation/dispatch, because the timeline already contains `outbound.auto_dial` and `outbound.dispatched`.

## Fix
- Patch:
- In `TwilioVoiceWebhookController::gatherResult()`, when Gather completes and the call is not already terminal, the session now advances to `completed`, sets `runtime_state=completed`, and stamps `started_at`/`ended_at` fallback values before saving.
- Verification:
- Reproduce one auto-dial call, press `1`, then confirm the call detail status changes from `Queued` to `Completed` immediately after the gather callback.
