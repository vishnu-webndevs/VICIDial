import { expect, test } from "@playwright/test";
import { authenticatePage, createTenantUsers, logoutPage, runKey } from "../../support/test-helpers";

test("@p0 @critical E2E-BILLING-003 Owner attaches payment method securely: Errors are shown for failed attachment", async ({ page }) => {
  // Source story: US-BILLING-001
  // Risk score: 82
  // Priority: P0
  const users = await createTenantUsers(page.request, runKey("billing-attach-error"));
  try {
    await page.route("**/api/v1/billing/payment-methods", async (route) => {
      if (route.request().method() === "GET") {
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ data: [] }),
        });
        return;
      }

      await route.fulfill({
        status: 422,
        contentType: "application/json",
        body: JSON.stringify({
          error: {
            code: "PAYMENT_METHOD_INVALID",
            message: "Payment method is invalid or already attached.",
          },
        }),
      });
    });

    await authenticatePage(page, users.owner, "/billing");
    await expect(page).toHaveURL(/\/billing/);
    await expect(page.getByRole("heading", { name: "Payment Methods", exact: true })).toBeVisible({ timeout: 30_000 });

    const secureStripeEnabled = await page.getByText(/Stripe publishable key loaded/i).isVisible().catch(() => false);
    if (secureStripeEnabled) {
      await expect(page.locator("main")).toContainText(/secure card entry is enabled/i);
      return;
    }

    await page.getByPlaceholder("Stripe payment_method_id (e.g. pm_xxx)").fill("pm_bad_001");
    await page.getByRole("button", { name: "Attach Method" }).click();
    await expect(page.locator("body")).toContainText(/Payment method is invalid or already attached|Request failed with status 422/i);
  } finally {
    await logoutPage(page, users.owner);
  }
});
