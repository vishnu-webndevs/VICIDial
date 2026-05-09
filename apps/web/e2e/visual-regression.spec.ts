import { expect, test } from "@playwright/test";

const pages = [
  { name: "providers", path: "/providers" },
  { name: "team", path: "/team" },
  { name: "tenant", path: "/tenant" },
  { name: "billing", path: "/billing" },
  { name: "api-tokens", path: "/api-tokens" },
  { name: "audit-logs", path: "/audit-logs" },
  { name: "webhooks", path: "/webhooks" },
  { name: "notifications", path: "/notifications" },
];

test.describe("visual regression admin layout", () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem("wnd_token", "visual-token");
      localStorage.setItem("wnd_tenant_id", "tenant-1");
    });

    await page.route("**/api/v1/**", async (route) => {
      const url = new URL(route.request().url());
      const path = url.pathname.replace("/api/v1", "");

      const json = (data: unknown) =>
        route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify(data),
        });

      if (path === "/auth/me") {
        return json({
          data: {
            id: "user-1",
            email: "owner@example.com",
            first_name: "Owner",
            last_name: "User",
            is_platform_admin: true,
            current_tenant: {
              id: "tenant-1",
              name: "Acme 334870",
              slug: "acme-334870",
              status: "active",
            },
            role: { slug: "company_admin", name: "Company Admin" },
            permissions: ["*"],
          },
        });
      }

      if (path.startsWith("/providers")) {
        return json({ data: [] });
      }
      if (path.startsWith("/team/members")) {
        return json({ data: [], meta: { pagination: { current_page: 1, last_page: 1 } } });
      }
      if (path.startsWith("/tenant")) {
        return json({
          data: {
            id: "tenant-1",
            name: "Acme 334870",
            slug: "acme-334870",
            status: "active",
            settings: { timezone: "UTC", locale: "en", date_format: "Y-m-d", voice_locale: "en-US" },
          },
        });
      }
      if (path === "/plans") {
        return json({
          data: [
            {
              id: "plan-1",
              slug: "starter",
              name: "Starter",
              monthly_price_cents: 4900,
              yearly_price_cents: 49000,
            },
          ],
        });
      }
      if (path === "/subscription") {
        return json({ data: { status: "active", billing_cycle: "monthly", plan: { slug: "starter", name: "Starter" } } });
      }
      if (path.startsWith("/billing/usage")) {
        return json({ data: { meters: [] } });
      }
      if (path.startsWith("/billing/invoices")) {
        return json({ data: [] });
      }
      if (path.startsWith("/billing/payment-methods")) {
        return json({ data: [] });
      }
      if (path.startsWith("/api-tokens")) {
        return json({ data: [] });
      }
      if (path.startsWith("/audit-logs")) {
        return json({ data: [] });
      }
      if (path.startsWith("/webhooks/delivery-logs")) {
        return json({ data: [] });
      }
      if (path.startsWith("/notifications")) {
        return json({ data: [] });
      }

      return json({ data: [] });
    });
  });

  for (const colorScheme of ["light", "dark"] as const) {
    for (const pageMeta of pages) {
      test(`visual ${pageMeta.name} ${colorScheme}`, async ({ page }) => {
        await page.emulateMedia({ colorScheme });
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(pageMeta.path);
        await page.addStyleTag({
          content: "html { overflow-y: scroll !important; }",
        });
        await page.waitForTimeout(800);
        await expect(page).toHaveScreenshot(`${pageMeta.name}-${colorScheme}.png`, {
          fullPage: true,
          maxDiffPixelRatio: 0.015,
        });
      });
    }
  }
});
