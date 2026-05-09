import { expect, test } from "@playwright/test";
import { authenticatePage, createTenantUsers, logoutPage, runKey } from "../../support/test-helpers";

test("@p0 @critical E2E-BILLING-001 Owner attaches payment method securely: Owner can create a setup intent", async ({ page }) => {
  // Source story: US-BILLING-001
  // Risk score: 82
  // Priority: P0
  const users = await createTenantUsers(page.request, runKey("billing-setup-intent"));
  try {
    await page.route("**/api/v1/billing/setup-intent", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            setup_intent_id: "seti_e2e_001",
            customer_id: "cus_e2e_001",
            client_secret: "seti_e2e_001_secret",
          },
        }),
      });
    });

    await authenticatePage(page, users.owner, "/billing");
    await expect(page).toHaveURL(/\/billing/);
    await expect(page.getByRole("heading", { name: "Payment Methods", exact: true })).toBeVisible({ timeout: 30_000 });

    const setupIntentRequest = page.waitForResponse((response) => {
      return response.url().includes("/api/v1/billing/setup-intent") && response.request().method() === "POST";
    });
    await page.getByRole("button", { name: "Create Stripe Setup Intent" }).first().click();
    const setupIntentResponse = await setupIntentRequest;
    expect(setupIntentResponse.ok()).toBeTruthy();

    await expect(page.locator("main")).toContainText(/Stripe setup intent created|Setup intent is ready/i);
  } finally {
    await logoutPage(page, users.owner);
  }
});
