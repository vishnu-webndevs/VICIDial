# AI-Generated E2E Scenario Matrix

This matrix is generated from discovered routes, permissions, and API contracts in the app.

## Coverage Map

1. Authentication
- Register tenant owner
- Login existing owner
- Logout via authenticated API and local session clear

2. Onboarding
- Tenant profile update (caller ID + alert email)
- Provider creation
- Team invitation
- Checklist completion and refresh validation

3. Lead Management
- Create lead with status, owner, tags, notes
- CSV import with async job polling and status validation
- Filter/search validation

4. Campaign and Auto Dialer
- Create auto campaign
- Start and pause campaign
- Queue monitor open and queue visibility checks
- Agent state switching

5. Dialer UI
- Place outbound call
- Toggle mute
- Toggle hold
- End call
- Retry call
- Validate live status panel behavior

6. Analytics
- Date-range apply
- KPI cards render
- Campaign and agent performance sections load

7. RBAC
- Invite restricted role (`support_analyst`)
- Accept invitation
- Verify denied module (`/billing`)
- Verify allowed module (`/analytics`)

8. Billing
- Change plan action
- Setup intent action
- Billing refresh and data rendering

## Validation Rules

- Assert no API `5xx` responses for each scenario.
- Capture screenshot/video/trace on failure.
- Retry each failed test once before final failure.

## 2-User Full-Route CRUD Protocol

### Personas

1. `owner` (tenant admin)
- Creates and updates tenant-scoped records.
- Manages provider/team/campaign/billing settings.

2. `support_analyst` (restricted role)
- Verifies route-level access control and allowed analytics visibility.
- Must be denied billing administration actions.

### Route Coverage Order (Execution Sequence)

1. Authentication and onboarding (`/register`, `/login`, `/onboarding`)
2. Admin setup (`/tenant`, `/providers`, `/team`)
3. Lead CRUD and import (`/crm/leads`)
4. Campaign CRUD and queue control (`/campaigns`)
5. Agent dialer state/actions (`/dialer`)
6. Analytics filters and KPI rendering (`/analytics`)
7. Billing controls and edge cases (`/billing`)
8. RBAC verification with invited restricted user (`/billing`, `/analytics`)

### CRUD Expectations by Module

1. Tenant/provider/team
- Create provider/member invite.
- Read details after reload.
- Update tenant settings and verify persistence.
- Delete/revoke only when API contract allows and tenancy remains valid.

2. Leads/campaigns
- Create records from UI and API-assisted setup.
- Read via table/list/filter states.
- Update status/assignment/run state.
- Delete/archive only through supported controls.

3. Billing/api tokens/webhooks/notifications (visual/admin routes)
- Validate render and action availability by role.
- Confirm restricted role cannot perform admin mutations.

## Stop-Fix-Continue Execution Policy

1. Run with fail-fast guard: `--max-failures 1 --retries 0`.
2. On first failure, halt full-suite execution immediately.
3. Collect diagnostics:
- exact failing test step
- locator/action failure context
- screenshot/video/trace artifact paths
- affected module/route
4. Apply root-cause fix (selector, auth recovery, route fallback, or baseline update).
5. Re-run the exact failed step/test first.
6. If green, resume from the next pending step (do not restart from step 1 unless explicitly requested).
7. After the sequence completes, run one clean full-suite confirmation pass.
