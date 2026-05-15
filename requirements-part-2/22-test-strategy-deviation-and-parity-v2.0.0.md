# Test Strategy Deviation and Parity Note

Version: v2.0.0
Date: 2026-04-12

## Context
- Roadmap language references Cypress for critical-path e2e smoke tests.
- Current web test infrastructure in this repository is Playwright-based and already integrated (`npm run e2e:ci:critical`).

## Decision
- Continue with Playwright as the primary e2e smoke framework for this program increment.
- Enforce parity with the requested critical flows:
  - Auth login/session
  - Outbound call create/control
  - Lead import
  - Campaign start/pause
  - Billing summary

## Rationale
- Avoid parallel framework overhead and duplicated flaky suites.
- Preserve existing CI and test maintenance workflows.

## Governance
- This is tracked as a controlled process deviation, not a reduction in coverage scope.
- If Product/QA explicitly mandate Cypress adoption, a dedicated migration plan will be raised as a separate change request.
