# Edge WAF, DDoS, and Bot Controls

Last updated: 2026-04-12

## Control Baseline

- Enable managed WAF rulesets (OWASP + known CVE signatures).
- Enforce geo/IP reputation filtering for abusive sources.
- Enable L7 rate-based rules for auth and API-heavy paths.
- Enable bot management/challenge mode for suspicious automation.
- Enable DDoS protection profile at edge/load balancer tier.

## Minimum Rules

1. Block common injection payloads and protocol anomalies.
2. Challenge repeated failed auth patterns.
3. Rate-limit `/api/v1/auth/*` and webhook abuse attempts.
4. Restrict admin/ops endpoints by IP allowlist where possible.

## Validation Procedure

1. Run `ops/verify-edge-controls.ps1 -baseUrl <public_url>`.
2. Confirm headers and protective behavior are visible through edge.
3. Validate WAF events appear in provider logs/metrics.
4. Attach evidence screenshots/exports in change ticket.

## Evidence Checklist

- WAF policy ID and version.
- DDoS profile enabled screenshot/export.
- Bot policy configuration export.
- 24h event trend with blocked/challenged request counts.
