# Monitoring and Alerting Baseline

Last updated: 2026-04-12

## Objectives

- Detect incidents before customer impact exceeds SLA/SLO.
- Correlate events across web, API, database, queues, and integrations.
- Ensure actionable alerts with clear ownership and escalation policy.

## Minimum Signals

## Application

- API request rate, p95 latency, error rate (4xx/5xx split).
- Auth/login failure rate and throttle events.
- Web Core Web Vitals trend and frontend error volume.
- Webhook processing success/failure and retry backlog.

## Infrastructure

- CPU, memory, disk, network utilization.
- DB connections, slow query count, replication lag (if applicable).
- Cache availability and hit ratio.
- Queue depth and worker processing latency.

## Alert Policy

- `P1` immediate page:
  - API availability < 99% over 5 min.
  - 5xx rate > 5% over 5 min.
  - DB unreachable/readiness down > 2 min.
- `P2` high urgency:
  - p95 latency > SLO threshold for 15 min.
  - Payment or webhook failures above error budget.
- `P3` backlog:
  - Capacity/utilization trend nearing threshold.

## Implementation Guidance

- Export structured logs with `X-Request-Id` for correlation.
- Integrate APM + error tracking in both `apps/web` and `apps/api`.
- Persist dashboards for:
  - Availability
  - Latency
  - Error budget burn
  - Integration health

## Validation

1. Synthetic checks every minute for `/up`, `/api/health/live`, `/api/health/ready`.
2. Weekly alert-fire drill for at least one P1 and one P2 scenario.
3. Monthly review: false-positive/false-negative tuning and threshold updates.
