# Risk and Escalation Register

Version: v2.0.0
Program: requirements-part-2-delivery
Last-Updated: 2026-04-12

## Risk Scale
- Probability: Low / Medium / High
- Impact: Low / Medium / High / Critical
- Severity: `S = Probability x Impact`

## Active Items
| ID | Risk | Probability | Impact | Severity | Owner | Mitigation | Escalation Trigger | Status |
|---|---|---|---|---|---|---|---|---|
| R-001 | Git repository unavailable in execution workspace | High | High | Severe | DevOps Lead | Initialize or attach canonical Git repo and validate branch/tag workflow | Not resolved within 1 business day | Open |
| R-002 | Regression from API version negotiation rollout | Medium | High | High | API Lead | Add contract tests and fallback behavior for default Accept values | Any 4xx spike after rollout | Open |
| R-003 | Feature-flag drift between environments | Medium | High | High | Release Manager | Add environment snapshot validation in CI and release checklist | Flag mismatch detected pre-release | Mitigated (Monitoring) |
| R-004 | Coverage gap below 90% in fast-delivery streams | Medium | Medium | Medium | QA Lead | Enforce coverage thresholds in PR checks and block merge on failure | Coverage below threshold in CI | Mitigated (Monitoring) |
| R-005 | Tooling mismatch (requested Cypress vs current Playwright stack) | Medium | Medium | Medium | QA Lead | Maintain critical-path parity in Playwright and log controlled deviation | Stakeholder requests strict Cypress migration | Open |

## Escalation Log
| DateTime (UTC) | Item ID | Raised By | Escalated To | Reason | Action | Resolution ETA |
|---|---|---|---|---|---|---|
| 2026-04-12T00:30:00Z | R-001 | Execution Agent | Engineering Lead, DevOps Lead | Mandatory branch/tag controls blocked | Request repository hookup and rerun governance steps | 2026-04-12T12:00:00Z |
| 2026-04-12T01:35:00Z | R-005 | Execution Agent | QA Lead, Product Owner | Requested framework differs from existing stack | Logged controlled deviation with critical-path parity guarantee | 2026-04-13T12:00:00Z |
| 2026-04-12T02:05:00Z | R-003 | Execution Agent | Release Manager, QA Lead | Environment drift risk required hard gate | Added CI snapshot validation script and workflow step | Closed to monitoring |
| 2026-04-12T02:10:00Z | R-004 | Execution Agent | QA Lead, Engineering Lead | Coverage risk required merge-blocking guard | Added `--coverage --min=90` in PR gate workflow | Closed to monitoring |
