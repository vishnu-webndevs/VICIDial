# AI E2E Scenario Matrix

Generated at: 2026-04-10T11:29:33.251Z
Coverage target: 90%
Critical runtime target: < 5 minutes

| ID | Story | Module | Route | Priority | Risk | Critical | Browsers |
| --- | --- | --- | --- | --- | ---: | --- | --- |
| E2E-BILLING-001 | US-BILLING-001 | billing | /billing | P0 | 82 | Yes | chromium, firefox, webkit |
| E2E-BILLING-002 | US-BILLING-001 | billing | /billing | P0 | 82 | Yes | chromium, firefox, webkit |
| E2E-BILLING-003 | US-BILLING-001 | billing | /billing | P0 | 82 | Yes | chromium, firefox, webkit |

## Scenario Details

### E2E-BILLING-001 - Owner attaches payment method securely: Owner can create a setup intent
- Story: `US-BILLING-001`
- Priority: `P0` (risk score: 82)
- Route: `/billing`
- Steps:
  - Authenticate as company_owner
  - Navigate to /billing
  - Execute action: Owner can create a setup intent
- Expected outcome: Owner can create a setup intent

### E2E-BILLING-002 - Owner attaches payment method securely: Owner can attach a card through Stripe Elements
- Story: `US-BILLING-001`
- Priority: `P0` (risk score: 82)
- Route: `/billing`
- Steps:
  - Authenticate as company_owner
  - Navigate to /billing
  - Execute action: Owner can attach a card through Stripe Elements
- Expected outcome: Owner can attach a card through Stripe Elements

### E2E-BILLING-003 - Owner attaches payment method securely: Errors are shown for failed attachment
- Story: `US-BILLING-001`
- Priority: `P0` (risk score: 82)
- Route: `/billing`
- Steps:
  - Authenticate as company_owner
  - Navigate to /billing
  - Execute action: Errors are shown for failed attachment
- Expected outcome: Errors are shown for failed attachment

