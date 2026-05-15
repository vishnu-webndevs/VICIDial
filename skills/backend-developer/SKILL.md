---
name: "backend-developer"
description: "Builds production-ready backend APIs, validation, auth, and integrations. Invoke when implementing server logic, webhooks, billing, or provider flows."
---

# Backend Developer

This skill implements the server-side application for WND Dialer with clean architecture, tenant-safe data access, provider abstractions, and production-ready API behavior.

## Invoke When

- REST APIs must be implemented
- database models and business rules must be coded
- authentication and authorization must be enforced
- webhooks, usage tracking, or billing logic must be added
- provider integrations or failover logic must be built

## Primary Responsibilities

- write clean backend modules and APIs
- implement request validation and standardized errors
- enforce tenant scoping in every query and mutation
- implement provider adapter interfaces
- build webhook ingestion and event normalization
- support usage metering, billing, and rate limiting
- add tests for core business flows

## Expected Outputs

- controllers or route handlers
- service layer logic
- schema and model definitions
- validation rules
- auth and permission middleware
- webhook handlers
- API tests and edge-case coverage

## WND Dialer Context

Backend work must support:

- multi-tenant company isolation
- provider-specific credential storage
- subscription packages and feature gates
- per-tenant API rate limiting
- call initiation, tracking, and history
- webhook processing for real-time call events
- provider failover policies

## Coding Standards

- follow existing repository conventions first
- keep handlers thin and business logic in services
- never expose secrets in logs or responses
- validate all external payloads
- make APIs idempotent where retries are possible
- prefer explicit contracts over hidden side effects

## Phase Deliverable Map

- Phase 0: API contract spec, webhook event schema, error code catalog (F0-05, F0-06, F0-08)
- Phase 1: Auth controllers, RBAC middleware, tenant scoping, invitation system
- Phase 2: Stripe integration, subscription management, metering engine, rate limiting
- Phase 3: Provider adapters, call orchestration, webhook handlers, failover logic
- Phase 4: Analytics API endpoints, export jobs, call history queries
- Phase 5: Public API endpoints, token auth, idempotency, API documentation support

## Handoff Artifacts

- To Frontend: typed API contracts (request/response schemas) + error codes + WebSocket events
- To DevOps: queue job catalog + worker requirements + environment variables
- To Security: auth flows + secret handling code + webhook validation logic
- To QA: testable endpoints + fixture data + deterministic scenarios
- To Database Engineer: query patterns + migration requirements

## Collaboration Notes

- give frontend stable API contracts
- provide observability hooks for DevOps
- tag security-sensitive changes for review
- expose deterministic scenarios for QA automation
