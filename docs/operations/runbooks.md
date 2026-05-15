# Operations Runbooks

Last updated: 2026-04-12

## 1) Incident Response

### Trigger Conditions

- API `5xx` error rate exceeds threshold.
- Elevated auth failures or suspicious traffic spikes.
- Database latency/availability degradation.
- Stripe/webhook delivery failures above error budget.

### Immediate Actions (0-15 minutes)

1. Declare incident severity and assign Incident Commander.
2. Capture current status from health endpoints:
   - `GET /up`
   - `GET /api/health/live`
   - `GET /api/health/ready`
3. Verify latest deployment and change window.
4. Enable mitigation path:
   - Feature flags OFF for unstable subsystems.
   - Throttle and edge protections elevated.
5. Publish first status update to stakeholders.

### Stabilization (15-60 minutes)

1. Roll back traffic using blue/green script:
   - `ops/switch-traffic.ps1 -target blue -instant`
2. Validate smoke checks (auth, tenant context, billing, dialer, webhook flows).
3. Confirm alert recovery trend.
4. Continue status updates every 15 minutes.

### Post-Incident

1. Publish root cause analysis (RCA) within 48 hours.
2. Add regression tests for discovered gap.
3. Track action items with owners and due dates.

## 2) Backup and Disaster Recovery

### Backup Policy

- Database snapshots: hourly incremental, daily full, encrypted at rest.
- Retention: daily (35 days), weekly (12 weeks), monthly (12 months).
- Offsite copy: cross-region replication enabled.

### Restore Drill Procedure

1. Select recovery point objective (RPO) target.
2. Restore latest snapshot to isolated staging environment.
3. Run data integrity checks:
   - tenant count parity
   - active subscriptions parity
   - latest call/message sequence validation
4. Execute critical business smoke tests.
5. Record recovery time objective (RTO) and variances.

### Success Criteria

- Restore integrity checks pass.
- RTO <= target.
- No data corruption or cross-tenant leakage.

## 3) Zero-Downtime Deployment

### Preconditions

- Production readiness workflow green.
- Regression gate green.
- Migration strategy validated (backward-compatible first).

### Deployment Sequence

1. Deploy to inactive color (blue/green).
2. Run warm-up + smoke tests on inactive color.
3. Shift canary traffic.
4. Monitor errors/latency for 10-15 minutes.
5. Complete full traffic switch.
6. Keep previous color hot until rollback window expires.

### Rollback

1. Execute traffic switch to stable color immediately.
2. Disable problematic feature flags.
3. Communicate rollback decision and customer impact.
4. Open incident and capture forensic evidence.
