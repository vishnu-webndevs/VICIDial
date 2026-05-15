# Backup/Restore and Cross-Region DR Validation

Last updated: 2026-04-12

## Drill Frequency

- Backup integrity check: daily automated
- Full restore drill: monthly
- Cross-region failover drill: quarterly

## Execution

1. Restore latest encrypted backup into isolated recovery environment.
2. Run liveness/readiness checks.
3. Run domain data integrity checks.
4. Validate cross-region restore path and DNS/LB failover mechanics.
5. Record RPO and RTO metrics.

## Automation Helper

- Run:
  - `ops/run-dr-drill.ps1 -environment <staging|prod-sim> -backupIdentifier <id> -restoredEndpoint <https://restored-host>`
- The script creates an evidence file in:
  - `docs/operations/evidence/`

## Required Evidence

- Backup snapshot ID and encryption key reference.
- Restore logs and command output.
- Data integrity comparison report.
- Cross-region network and failover proof.
- Signed approvals from SRE + Security + Engineering.

## Exit Criteria

- RPO within policy target.
- RTO within policy target.
- Zero integrity mismatches above accepted threshold.
