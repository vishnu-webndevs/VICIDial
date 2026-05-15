# WND Dialer Web App

## Development

```bash
npm install
npm run dev
```

App runs on [http://localhost:3000](http://localhost:3000).

## UI System

- Source of truth for UI primitives: `src/ui`
- Material reference clone (read-only style guide): `ui-template/javascript-version`
- Theme provider is mounted in `src/app/layout.tsx` via `ThemeProvider` from `src/ui/theme/ThemeProvider.tsx`
- New page rendering should consume wrappers exported from `src/ui/index.ts`

### Theme Mode

- Light/dark mode is stored in `localStorage` key `wnd_ui_mode`
- Toggle is exposed in `DashboardLayout` navbar
- Theme recomputes through `createUiTheme(mode)`

### Adding Components

1. Add or update reusable building blocks in `src/ui/<Category>/`.
2. Export components from `src/ui/index.ts`.
3. Prefer wrapper components over direct MUI imports in app pages.
4. Add a story file under `src/ui/stories` for each new component.

## Testing and Validation

```bash
npm run lint
npm run build
npm run e2e
```

AI-driven E2E framework commands:

```bash
npm run e2e:matrix
npm run e2e:scaffold
npm run e2e:modules
npm run e2e:modules:cross-browser
npm run e2e:ci:critical
npm run e2e:coverage
```

Framework documentation:
- `docs/testing/README.md`
- `docs/testing/templates/scenario-matrix.schema.json`

For visual baselines (scaffolded Playwright visual suite):

```bash
npx playwright test e2e/visual-regression.spec.ts --project=chromium
```

Performance and bundle checks:

```bash
npm run bundle:analyze
npm run lighthouse:demo
npm run lighthouse:login
```

## Migration Notes

- Migration report: `docs/ui-migration.md`
- UI template directory is ignored in git to prevent accidental edits.
- Lint and TS checks exclude `ui-template/**` so only app code is enforced.
