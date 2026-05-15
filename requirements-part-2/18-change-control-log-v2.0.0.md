# Change Control Log

Version: v2.0.0
Program: requirements-part-2-delivery
Last-Updated: 2026-04-12

## Process
- Every scope/process/implementation change must include reason, impact assessment, approvals, and rollback note.
- No high-impact change is applied without Product + Engineering approval and QA visibility.

## Change Entries
| Change ID | Date | Area | Description | Reason | Impact | Approval | Rollback/Backout |
|---|---|---|---|---|---|---|---|
| C-001 | 2026-04-12 | API Platform | Added runtime API version negotiation middleware (`Accept`-aware; defaults to v1) | Enforce v1->v2 compatibility guardrail from roadmap | Low functional impact, high governance value | Engineering Lead | Disable middleware alias and route middleware binding |
| C-002 | 2026-04-12 | API Platform | Added centralized feature flags configuration and flag service scaffold | Required for runtime controlled rollout and kill-switch readiness | Low immediate impact, enables phased rollout | Engineering Lead + Product Owner | Set all flags to `false` and redeploy |
| C-003 | 2026-04-12 | Program Governance | Added execution control center, risk register, stakeholder report, change log artifacts | Enforce schedule/quality/risk management discipline | Process impact only, improves traceability | Product Owner + PM + Engineering Lead | N/A |
| C-004 | 2026-04-12 | DevOps/Release | Added PR regression gate workflow (`.github/workflows/regression-gate.yml`) | Enforce must-pass CI checks before merge | High quality control impact, low runtime impact | Engineering Lead + QA Lead | Remove workflow requirement from branch policy |
| C-005 | 2026-04-12 | Operations | Added one-command blue/green switch script and rollback playbook | Operationalize instant rollback command in roadmap | High incident response value | DevOps Lead + Engineering Lead | Use prior manual LB runbook |
| C-006 | 2026-04-12 | API Documentation | Added initial OpenAPI and Postman artifacts for v1/v2 contracts (`docs/api`) | Deliver required contract artifacts and stakeholder validation support | Medium documentation impact, low runtime impact | Engineering Lead + Product Owner | Revert docs/api artifacts |
| C-007 | 2026-04-12 | Architecture Governance | Added ADR set for versioning, feature flags, and blue-green rollback (`docs/adr`) | Trace architectural decisions for auditability and alignment | Process impact only | Engineering Lead + Security Lead | Revert ADR files |
| C-008 | 2026-04-12 | QA Governance | Logged Playwright/Cypress controlled deviation with critical-path parity commitments | Maintain delivery momentum with existing stack while preserving scope coverage | Medium process impact | QA Lead + Product Owner | Approve Cypress migration plan and supersede deviation |
| C-009 | 2026-04-12 | Release Quality Gate | Added feature-flag snapshot validation script and CI step (`apps/api/scripts/validate-feature-flags.php`) | Prevent environment flag drift before merge/release | High governance impact, low runtime impact | Release Manager + QA Lead | Remove validation step from workflow |
| C-010 | 2026-04-12 | Release Quality Gate | Enforced API coverage threshold in PR workflow (`php artisan test --coverage --min=90`) | Guarantee minimum line coverage policy in merge gate | High quality impact, medium CI runtime impact | QA Lead + Engineering Lead | Revert workflow test command |
