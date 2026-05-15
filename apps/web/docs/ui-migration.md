# UI Migration Report

## Scope

- Reference template: `ui-template/javascript-version` (Materio MUI Next.js Admin Template Free).
- Goal: introduce a parallel design system in `src/ui` and migrate presentation incrementally without backend/business logic changes.

## Template Audit

### Design Tokens Extracted

- Color palette:
  - Primary `#8C57FF` (`light: #A379FF`, `dark: #7E4EE6`)
  - Secondary `#8A8D93`
  - Error `#FF4C51`
  - Warning `#FFB400`
  - Info `#16B1FF`
  - Success `#56CA00`
  - Light backgrounds: `default #F4F5FA`, `paper #FFFFFF`
  - Dark backgrounds: `default #28243D`, `paper #312D4B`
- Typography scale:
  - Base size `13.125px`
  - `h1` `2.875rem` through `overline` `0.75rem`
  - Font family: Inter-first stack
- Spacing:
  - Base spacing formula `0.25rem * factor` (4px grid)
- Radius:
  - Global radius `6`
  - Template custom map: `2/4/6/8/10`
- Shadows:
  - Material shadow scale + custom levels (`xs`..`xl`) with mode-aware alpha
- Breakpoints:
  - MUI: `xs 0`, `sm 600`, `md 900`, `lg 1200`, `xl 1536`
  - Nav config adds `xxl 1920`

### Component Architecture Catalog

- Layout shells:
  - Vertical dashboard layout (`@layouts/VerticalLayout`)
  - Blank/auth pages (`(blank-layout-pages)`)
  - Error/misc pages
- Navigation:
  - Vertical sidebar menu with grouped sections and collapse behavior
  - Top navbar with user controls, theme mode, search
- Surfaces:
  - Cards, dialogs, drawer, popover, snackbar, paper variants
- Data display:
  - Tables + pagination, chart widgets, statistic cards, timeline blocks
- Inputs:
  - Text fields, select, checkbox/radio/switch, date/time controls, validation states
- Feedback:
  - Alerts, linear/circular progress, skeleton patterns, empty-ish placeholders

### Interaction Patterns

- Hover/focus:
  - Opacity-based hover overlays (`action.hover`) and visible focus tokens
- Loading:
  - Skeleton/loader-first sections, button pending states
- Validation:
  - Inline field error + helper text pattern
- Responsive:
  - Sidebar fixed on large screens, drawer on small screens
  - Content padding from layout token and breakpoint scaling
  - Dense/card-like table behavior for constrained widths
- Dark mode:
  - Mode stored in local/cookie settings and applied by theme provider

## Implemented In `src/ui`

- Theme:
  - `src/ui/theme/index.ts`
  - `src/ui/theme/ThemeProvider.tsx` with mode toggle synchronization (`wnd_ui_mode`)
- Layout and navigation:
  - `src/ui/Layout/DashboardLayout.tsx`
  - `src/ui/Navigation/Sidebar.tsx`
  - `src/ui/Navigation/Navbar.tsx`
- Surfaces:
  - `src/ui/Surfaces/Card.tsx`
  - `src/ui/Surfaces/Modal.tsx`
- Feedback:
  - `src/ui/Feedback/Snackbar.tsx`
- Data display:
  - `src/ui/DataDisplay/DataTable.tsx` (sorting, pagination, density, mobile card mode)
- Inputs:
  - `src/ui/Inputs/FormTextField.tsx`
  - `src/ui/Inputs/FormSelect.tsx`
  - `src/ui/Inputs/DatePicker.tsx`
  - `src/ui/Inputs/Button.tsx`
- Barrel export:
  - `src/ui/index.ts`

## Pages Refactored

- Migrated:
  - `src/app/demo/page.tsx`
    - Replaced legacy presentational components with `/src/ui` components (`Card`, `Button`, `DataTable`, `Snackbar`).
- Migrated (auth layout + controls):
  - `src/app/login/page.tsx`
  - `src/app/register/page.tsx`
    - Inputs, buttons, card container, and link styling now render through MUI (`/src/ui` + `@mui/material`) without Tailwind class styling.
- Migrated (workspace flow):
  - `src/app/onboarding/page.tsx`
    - Checklist, progress, alerts, and navigation links now use MUI components and tokenized theme colors.
- In progress:
  - Remaining app pages still use existing shell/primitives and are queued for phased replacement.

## Design Integration Audit (2026-04-10)

- Status: **partially integrated**, not fully replaced.
- Visual confirmation:
  - New design is active on `demo`, `login`, and `register` surface components.
  - Legacy design still appears on app-internal routes that are mounted with `AppShell`.
- Legacy styling persistence (code-level instances):
  - Legacy shell usage path (`@/components/app-shell`) remains in 18 app routes, but now renders through MUI `DashboardLayout` and MUI state/feedback wrappers.
  - Legacy primitives path (`@/components/ui-primitives`) remains in 6 routes, now backed by MUI components (`Button`, `Card`, `Chip`, `Alert`, `Skeleton`) for visual parity during incremental page migration.
  - Remaining route-level Tailwind utility classes still exist in 22 route files (~307 occurrences), concentrated in:
    - `src/app/dialer/page.tsx`
    - `src/app/crm/leads/page.tsx`
    - `src/app/campaigns/page.tsx`
    - `src/app/calls/page.tsx`
    - `src/app/page.tsx`
    - `src/app/analytics/page.tsx`
  - Prior snapshot note:
    - Earlier state had fully legacy shell rendering + 25 files with Tailwind route styling.
  - Historical list of direct shell consumers includes:
    - `src/app/page.tsx`
    - `src/app/dialer/page.tsx`
    - `src/app/campaigns/page.tsx`
    - `src/app/calls/page.tsx`
    - `src/app/crm/leads/page.tsx`
    - `src/app/onboarding/page.tsx`
    - and other operations/admin routes.
- Root cause of incomplete integration:
  - Migration is only partial at **route markup level**: global `ThemeProvider` is wired and shared shell/primitives now render through MUI, but many page templates still contain route-level Tailwind utility styling.
  - `src/app/globals.css` still defines Tailwind-driven base styling and element-level overrides, which keeps old visual patterns active on unmigrated routes.
- Deployment/environment conclusion:
  - The new design system is functional where adopted.
  - Full cross-environment replacement cannot be considered complete until remaining route-level Tailwind markup is migrated to `/src/ui` and legacy utility styling is removed.

## Tooling and Governance

- Import governance:
  - ESLint `no-restricted-imports` added to force MUI usage through `/src/ui` in app code.
  - `src/ui/**` exempted so wrappers can import MUI internals.
- Repository hygiene:
  - Added `ui-template/` to `.gitignore` (style-guide clone, no source edits in template subtree).
  - Excluded `ui-template/**` from TypeScript and ESLint workspace checks.

## Visual Regression Scaffolding

- Added initial Playwright visual test scaffold for light/dark snapshots on key public pages.
- Snapshot baseline creation and CI wiring remain required for enforcement.

## Deviations and Rationale

- Template kept untouched in `ui-template` as requested.
- Because the app currently runs on React 19, `mui-datatables` is installed via `--legacy-peer-deps` for compatibility while avoiding runtime coupling until full validation.
- Full page-by-page migration is not complete yet; current state provides the shared theme/component foundation and one fully migrated reference page.

## Quality Gate Status (Current)

- `npm run build`: pass
- `npm run lint`: pass with no errors (template path excluded)
- `npm run e2e` (headed): pass for core flow suite (`6 passed`, visual scaffold tests skipped by design)
- Lighthouse (desktop):
  - `/demo`: performance `97`, accessibility `100`
  - `/login`: performance `99`, accessibility `100`
- Bundle analysis:
  - Turbopack build does not emit `@next/bundle-analyzer` reports.
  - Webpack analyzer run (`next build --webpack` with `ANALYZE=true`) generated:
    - `.next/analyze/client.html`
    - `.next/analyze/edge.html`
    - `.next/analyze/nodejs.html`
  - Baseline delta (`<=150 kB gz`) is not yet measurable from current history-only workspace state; requires a pre-migration baseline artifact.
- Chromatic / enforced visual diff CI: scaffold only; snapshot baselines and pipeline wiring pending.
