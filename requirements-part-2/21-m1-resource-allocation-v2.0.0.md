# M1 Resource Allocation - Auth/RBAC + Dialer Stabilization

Version: v2.0.0
Window: Week 2-4
Milestone: M1

## Capacity Plan
| Stream | Owner Team | Allocation | Scope |
|---|---|---:|---|
| Auth/Tenant hardening | Backend/API | 1.5 FTE | Auth flows, token/session controls, tenant context consistency |
| RBAC and audit controls | Backend/API + Security | 1.0 FTE | Permission matrix enforcement, audit visibility checks |
| Dialer core stability | Backend/Platform | 2.0 FTE | Call state transitions, webhook idempotency, retry controls |
| UI adjustments | Frontend | 1.5 FTE | Auth settings, team management, dialer console UX updates |
| Test automation | QA Automation | 1.5 FTE | Critical path smoke, contract tests, regression expansion |
| Release readiness | DevOps | 0.5 FTE | CI gate tuning, deployment rehearsal support |

## Weekly Deliverables
- Week 2:
  - Auth/RBAC edge-case fixes complete.
  - Dialer state-machine invariant tests in CI.
- Week 3:
  - Call controls reliability hardening complete.
  - Permission and audit UIs finalized for accessibility baseline.
- Week 4:
  - M1 regression suite pass.
  - Security review and milestone sign-off package prepared.

## Monitoring Metrics
- Defect escape rate (target: zero Sev-1).
- API p95 on priority dialer endpoints (target: <= 200 ms for standard load profiles).
- Critical smoke pass rate (target: 100% on PR).
- Coverage trend on touched modules (target: >= 90%).

## Dependencies and Risks
- Dependency: Git source-of-truth must be attached for mandatory branch/tag policy.
- Risk: Provider webhook payload variance causing transition drift.
  - Mitigation: broaden webhook fixture corpus and replay testing.
