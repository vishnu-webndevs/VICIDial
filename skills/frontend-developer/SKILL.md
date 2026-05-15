---
name: "frontend-developer"
description: "Builds responsive app interfaces with reusable components and strong UX. Invoke when implementing dashboards, forms, tables, and admin workflows."
---

# Frontend Developer

This skill builds the WND Dialer web application using a modern frontend approach with reusable components, responsive layouts, and integration-ready API consumption.

## Invoke When

- pages or layouts must be implemented
- dashboards and settings screens must be built
- reusable component systems must be created
- API data must be integrated into the UI
- UX improvements or frontend state patterns are needed

## Primary Responsibilities

- build page layouts and navigation
- implement reusable form, table, modal, chart, and card components
- connect authenticated APIs cleanly
- manage loading, success, empty, and error states
- deliver responsive and accessible interfaces
- keep the codebase modular and maintainable

## Expected Outputs

- application pages
- layout and routing structure
- reusable UI component library
- hooks, services, or stores for API calls
- validation-aware forms
- dashboard data visualizations

## WND Dialer Context

Frontend work should cover:

- company onboarding
- login and tenant-aware administration
- provider credential management
- subscription and billing views
- call history and analytics
- webhook and failover settings
- voice profile and provider testing screens

## UI Standards

- prefer consistent reusable components over page-specific duplication
- keep secret fields masked and intentionally revealed
- make high-volume tables searchable and filterable
- surface actionable feedback for provider and webhook failures
- optimize for company admins using the platform for long sessions

## Phase Deliverable Map

- Phase 1: Registration, login, password reset, team management, company settings pages
- Phase 2: Plan selection, billing dashboard, invoice list, usage breakdown, payment management pages
- Phase 3: Provider setup, credential management, failover config, voice profile, test connection pages
- Phase 4: Main dashboard, call history, usage analytics, provider health, audit log, webhook log pages
- Phase 5: API token management, developer portal pages

## Collaboration Notes

- align with UX page structures and components
- consume backend APIs through stable typed contracts
- expose visual regression targets for QA
- respect security requirements for secret handling and tenant context
