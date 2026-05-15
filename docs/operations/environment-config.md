# Environment Configuration Matrix

Last updated: 2026-04-12

## Environments

- `local`: developer workstation, mock integrations enabled by default.
- `staging`: production-like validation, full CI/CD gates, synthetic monitoring.
- `production`: customer traffic, strict security controls, audited changes only.

## Required Configuration Baseline

## API (`apps/api`)

- `APP_ENV`: `staging` or `production` (never `local` in shared envs).
- `APP_DEBUG`: `false` in staging/production.
- `APP_URL`: public HTTPS base URL.
- `LOG_LEVEL`: `info` (prod baseline), elevate to `warning` for cost control if needed.
- `LOG_STACK`: include `stderr` for container-native log shipping.
- `CORS_ALLOWED_ORIGINS`: strict allowlist per environment.
- `PART3_INTEGRATIONS_ENABLED`: enabled only when credentials and endpoints are validated.
- `FF_*`: feature flags tracked via change-management record.

## Web (`apps/web`)

- `NEXT_PUBLIC_API_BASE_URL`: environment-specific API URL.
- `NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY`: per environment key.
- `NODE_ENV`: `production` for production runtime.

## Security Rules

- No plaintext secrets in repository, logs, or issue trackers.
- Use environment secret manager (GitHub Environments, Vault, AWS SM, Azure KV, GCP Secret Manager).
- Rotate credentials quarterly or immediately after suspected exposure.

## Promotion Rules

1. Local -> Staging:
   - PR merged with green regression and readiness workflows.
2. Staging -> Production:
   - Manual approval by Engineering + Platform/SRE.
   - Change ticket includes rollback plan and blast-radius assessment.
3. Production rollback:
   - Use `ops/switch-traffic.ps1` and feature-flag kill switch where applicable.
