# Agent Task Assignments - Requirements Part 2

Version: v2.0.0
Last-Updated: 2026-04-12
Shared State File: `agents/shared-state-v2.0.0.json`

## Agent Utilization Note
- Current `/agents` directory initially contained only `.gitkeep`.
- This assignment matrix defines executable agent roles for the delivery pipeline.

## Agent Role Matrix
| Agent ID | Role | Primary Scope | Inputs | Outputs |
|---|---|---|---|---|
| AGENT-01 | Requirements Parser | Parse all `requirements-part-2/*.md` into structured backlog | All requirement markdown files | Ranked requirement JSON blocks in shared state |
| AGENT-02 | API/Contract Engineer | REST v1/v2 contracts, DTOs, status/error envelope consistency | API spec + roadmap | OpenAPI updates, contract test cases |
| AGENT-03 | Data Engineer | Migration forward/rollback scripts and schema compatibility | DB schema + roadmap | SQL migration plans and rollback scripts |
| AGENT-04 | Backend Engineer | Service-level implementation planning and feature-flag integration | Backlog + contracts + migrations | Implementation tasks by service |
| AGENT-05 | Frontend Engineer | UI/UX deliverables, responsive behavior, accessibility | Roadmap + feature specs | Wireframe task list, a11y acceptance checks |
| AGENT-06 | QA Engineer | Unit/integration/e2e coverage strategy and CI gates | Roadmap + contracts | Test plan with >=90% unit coverage targets |
| AGENT-07 | Security Engineer | OWASP controls, auth/session hardening, rate limits | Roadmap + architecture | Security checklist, threat model deltas |
| AGENT-08 | DevOps Engineer | Branch/tag strategy, blue-green rollout, rollback command | Roadmap + release policy | CI/CD pipeline tasks and rollback playbook |
| AGENT-09 | Documentation Engineer | Swagger/Postman/README/ADR/checklists | All outputs | Updated docs and sign-off checklist template |

## Cross-Agent Workflow
1. AGENT-01 writes normalized requirement objects into shared state.
2. AGENT-02 and AGENT-03 enrich each requirement with contract and migration plans.
3. AGENT-04 and AGENT-05 define implementation and UX work items tied to feature flags.
4. AGENT-06 and AGENT-07 attach test/security controls and gate criteria.
5. AGENT-08 appends rollout/rollback/CI orchestration.
6. AGENT-09 compiles final delivery package and sign-off artifacts.

## Required Synchronization Rules
- No agent may mark a requirement as `ready_for_release` unless:
  - Feature flag key exists and kill-switch behavior is documented.
  - API version compatibility (`v1` and `v2`) is validated.
  - Test coverage target and security checklist are both attached.
- Shared state must be updated atomically per requirement ID.
