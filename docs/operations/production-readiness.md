# Production Readiness Program

Last updated: 2026-04-12

## Scope

This document defines enterprise production standards and verification controls for:

- Security hardening (OWASP-aligned controls, dependency scanning, rate limiting, secure headers).
- Reliability (structured error handling, health checks, request tracing).
- Quality gates (automated tests, coverage thresholds, CI policy).
- Operations (monitoring/alerting, incident response, backup/DR, zero-downtime rollout).
- Compliance and governance (data protection, retention, runbooks, auditability).

## Current Baseline

### Implemented in repository

- CI gate with 90% API coverage enforcement: `.github/workflows/regression-gate.yml`.
- Expanded production readiness pipeline:
  - `.github/workflows/production-readiness.yml`
  - API tests + coverage gate.
  - API dependency vulnerability scan (`composer audit`).
  - Web lint/build/critical E2E smoke.
  - Web dependency vulnerability scan (`npm audit`).
- API request correlation IDs and standardized error envelope:
  - `apps/api/app/Http/Middleware/AttachRequestId.php`
  - `apps/api/bootstrap/app.php`
- API security response headers middleware:
  - `apps/api/app/Http/Middleware/SecurityHeaders.php`
  - registered in API middleware pipeline.
- API rate limiting policies:
  - `apps/api/app/Providers/AppServiceProvider.php` (`auth`, `api` limiters).
  - `apps/api/routes/api.php` (`throttle:auth` for authentication endpoints).
- Operational liveness/readiness probes:
  - `GET /api/health/live`
  - `GET /api/health/ready`
  - implementation: `apps/api/app/Http/Controllers/Api/V1/OperationalHealthController.php`
- Web security hardening headers and strict mode:
  - `apps/web/next.config.ts`
- Web environment template:
  - `apps/web/.env.example`

### Requires infrastructure and platform access

- External penetration testing execution and signed assessor report.
- WAF / DDoS policies applied on the production edge perimeter.
- Centralized APM backend activation and dashboard/alert ownership assignment.
- Cross-region restore execution in cloud account with signed drill evidence.
- Auto-scaling policy tuning based on live staging/prod-like load execution.
- Zero-downtime deployment orchestration integration with cloud load balancer.

### Added execution assets (repository)

- Pen test + remediation attestation framework:
  - `docs/security/pentest-execution-and-attestation.md`
  - `.github/workflows/security-validation.yml` (OWASP ZAP baseline)
- Edge control validation:
  - `docs/security/waf-ddos-bot-controls.md`
  - `ops/verify-edge-controls.ps1`
- APM and error tracking wiring:
  - `apps/api/app/Http/Middleware/ApiRequestTelemetry.php`
  - `apps/api/config/logging.php` (`apm` channel)
  - `docs/operations/apm-error-tracking.md`
- Backup/DR drill automation and evidence:
  - `ops/run-dr-drill.ps1`
  - `docs/operations/backup-restore-dr-validation.md`
- Load test and autoscale verification:
  - `apps/api/tests/performance/part3-k6-autoscale.js`
  - `.github/workflows/load-test-autoscale.yml`
  - `docs/operations/load-test-autoscale-verification.md`

## Production Checklist

## 1) Security

- [x] Secure transport and response header hardening in app layer.
- [x] Auth endpoint rate limiting.
- [x] API/global limiter policy.
- [x] Dependency vulnerability scans in CI.
- [x] SAST/DAST tooling policy in CI (CodeQL + ZAP baseline workflow).
- [ ] Pen test performed with remediation closure evidence.
- [ ] WAF + bot mitigation + geo/IP policy configured at edge.

## 2) Reliability & Error Handling

- [x] Correlation ID (`X-Request-Id`) in API requests/responses.
- [x] Standardized API error envelope for unhandled API exceptions.
- [x] Liveness/readiness endpoints.
- [ ] Alerting thresholds and on-call escalation integrations.
- [ ] Chaos/failure-injection drills completed.

## 3) Performance & Scalability

- [x] Existing E2E and performance script foundations in web (`lighthouse:*` scripts).
- [ ] API query profiling and N+1 detection report baseline.
- [x] Load test suite (k6) with SLO pass criteria and CI artifact trend export.
- [ ] HPA/ASG policies and autoscale cooldown validation.

## 4) CI/CD & Release Safety

- [x] Regression quality gate and production readiness pipeline.
- [x] Coverage threshold gate for API tests (>=90%).
- [x] Blue/green traffic switch helper script in `ops/switch-traffic.ps1`.
- [ ] Protected environments with manual approvals and rollback hooks in deployment workflow.
- [ ] Signed artifacts and SBOM generation.

## 5) Compliance & Data Governance

- [x] API contracts maintained under `docs/api`.
- [ ] Data inventory and GDPR records of processing activities (ROPA).
- [ ] DSR workflows (access/export/delete) formalized and tested.
- [ ] Data retention/deletion jobs with legal-hold controls.
- [ ] DPIA for high-risk processing use cases.

## Exit Criteria

Production release is approved only when:

1. All `[ ]` controls marked critical by Security/Platform are converted to `[x]`.
2. Last 14-day CI signal is green for both regression and production-readiness pipelines.
3. Backup restore drill has passed within the last 30 days.
4. Load test meets p95 latency/error SLOs at target peak throughput.
5. Runbook signoff completed by Engineering, SRE/Platform, and Security.
