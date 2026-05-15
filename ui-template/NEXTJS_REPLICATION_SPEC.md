# Vicidial UI Template -> Next.js Replication Spec

## 1) Objective

Recreate the audited HTML template in Next.js with:

- Pixel-parity visuals (spacing, colors, typography, shadows, radii, component dimensions).
- Behavior parity (menu, modals, offcanvas, toasts, tabs, tooltips/popovers, chart interactions).
- No duplicated component implementations (single source per unique UI pattern).

Canonical style sources:

- `assets/vendor/css/core.css` (base Bootstrap + template override tokens/utilities).
- `assets/vendor/css/theme-default.css` (active theme color and shell styling).

Canonical behavior sources:

- `assets/js/main.js`
- `assets/js/config.js`
- `assets/js/dashboards-analytics.js`
- `assets/js/ui-modals.js`
- `assets/js/ui-toasts.js`
- `assets/js/ui-popover.js`
- `assets/js/pages-account-settings-account.js`
- `assets/js/extended-ui-perfect-scrollbar.js`

## 2) Runtime Assets And Dependency Contract

Replicate these runtime dependencies in Next.js:

- CSS:
  - `assets/vendor/fonts/boxicons.css`
  - `assets/vendor/css/core.css`
  - `assets/vendor/css/theme-default.css`
  - `assets/css/demo.css` (optional for demo-only styling; include only where needed)
  - `assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css`
  - `assets/vendor/libs/apex-charts/apex-charts.css`
- JS libraries:
  - jQuery (included in HTML, but avoid direct usage unless required by library behavior)
  - Popper
  - Bootstrap JS
  - Perfect Scrollbar
  - Menu plugin (`assets/vendor/js/menu.js`)
  - ApexCharts

Reference include order from `html/index.html`:

1. `helpers.js`
2. `config.js`
3. vendor libs (`jquery`, `popper`, `bootstrap`, `perfect-scrollbar`, `menu`, `apexcharts`)
4. page runtime (`main.js`, then page-specific script)

## 3) Global Design Tokens (Exact)

### 3.1 Color Tokens

From `assets/js/config.js` and theme CSS:

- `primary`: `#696cff`
- `secondary`: `#8592a3`
- `success`: `#71dd37`
- `info`: `#03c3ec`
- `warning`: `#ffab00`
- `danger`: `#ff3e1d`
- `dark`: `#233446`
- `black`: `#000`
- `white`: `#fff`
- `body`: `#f4f5fb`
- `headingColor`: `#566a7f`
- `axisColor`: `#a1acb8`
- `borderColor`: `#eceef1`

Theme shell colors (`theme-default.css`):

- Navbar surface: `#fff` and detached glass effect via `rgba(255,255,255,0.95)`.
- Menu surface: `#fff`.
- Footer surface: `#f5f5f9`.
- Body/page background: `#f5f5f9` family usage across layout surfaces.

### 3.2 Typography Tokens

From `core.css`:

- Headings `h1..h6`:
  - `font-weight: 500`
  - `line-height: 1.1`
  - `color: #566a7f`
- Sizes:
  - `h1`: responsive to `2.375rem` at `>=1200px`
  - `h2`: responsive to `2rem` at `>=1200px`
  - `h3`: responsive to `1.625rem` at `>=1200px`
  - `h4`: responsive to `1.375rem` at `>=1200px`
  - `h5`: `1.125rem`
  - `h6`: `0.9375rem`
- Body uses Bootstrap CSS vars:
  - `font-family: var(--bs-body-font-family)`
  - `font-size: var(--bs-body-font-size)`
  - `font-weight: var(--bs-body-font-weight)`
  - `line-height: var(--bs-body-line-height)`

### 3.3 Spacing Scale

Bootstrap utility scale (must remain exact):

- `0 -> 0`
- `1 -> 0.25rem`
- `2 -> 0.5rem`
- `3 -> 1rem`
- `4 -> 1.5rem`
- `5 -> 3rem`

Examples verified in `core.css`:

- `.p-5 { padding: 3rem !important; }`
- Container gutters use `var(--bs-gutter-x, 1.625rem)`.

### 3.4 Border Radius / Shadow / Elevation

- Standard radius: `.rounded { border-radius: 0.375rem !important; }`
- Card/dropdown shadow family: `0 0.25rem 1rem rgba(161, 172, 184, 0.45)`
- Shell detached shadow: `0 0 0.375rem 0.25rem rgba(161, 172, 184, 0.15)`
- Menu vertical shadow: `0 0.125rem 0.375rem 0 rgba(161, 172, 184, 0.12)`

### 3.5 Breakpoints

Bootstrap default breakpoints used by template:

- `sm >= 576px`
- `md >= 768px`
- `lg >= 992px`
- `xl >= 1200px`
- `xxl >= 1400px`

Critical layout transitions:

- Mobile/overlay behavior breakpoint around `1199.98px`.
- Desktop fixed/collapsed layout logic starts at `>=1200px`.

## 4) Layout Shell Contract

Root hierarchy (from `html/index.html` and shared pages):

1. `.layout-wrapper.layout-content-navbar`
2. `.layout-container`
3. `#layout-menu.layout-menu.menu-vertical.menu.bg-menu-theme`
4. `.layout-page`
5. `.layout-navbar.navbar-detached...bg-navbar-theme`
6. `.content-wrapper`
7. `.container-xxl.flex-grow-1.container-p-y`
8. `.content-backdrop.fade`

Required behavioral classes toggled by runtime:

- `layout-menu-collapsed`
- `layout-menu-hover`
- `layout-transitioning`
- `layout-no-transition`
- `layout-menu-offcanvas`
- `layout-menu-fixed` / `layout-menu-fixed-offcanvas`
- `layout-navbar-fixed`

## 5) Interaction Runtime Contract

### 5.1 Global Initializers

From `assets/js/main.js`:

- Initialize menu on `#layout-menu` with:
  - `orientation: 'vertical'`
  - `closeChildren: false`
- Bind `.layout-menu-toggle` click -> `Helpers.toggleCollapsed()`.
- Hover delay for toggle visibility:
  - `300ms` on non-small screens.
  - `0ms` on small screens.
- Menu shadow visibility controlled by Perfect Scrollbar `ps-scroll-y` thumb offset.
- Initialize Bootstrap tooltips for `[data-bs-toggle="tooltip"]`.
- Add/remove `.active` on accordion item during `show.bs.collapse` / `hide.bs.collapse`.
- Enable:
  - `Helpers.setAutoUpdate(true)`
  - `Helpers.initPasswordToggle()`
  - `Helpers.initSpeechToText()`
- Desktop-only collapse persistence:
  - if not small screen -> `Helpers.setCollapsed(true, false)`

### 5.2 Menu Animation / Overlay

From `core.css`:

- Overlay animation keyframe `menuAnimation`:
  - opacity `0 -> 0.5` over `0.3s`.
- Transition durations for menu and shell elements:
  - `0.3s` across transform/width/margins/padding depending on state.

### 5.3 Dropdown Animation

From `core.css`:

- `.dropdown-menu` includes:
  - `box-shadow: 0 0.25rem 1rem rgba(161, 172, 184, 0.45)`
  - `animation: dropdownAnimation 0.1s`

### 5.4 Page-Specific Runtime

- `ui-modals.js`:
  - YouTube modal autoplay/stop wiring.
  - Uses Bootstrap modal lifecycle (`shown.bs.modal`, `hide.bs.modal`).
- `ui-toasts.js`:
  - Dynamic placement/type via `#selectTypeOpt` and `#selectPlacement`.
  - Dispose current toast before showing next.
- `ui-popover.js`:
  - Init `[data-bs-toggle="popover"]` with `{ html: true, sanitize: false }`.
- `form-basic-inputs.js`:
  - Sets `#defaultCheck2.indeterminate = true`.
- `pages-account-settings-account.js`:
  - Avatar preview upload (`window.URL.createObjectURL`) and reset.
- `extended-ui-perfect-scrollbar.js`:
  - Initializes custom scroll containers:
    - `#vertical-example`
    - `#horizontal-example` (`suppressScrollY: true`)
    - `#both-scrollbars-example`

## 6) Deduplicated Component Catalog

Implement one reusable Next.js component per unique pattern.

### 6.1 Navigation / Shell

- App shell layout (vertical menu + top navbar + content + footer).
- Menu item types:
  - single link
  - expandable toggle with submenu
  - active leaf marker
  - disabled item
- Menu collapse/expand affordance (`.layout-menu-toggle`).
- Menu scroll shadow (`.menu-inner-shadow`).

### 6.2 Cards

- Base card:
  - `.card`
  - optional `.card-header`
  - `.card-body`
- Variants from `cards-basic.html`:
  - text cards
  - contextual backgrounds
  - image cards
  - quote card
  - card grid using `.row-cols-*`

### 6.3 Forms

From `forms-basic-inputs.html` and `forms-input-groups.html`:

- Inputs:
  - text/search/email/url/tel/password/number/date/month/week/time/color/file
- Selects:
  - single, multiple, sized (`form-select-lg`, `form-select-sm`)
- Textareas (standard and input-group-integrated)
- Floating labels (`.form-floating`)
- Checks/radios:
  - default, checked, disabled, inline
- Switches (`.form-check.form-switch`)
- Range inputs
- Input groups:
  - prepend/append text
  - merged input groups (`.input-group-merge`)
  - icon-based groups
  - checkbox/radio addons
  - dropdown button addons
  - select addons
  - file addons

### 6.4 Auth

From `auth-login-basic.html` (+ register/forgot pages with same shell):

- Wrapper:
  - `.authentication-wrapper.authentication-basic.container-p-y`
  - `.authentication-inner`
- Auth card with logo/header/body/form:
  - `#formAuthentication`
  - full-width CTA `.btn.btn-primary.d-grid.w-100`

### 6.5 Account Settings

From `pages-account-settings-account.html`:

- Settings tabs:
  - `.nav.nav-pills.flex-column.flex-md-row`
- Profile card with avatar upload/reset controls.
- Account form (`#formAccountSettings`) with multi-field grid.
- Deactivation card with checkbox confirmation.

### 6.6 Bootstrap Interactive Components

- Modals (default, centered, scrollable, fullscreen, animation, etc.).
- Offcanvas (positions and backdrop/scroll options).
- Toasts (basic and placement-configurable).
- Tabs and pills.
- Accordions.
- Dropdowns.
- Tooltips and popovers.
- Carousels.
- Alerts, badges, pagination, breadcrumbs, progress, list groups.

### 6.7 Tables

From `tables-basic.html`:

- Standard responsive table container patterns.
- Header/body styling variants.
- Bordered/striped/hover/contextual rows.
- Small/compact variants.

### 6.8 Dashboard Widgets

From `index.html` + `dashboards-analytics.js`:

- Apex chart mount points:
  - `#totalRevenueChart`
  - `#growthChart`
  - `#profileReportChart`
  - `#orderStatisticsChart`
  - `#incomeChart`
  - `#expensesOfWeek`
- KPI cards and list/stat blocks around charts.

## 7) DOM And ID Contracts (Do Not Break)

Preserve these IDs/classes when porting, or map them 1:1 in component adapters:

- Layout and menu:
  - `#layout-menu`, `.layout-menu-toggle`, `.menu-inner`, `.menu-inner-shadow`
- Dashboard charts:
  - `#totalRevenueChart`, `#growthChart`, `#profileReportChart`, `#orderStatisticsChart`, `#incomeChart`, `#expensesOfWeek`
- Forms:
  - `#defaultCheck2` (indeterminate behavior)
- Account:
  - `#formAccountSettings`, `#uploadedAvatar`, `.account-file-input`, `.account-image-reset`
- Toast demo:
  - `.toast-placement-ex`, `#showToastPlacement`, `#selectTypeOpt`, `#selectPlacement`
- Auth:
  - `#formAuthentication`

## 8) Motion And State Parity Rules

- Keep transform hover lift behavior where defined (`translateY(-1px)` utility/component states in `core.css`).
- Preserve Bootstrap and template transition timing (`0.1s`, `0.3s`, and stock BS timings).
- Keep active/open/disabled class handling exactly for menu and nested submenu markers.
- Keep backdrop blur and translucency on fixed navbar layers.

## 9) Next.js Implementation Blueprint

### 9.1 App Structure

- `app/(dashboard)/layout.tsx`:
  - shell markup and static regions.
- `components/layout/`:
  - `AppShell`, `SidebarMenu`, `TopNavbar`, `Footer`.
- `components/ui/`:
  - reusable `Card`, `FormField`, `InputGroup`, `Modal`, `Offcanvas`, `Toast`, `Tabs`, `DataTable`.
- `components/charts/`:
  - Apex wrappers with deterministic options.
- `lib/theme/tokens.ts`:
  - exact token mapping from CSS/config.

### 9.2 Client-Only Boundaries

Use `"use client"` wrappers for:

- Bootstrap JS behavior bridges (modals, toasts, tooltips, popovers, offcanvas, collapse).
- Perfect Scrollbar.
- Menu plugin initialization.
- ApexCharts rendering.

### 9.3 Styling Strategy

Preferred:

1. Import audited CSS directly for baseline parity.
2. Layer minimal module/Tailwind/CSS-in-JS only for app-specific adaptations.
3. Avoid rewriting Bootstrap/template classes until parity is signed off.

## 10) Acceptance Checklist

- Visual parity:
  - shell, cards, forms, auth pages, account settings, tables, dashboard.
- Behavioral parity:
  - menu collapse/hover, tooltips/popovers, modals, toasts, offcanvas, tabs/accordion, avatar upload/reset.
- Token parity:
  - color palette, type scale, spacing scale, radius/shadow, responsive breakpoints.
- Zero duplicate components:
  - one component per unique UI pattern with variant props.
- ID/class contract:
  - all runtime selectors continue to resolve.

## 11) Source Pages Audited (Primary)

- `html/index.html`
- `html/ui-modals.html`
- `html/ui-offcanvas.html`
- `html/ui-toasts.html`
- `html/ui-tabs-pills.html`
- `html/tables-basic.html`
- `html/forms-basic-inputs.html`
- `html/forms-input-groups.html`
- `html/auth-login-basic.html`
- `html/pages-account-settings-account.html`
- `html/cards-basic.html`

## 12) Implementation Notes To Prevent Drift

- Treat `core.css` as canonical over source SCSS for final rendered values.
- Match class composition exactly before introducing abstraction.
- Keep chart mount IDs stable; changing IDs breaks page scripts.
- If replacing scripts with React logic, preserve event timing and state classes.
