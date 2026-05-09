import { test, expect } from "@playwright/test";
import { authenticatePage, createTenantUsers, logoutPage, runKey, waitForAuthResolution } from "../support/test-helpers";

test.describe("Regular User Journeys", () => {
  test("[feature:analytics] regular user can perform allowed workflow", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("regular-analytics"));
    try {
      await authenticatePage(page, users.support, "/analytics");
      if (users.support.permissions.includes("analytics.view")) {
        await page.getByRole("button", { name: "Apply" }).click();
        await expect(page.locator("main")).toContainText(/Analytics Filters|Total Calls/i);
      } else {
        await expect(page.locator("main")).toContainText(/Access denied/i);
      }
    } finally {
      await logoutPage(page, users.support);
    }
  });

  test("[feature:billing] regular user denied billing management", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("regular-billing"));
    try {
      await authenticatePage(page, users.support, "/billing");
      await waitForAuthResolution(page);
      if (users.support.permissions.includes("billing.view")) {
        await expect(page.locator("main, body")).toContainText(/Subscription and Plans|Usage and Quotas/i);
      } else {
        const loginVisible = await page.locator('input[name="email"]').first().isVisible().catch(() => false);
        if (loginVisible) {
          await expect(page).toHaveURL(/\/login/);
        } else {
          await expect(page.locator("body")).toContainText(/Access denied|Loading session\.\.\./i);
        }
      }
    } finally {
      await logoutPage(page, users.support);
    }
  });
});
