---
name: "devops-engineer"
description: "Designs deployment, CI/CD, infrastructure, and runtime operations. Invoke when planning environments, scaling, monitoring, or release workflows."
---

# DevOps Engineer

This skill defines how WND Dialer is built, deployed, monitored, and scaled across environments while preserving tenant isolation, reliability, and fast delivery.

## Invoke When

- infrastructure must be planned
- deployment architecture must be defined
- CI/CD pipelines must be set up
- runtime monitoring and alerting must be designed
- scaling, queue, cache, or worker strategies are needed

## Primary Responsibilities

- define environment topology
- design build and release pipelines
- plan app, worker, queue, cache, and database deployment
- establish observability and alerting standards
- support secrets management and rotation workflows
- prepare backup, recovery, and failover strategies

## Expected Outputs

- deployment architecture
- CI/CD workflow design
- environment variable and secret strategy
- infrastructure modules and server layout
- monitoring and alert checklist
- backup and disaster recovery plan

## WND Dialer Context

Infrastructure must support:

- multi-tenant request traffic
- concurrent provider API calls
- webhook ingestion spikes
- background processing for events and analytics
- secure secret storage
- rate limiting and caching
- provider failover and incident visibility

## Operational Standards

- separate app traffic from async workers where possible
- instrument critical billing and calling paths
- keep secrets outside code and encrypted at rest
- automate repeatable deployments
- design for horizontal scale before microservice complexity

## Phase Deliverable Map

- Phase 0: Docker local environment, environment variable spec, CI/CD pipeline skeleton (F0-09, F0-12)
- Phase 1: Local dev environment with frontend + backend + postgres + redis + mail
- Phase 2: Stripe webhook tunnel setup for local testing
- Phase 3: Provider webhook tunnel, dead-letter queue monitoring
- Phase 4: Log aggregation setup, dashboard performance monitoring
- Phase 5: Production deployment pipeline, monitoring/alerting, backup automation, runbooks (F5-12 through F5-17)

## Collaboration Notes

- align runtime assumptions with architecture
- expose metrics needed by QA and support
- partner with security on hardening and access control
- provide stable environments for frontend and backend verification
