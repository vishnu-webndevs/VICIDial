# Live APM and Error Tracking Wiring

Last updated: 2026-04-12

## Implemented in App Layer

- API emits per-request telemetry via `api.request` log events:
  - request ID
  - path/method/status
  - duration in ms
  - tenant/user context
- Response includes `X-Response-Time-Ms`.
- Error envelopes include `meta.request_id` for trace correlation.

## Files

- `apps/api/app/Http/Middleware/ApiRequestTelemetry.php`
- `apps/api/config/logging.php` (`apm` channel)
- `apps/api/bootstrap/app.php` (middleware registration)

## Platform Wiring Steps

1. Select collector (Datadog/New Relic/Sentry/OpenTelemetry gateway).
2. Configure `LOG_APM_STACK` to ship telemetry to collector-supported sink.
3. Create dashboards:
   - p95 latency by path
   - error rate by path/status
   - auth failure and throttle trends
4. Create alerts for thresholds in `docs/operations/monitoring-alerting.md`.

## Evidence Required

- Dashboard URL(s)
- Alert policy export/screenshots
- 24h trace/log sample demonstrating request-id correlation
