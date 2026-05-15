# TestNG Documentation Framework for AI-Generated Playwright E2E

## 1) Purpose
This framework defines how to design, generate, execute, and maintain end-to-end (E2E) tests using:
- AI-analyzed user stories as the test source.
- A generated E2E Scenario Matrix as the planning artifact.
- Playwright as the execution engine.

The target outcomes are:
- `>= 90%` coverage for critical user-flow requirements.
- `< 5 minutes` execution time for critical-flow CI smoke suites.
- Repeatable cross-browser validation and report artifacts.

## 2) Repository Layout
- `docs/testing/README.md`: master framework document (this file).
- `docs/testing/templates/user-story-intake.json`: AI input template.
- `docs/testing/templates/risk-weights.json`: risk model configuration.
- `docs/testing/templates/scenario-matrix.schema.json`: matrix JSON schema.
- `scripts/generate-e2e-matrix.mjs`: generates prioritized matrix from user stories.
- `scripts/generate-playwright-specs.mjs`: scaffolds spec files from matrix.
- `e2e/generated/scenario-matrix.json`: generated matrix output.
- `e2e/generated/scenario-matrix.md`: generated human-readable matrix.

## 3) Test Environment Setup
### Required toolchain
- Node.js `>= 20`
- npm `>= 10`
- Playwright browsers installed via `npx playwright install`

### Baseline setup
```bash
npm install
npx playwright install
```

### Runtime environment variables
- `E2E_BASE_URL`: web app URL (default: `http://localhost:3000`)
- `E2E_API_BASE_URL`: API URL (default expected by existing suites)
- `E2E_CROSS_BROWSER`: `true|false` to enable Chromium/Firefox/WebKit projects
- `E2E_WORKERS`: integer worker override for parallel runs
- `E2E_HEADED`: `true|false` for headed/headless mode
- `E2E_FULLY_PARALLEL`: `true|false` for suite-level parallelization

## 4) Dependency Management
- Keep `@playwright/test` pinned in `devDependencies`.
- Upgrade cadence: monthly minor upgrades, immediate patch upgrades for CVEs.
- Use lockfile-based installs in CI: `npm ci`.
- Validate browser install cache in CI to avoid cold-start delays.

## 5) AI Scenario Matrix Generation
### Input contract
- User stories are provided in JSON format matching `user-story-intake.json`.
- Each story includes:
  - business value
  - acceptance criteria
  - impacted routes/modules
  - risk signals (security, revenue, compliance, user impact)

### Generation algorithm
1. Parse stories and acceptance criteria.
2. Expand each criterion into candidate scenarios.
3. Assign risk score using weighted model:
   - business criticality
   - change frequency
   - user impact
   - security/compliance impact
   - historical defect density
4. Deduplicate by normalized route + action + expected outcome.
5. Compute priority tier:
   - `P0`: critical smoke
   - `P1`: high value regression
   - `P2`: extended coverage
6. Emit:
   - `scenario-matrix.json` for automation
   - `scenario-matrix.md` for review

### Prioritization rule
- Critical flow if `risk_score >= 80` OR tagged with `auth|billing|campaign-execution|webhook`.
- CI smoke must execute all critical flows under 5 minutes.

## 6) Playwright Integration Protocol
### Matrix-to-test mapping
- One scenario maps to one test case ID.
- IDs follow: `E2E-{module}-{sequence}`.
- Generated specs include metadata comments:
  - source story ID
  - risk score
  - priority
  - owner

### Script generation workflow
```bash
npm run e2e:matrix
npm run e2e:scaffold
```

### Selector strategy
- Prefer role/text selectors for stability.
- Use test IDs only for unstable dynamic UI areas.
- Keep selectors in helper/page-object functions for maintainability.

## 7) Test Data Management
### Data model
- Synthetic tenant-scoped test data only.
- Unique run IDs in emails/entities to avoid collisions.
- Deterministic fixtures for import and visual tests.

### Lifecycle
1. Seed or bootstrap via API where safe.
2. Perform UI flow.
3. Cleanup tenant-scoped records when contract supports deletion.

### Isolation policy
- No shared mutable global fixtures across parallel workers.
- Worker-level namespace key: `${RUN_ID}-${WORKER_ID}`.

## 8) Parallel Execution Strategy
- Default local mode: conservative workers for debugging.
- CI mode:
  - enable parallel workers via `E2E_WORKERS`.
  - split suites by priority tags (`@p0`, `@p1`, `@p2`).
- Critical-flow profile:
  - run only `@p0` and must stay under 5 minutes.

## 9) Cross-Browser Validation
- Browser matrix:
  - Chromium (required)
  - Firefox (required in nightly/full regression)
  - WebKit (required in nightly/full regression)
- CI cadence:
  - PR: Chromium `@p0`
  - nightly: Chromium/Firefox/WebKit full matrix

## 10) Artifact Capture and Reporting
### Capture policies
- Screenshot: `only-on-failure`
- Video: `retain-on-failure`
- Trace: `retain-on-failure`

### Reporting outputs
- Console list/line report
- HTML report
- JSON report for downstream processing
- JUnit XML for CI test dashboards

## 11) CI/CD Pipeline Documentation
### Required stages
1. Install: `npm ci`
2. Build: `npm run build`
3. Matrix generation: `npm run e2e:matrix`
4. Test scaffolding check: `npm run e2e:scaffold` (dry-run allowed)
5. Critical smoke: `npm run e2e:ci:critical`
6. Publish artifacts (html, json, junit, traces, screenshots, videos)

### Gate policy
- Block merge on failed `@p0` tests.
- Warn-only on flaky `@p1/@p2` until stabilized.

## 12) Maintenance Procedures
- Weekly:
  - rotate flaky-test triage
  - review top failing selectors
  - review risk model drift
- Sprint-end:
  - regenerate matrix from latest accepted stories
  - archive old matrix snapshot with release tag
- Quarterly:
  - benchmark runtime and optimize bottlenecks

## 13) Version Control Strategy
- Commit generated matrix JSON/MD for review visibility.
- Do not auto-commit generated spec scaffolds without owner approval.
- Branch naming:
  - `test/matrix-<release>`
  - `test/e2e-hardening-<module>`
- Tag matrix snapshots with release tags for traceability.

## 14) Performance Benchmarking Criteria
### Coverage KPI
- Story acceptance coverage:
  - `covered_criteria / total_criteria >= 0.90`
- Critical-path route coverage:
  - all auth, billing, campaign execution, webhook flows covered.

### Runtime KPI
- `@p0` suite target: `< 5 minutes`.
- Full suite target: team-defined SLO (track trend weekly).

### Reliability KPI
- Flake rate target: `< 2%` over rolling 14 days.
- Retry rescue rate tracked separately.

## 15) Implementation Commands
```bash
# Generate matrix from AI user stories
npm run e2e:matrix

# Scaffold Playwright specs from matrix
npm run e2e:scaffold

# Run module-organized multi-role suites (admin/regular/guest/premium-like)
npm run e2e:modules

# Run module-organized suites across chromium/firefox/webkit
npm run e2e:modules:cross-browser

# Local debug run
npm run e2e:debug

# CI smoke for critical paths
npm run e2e:ci:critical

# Generate feature coverage summary from executed tests
npm run e2e:coverage
```

## 16) Governance Checklist
- Matrix regenerated from latest approved stories.
- Risk weights reviewed by QA + engineering.
- Critical-path runtime benchmark still under 5 minutes.
- Coverage report confirms `>= 90%` acceptance-criteria coverage.
