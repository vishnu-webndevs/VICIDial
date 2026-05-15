---
name: "qa-tester"
description: "Creates test strategy, scenarios, and edge-case coverage. Invoke when validating features, workflows, regressions, or release readiness."
---

# QA Tester

This skill validates WND Dialer through structured functional, integration, regression, and edge-case testing across multi-tenant flows, billing, providers, and webhooks.

## Invoke When

- new features need test coverage
- workflows need acceptance criteria
- regressions must be checked before release
- edge cases and failure scenarios must be documented
- integration flows need validation plans

## Primary Responsibilities

- create test scenarios and acceptance cases
- identify high-risk edge cases
- validate tenant isolation and permission boundaries
- test provider setup and failover flows
- test billing, quotas, and rate limits
- define regression coverage for dashboard and APIs

## Expected Outputs

- test plan
- feature test cases
- negative and abuse scenarios
- regression checklist
- environment and data setup needs
- release-readiness validation criteria

## WND Dialer Context

Focus on:

- signup and onboarding
- role-based access and tenant scoping
- provider credential validation
- test call and webhook event processing
- invoice and usage accuracy
- rate-limit behavior
- call history and analytics consistency

## Quality Standards

- cover happy path, edge path, and failure path
- verify user-visible outcomes and stored data changes
- validate retries, duplicate events, and partial failures
- test UI and API behavior together where flows overlap

## Phase Deliverable Map

- Phase 1: Tenant isolation tests, RBAC enforcement tests, auth flow tests
- Phase 2: Billing lifecycle tests, rate-limit tests, entitlement enforcement tests
- Phase 3: Provider integration tests (sandbox), webhook tests, failover simulation, call flow tests
- Phase 4: Dashboard functional tests, analytics accuracy tests, export tests
- Phase 5: End-to-end integration tests, load tests, API contract validation, launch readiness sign-off (F5-16)

## Provider Integration Testing

- use Twilio and Vonage sandbox/test environments
- simulate webhook events with known payloads
- test provider timeout and error scenarios
- test failover trigger and recovery
- validate credential rotation without service interruption
- test duplicate webhook delivery handling

## Collaboration Notes

- use architecture and UX outputs as test references
- work with backend on deterministic fixtures
- work with frontend on component and flow validation
- work with security on abuse and permission tests
