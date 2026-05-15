# WND Dialer Role Skills

This folder contains reusable role-based skills for the WND Dialer project.

## Available Skills (12 Total)

### Core Engineering
- **system-architect** — Architecture, stack, data models, APIs, module boundaries
- **backend-developer** — APIs, validation, auth, integrations, provider adapters
- **frontend-developer** — Responsive UI, dashboards, forms, component library
- **database-engineer** — Schemas, migrations, indexes, query optimization, backups

### Design and Product
- **ui-ux-designer** — User flows, page structure, reusable UI patterns
- **product-manager** — Feature scope, user stories, prioritization, acceptance criteria

### Quality and Security
- **security-expert** — Auth, secrets, tenant isolation, compliance, threat review
- **qa-tester** — Test strategy, scenarios, edge cases, provider integration testing

### Operations
- **devops-engineer** — Deployment, CI/CD, infrastructure, monitoring, scaling

### AI and Automation
- **ai-automation-engineer** — Internal operations automation, workflow efficiency (V1)
- **ai-product-engineer** — AI product features: transcription, scoring, bots, analytics (V2)

### Documentation
- **technical-writer** — API docs, integration guides, setup instructions, decision records

## Skill Invocation Sequence Per Feature

1. Product Manager — defines requirements and acceptance criteria
2. System Architect — defines module shape, API contract, data model
3. UI/UX Designer — defines flow and screen structure (if UI exists)
4. Database Engineer — designs schema and migrations
5. Backend Developer — implements server contracts
6. Frontend Developer — implements interface
7. Security Expert — reviews sensitive areas (can block merge)
8. QA Tester — validates acceptance and edge cases
9. DevOps Engineer — checks observability and deployment impact
10. AI Automation Engineer — suggests operational automation (if applicable)

## Handoff Protocol

Each skill passes explicit artifacts to the next:

| From | To | Artifact |
|---|---|---|
| Product Manager | All | User stories + acceptance criteria + priority |
| System Architect | Backend | Module spec + API contract + data model |
| System Architect | Frontend | Page map + API contract + permission matrix |
| System Architect | Database Engineer | Entity relationships + tenant rules + volume estimates |
| System Architect | DevOps | Infrastructure requirements + queue definitions |
| Backend Developer | Frontend | Typed API contracts + error codes |
| Backend Developer | QA | Testable endpoints + fixtures |
| Backend Developer | Security | Auth flows + secret handling + webhook validation |
| Security Expert | All | Approved/blocked findings (blocks override all) |
| QA Tester | All | Test results + regression status |

## Conflict Resolution

1. Security blocks override all other approvals
2. QA-found design flaws escalate to System Architect
3. API contract changes require Architect approval + Frontend/Backend agreement
4. Scope additions require Product Manager approval
5. Unresolved disputes escalate: Security > Architect > Product Manager
