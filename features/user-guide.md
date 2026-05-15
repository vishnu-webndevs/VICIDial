# WND Dialer User Guide (Complete Feature + Gap Audit)

This guide covers:
- End-to-end usage of all currently exposed UI modules
- Full CRUD visual workflow (Team module)
- Missing/partial feature audit (including WhatsApp)
- Troubleshooting for missing images and feature accessibility

Reference artifacts:
- Inventory: `features/documentation-inventory.json`
- Feature matrix: `features/index.html`
- Screenshot directory: `features/screenshots/`

## 1) Public Access and Authentication

### Landing and Plan Selection
![Landing](./screenshots/01-landing-home.png)
![Pricing](./screenshots/02-pricing-plans.png)
- Open the landing page and review available plans.
- Select the plan that matches expected call volume and feature needs.

### Registration and Password Recovery
![Register Validation](./screenshots/03-register-validation.png)
![Forgot Password](./screenshots/04-forgot-password.png)
![Reset Password](./screenshots/05-reset-password.png)
- Complete registration fields and submit.
- Use forgot/reset password screens if account access is lost.

### Sign-In
![Login Validation](./screenshots/06-login-validation.png)
![Login Filled](./screenshots/07-login-filled.png)
- Enter email and password.
- Successful login redirects to onboarding or dashboard based on completion state.

## 2) Workspace Modules (Existing Features)

### Dashboard and Onboarding
![Dashboard Home](./screenshots/08-dashboard-home.png)
![Dashboard Hover State](./screenshots/09-dashboard-hover-state.png)
![Onboarding Checklist](./screenshots/10-onboarding-checklist.png)
- Track key metrics and pending setup steps.
- Complete onboarding checklist items before production usage.

### Calling Operations
![Dialer Console](./screenshots/11-dialer-console.png)
![Calls History](./screenshots/12-calls-history.png)
- Use Dialer for active call control actions.
- Use Calls History to review outcomes and retry logic.

### Growth Modules
![CRM Leads](./screenshots/13-crm-leads.png)
![Campaigns Overview](./screenshots/14-campaigns-overview.png)
- Manage lead records and CSV imports in Leads.
- Create/start/pause/stop campaigns from Campaigns.

### Insights and Admin
![Analytics Dashboard](./screenshots/15-analytics-dashboard.png)
![Analytics Roadmap Planned Features](./screenshots/16-analytics-roadmap-planned-features.png)
![Billing Center](./screenshots/17-billing-center.png)
![Team Management](./screenshots/18-team-management.png)
![Team Invite Form](./screenshots/19-team-invite-form.png)
![Tenant Settings](./screenshots/20-tenant-settings.png)
- Use Analytics for campaign, agent, trend, and roadmap telemetry.
- Use Billing for usage, invoices, and payment setup.
- Use Team for membership administration.
- Use Tenant for branding, locale, defaults, and profile settings.

### Integrations and Workspace Utilities
![Providers Config](./screenshots/21-providers-config.png)
![Webhooks Monitoring](./screenshots/22-webhooks-monitoring.png)
![API Tokens](./screenshots/23-api-tokens.png)
![Audit Logs](./screenshots/24-audit-logs.png)
![Notifications Center](./screenshots/25-notifications-center.png)
![Global Search](./screenshots/26-global-search.png)
![Help Center](./screenshots/27-help-center.png)
![Demo Workspace](./screenshots/28-demo-workspace.png)
- Configure telecom providers and failover routing.
- Monitor webhook deliveries and replay failed events.
- Create/revoke API tokens for service integrations.
- Use audit logs for compliance and change tracking.
- Process notifications and search globally across records.

## 3) Complete CRUD Workflow (Team Module)

This module currently provides the most direct full UI CRUD cycle:
- Create: invite member
- Read: team directory list
- Update: status change
- Delete: remove member

### Step A: Read (View Current Team Directory)
![Team CRUD Read](./screenshots/29-team-crud-read-directory.png)
- Open `Team` and verify the member table is loaded.

### Step B: Create (Invite a New Member)
![Team CRUD Create](./screenshots/30-team-crud-create-invite.png)
- Enter email.
- Select role.
- Click `Send Invite`.
- Confirm the invited member appears in the directory.

### Step C: Update (Modify Member Status)
![Team CRUD Update](./screenshots/31-team-crud-update-member-status.png)
- Change status (for example `Active` to `Disabled`).
- Click `Save`.

### Step D: Delete (Remove Member)
![Team CRUD Delete](./screenshots/32-team-crud-delete-member.png)
- Click `Remove` on the invited member row.
- Confirm the row is removed from the table.

## 4) Feature Completion and Partial Audit

Source of truth: `features/index.html` and route/UI code audit.

### Partial Features (Visible but not fully implemented)
- Top navigation search input UI: partial
- Topbar notification bell UI: partial

### Formerly Planned Features (Now Implemented)
- AI receptionist intent handling: implemented in API and telemetry.
- Microsoft Graph booking sync: implemented in API and persistence.
- WhatsApp messaging channel: implemented in webhook/API flows with opt-in enforcement.
- Workflow automation engine: implemented in workflow definition/run endpoints.
- Unified reporting layer: implemented in unified reporting endpoint with snapshots.
- Advanced governance controls: implemented in governance retention/drill endpoints.

### WhatsApp-Specific Findings
- WhatsApp API/webhook flow is implemented (`/api/webhooks/whatsapp/mock`, `/api/v1/inbox/whatsapp-opt-in`).
- No dedicated WhatsApp page exists in `apps/web/src/app`.
- Sidebar navigation has no WhatsApp entry.
- Current visibility in web UI is through roadmap telemetry in Analytics, not a standalone WhatsApp module.

## 5) Resolved Defect Notes

- Fixed broken screenshot references in `features/how-to-use.html` so images render with the current `01-28` naming scheme.
- Fixed Team invite role mismatch in `apps/web/src/app/team/page.tsx` by aligning UI roles with backend role slugs, enabling successful Team CRUD flow capture.

## 6) Troubleshooting

### Images Not Displaying
- Confirm image files exist under `features/screenshots/`.
- Verify `img src` names exactly match file names (for example `06-login-validation.png`).
- Hard refresh browser cache (`Ctrl+F5`).
- Open `features/how-to-use.html` from project root context so relative `./screenshots/...` links resolve correctly.

### Feature Missing from UI
- Confirm feature status in `features/index.html`:
  - `Implemented`: should be available
  - `Partial`: visible with limited behavior
- `Implemented` can be API/telemetry-first with limited dedicated page exposure.
- Verify user role permissions and tenant context (`wnd_token`, `wnd_tenant_id`).
- Check sidebar and route availability to confirm whether the feature has a rendered page.

### Team Invite/CRUD Issues
- Ensure a valid role slug is selected (`company_admin`, `billing_manager`, `developer_manager`, `operations_manager`, `support_analyst`).
- If invite appears to fail, check for API validation or duplicate-member conflicts.

### WhatsApp Accessibility Questions
- Current state is implemented at API/webhook level.
- Backend channel support can exist without a dedicated front-end module page.
- Absence from sidebar/pages is expected until a standalone WhatsApp workspace is added.

## 7) Company Campaign Setup Guide (Operational Runbook)

Use this workflow when a company tenant is preparing to run a production or pilot campaign.

### Prerequisites
- Company owner/admin account is available and can access `Team`, `Tenant Settings`, `Providers`, and `Campaigns`.
- At least one telecom provider account is ready (for example Twilio or SIP trunk credentials).
- Initial lead list is prepared as CSV with required fields (name, number, campaign metadata).

### Step 1: Sign In and Complete Workspace Basics
![Login Filled](./screenshots/07-login-filled.png)
![Onboarding Checklist](./screenshots/10-onboarding-checklist.png)
- Sign in using company admin credentials.
- Complete onboarding checklist items before launching outbound activity.
- Confirm dashboard loads without warnings.

### Step 2: Configure Company Profile and Defaults
![Tenant Settings](./screenshots/20-tenant-settings.png)
- Open `Tenant Settings`.
- Set organization identity (name, branding, timezone, locale).
- Save calling defaults and compliance settings used by campaigns.

### Step 3: Add Campaign Team and Permissions
![Team Management](./screenshots/18-team-management.png)
![Team Invite Form](./screenshots/19-team-invite-form.png)
- Open `Team`.
- Invite campaign operators (manager, caller, support roles as needed).
- Assign role-based access so only authorized users can edit billing/provider settings.

### Step 4: Configure Calling Provider and Reliability
![Providers Config](./screenshots/21-providers-config.png)
- Open `Providers`.
- Add provider credentials and validate connection state.
- Configure failover/priority order so calls continue if the primary route fails.

### Step 5: Import and Prepare Leads
![CRM Leads](./screenshots/13-crm-leads.png)
- Open `Lead Management`.
- Import CSV leads and verify field mapping.
- Remove duplicates, fix invalid numbers, and segment by campaign objective.

### Step 6: Create and Launch Campaign
![Campaigns Overview](./screenshots/14-campaigns-overview.png)
- Open `Campaigns`.
- Create a campaign (name, target list, dialing window, assigned team).
- Start with a small pilot batch first, then scale volume after quality checks.

### Step 7: Monitor Live Results and Optimize
![Dialer Console](./screenshots/11-dialer-console.png)
![Calls History](./screenshots/12-calls-history.png)
![Analytics Dashboard](./screenshots/15-analytics-dashboard.png)
- Monitor active sessions in `Dialer`.
- Track outcomes in `Calls History` (connected, failed, retry-required).
- Use `Analytics` to adjust pacing, scripts, and staffing.

### Step 8: Governance, Billing, and Ongoing Operations
![Billing Center](./screenshots/17-billing-center.png)
![Audit Logs](./screenshots/24-audit-logs.png)
![Notifications Center](./screenshots/25-notifications-center.png)
- Track spend and usage in `Billing`.
- Review `Audit Logs` for permission/config changes.
- Keep `Notifications` clear for delivery issues, provider failures, or campaign warnings.

### Recommended First-Week Operating Cadence
1. Day 1: Configure tenant/provider, import leads, and run pilot campaign.
2. Day 2-3: Tune scripts and retry logic from call outcomes.
3. Day 4-5: Scale campaign volume and monitor conversion/cost metrics.
4. Weekly: Audit permissions, billing usage, and failed webhook/provider events.

### Campaign Setup Troubleshooting

#### Campaign Does Not Start
- Verify provider status in `Providers`.
- Confirm campaign has assigned leads and active team members.
- Check for role permission limitations on the current user.

#### Low Connect Rate
- Validate dial windows and timezone alignment in tenant/campaign settings.
- Re-check lead number formatting and remove invalid entries.
- Use pilot analytics to refine lead segmentation and retry policy.

#### Team Cannot Access Campaign Controls
- Confirm users were invited with correct role slugs and accepted invites.
- Review `Team` status (active/disabled) and update as needed.

#### Cost Spikes or Unexpected Usage
- Check `Billing` usage trends and high-volume campaign windows.
- Reduce pacing temporarily and inspect campaign targeting quality.
