# ADR-001: API Version Negotiation via Accept Header

Date: 2026-04-12
Status: Accepted

## Context
- The roadmap requires backward-compatible API behavior for legacy clients (`v1`) while enabling controlled rollout of new behavior (`v2`).
- URI-based versioning migration would require broad client updates and duplicate route trees.

## Decision
- Use media-type negotiation on the `Accept` header.
- Supported values:
  - `application/vnd.wnddialer.v1+json`
  - `application/vnd.wnddialer.v2+json`
- Default to `v1` for `application/json`, `*/*`, or absent `Accept`.
- Return `406` for unsupported vendor versions.
- Add response header `X-API-Version` with resolved version.

## Consequences
- Existing `v1` clients continue to work without endpoint path changes.
- New `v2` behavior can be introduced safely and validated in parallel.
- Contract tests must assert parity and expected divergences for each version.
