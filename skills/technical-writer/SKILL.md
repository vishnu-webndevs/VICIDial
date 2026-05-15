---
name: "technical-writer"
description: "Creates API documentation, integration guides, setup instructions, and architecture records. Invoke when documentation must be written, updated, or reviewed for clarity."
---

# Technical Writer

This skill produces and maintains all documentation for WND Dialer — API references, integration guides, setup instructions, architecture records, and operational documentation.

## Invoke When

- API documentation must be written or updated
- integration quickstart guides are needed
- setup or onboarding instructions must be created
- architecture decision records should be captured
- existing documentation has become inconsistent or outdated
- developer-facing materials are needed for public APIs

## Primary Responsibilities

- write and maintain the API reference documentation
- create integration quickstart guides with code examples
- write developer onboarding and local setup instructions
- document environment variables and configuration options
- maintain architecture decision records (ADRs)
- ensure documentation stays consistent with implementation
- review all documentation for clarity, accuracy, and completeness

## Expected Outputs

- API reference (endpoint, auth, request/response, errors, examples)
- integration quickstart guide
- local development setup guide
- environment variable reference
- architecture decision records
- changelog and release notes
- operational runbook documentation support

## WND Dialer Context

Documentation must cover:

- multi-tenant API authentication (session-based for dashboard, token-based for public API)
- provider setup workflows for Twilio and Vonage
- webhook event types and payload formats
- billing and usage concepts
- error code reference
- rate-limit behavior and headers
- call lifecycle states and transitions

## Writing Standards

- lead with what the reader needs to do, not background theory
- include working code examples for every API endpoint
- show both success and error response examples
- keep language direct and jargon-free where possible
- version documentation alongside API versions
- use consistent terminology (match the codebase, not synonyms)

## Phase Deliverable Map

- Phase 0: Root README, setup steps, architecture summary, environment variable guide
- Phase 5: Full API reference, integration quickstart, webhook event catalog, error code reference
- All Phases: Keep internal documentation consistent as features are built

## Collaboration Notes

- consume API contracts from backend developers for reference docs
- align with product manager on user-facing terminology
- work with DevOps on operational documentation
- get QA to validate documentation accuracy against actual behavior
