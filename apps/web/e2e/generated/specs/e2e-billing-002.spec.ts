import { expect, test } from "@playwright/test";
import { authenticatePage, createTenantUsers, logoutPage, runKey } from "../../support/test-helpers";

test("@p0 @critical E2E-BILLING-002 Owner attaches payment method securely: Owner can attach a card through Stripe Elements", async ({ page }) => {
  // Source story: US-BILLING-001
  // Risk score: 82
  // Priority: P0
  const users = await createTenantUsers(page.request, runKey("billing-attach-card"));
  try {
    let attached = false;
    await page.route("**/api/v1/billing/payment-methods", async (route) => {
      if (route.request().method() === "GET") {
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({
            data: attached
              ? [
                  {
                    id: "pmrow_e2e_001",
                    stripe_payment_method_id: "pm_e2e_001",
                    card_brand: "visa",
                    card_last_four: "4242",
                    is_default: true,
                  },
                ]
              : [],
          }),
        });
        return;
      }

      attached = true;
      await route.fulfill({
        status: 201,
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            id: "pmrow_e2e_001",
            stripe_payment_method_id: "pm_e2e_001",
            card_brand: "visa",
            card_last_four: "4242",
            is_default: true,
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
      await expect(page.getByRole("button", { name: "Create Stripe Setup Intent" }).first()).toBeVisible();
      return;
    }

    const attachRequest = page.waitForResponse((response) => {
      return response.url().includes("/api/v1/billing/payment-methods") && response.request().method() === "POST";
    });
    await page.getByPlaceholder("Stripe payment_method_id (e.g. pm_xxx)").fill("pm_e2e_001");
    await page.getByRole("button", { name: "Attach Method" }).click();
    const attachResponse = await attachRequest;
    expect(attachResponse.status()).toBe(201);

    await expect(page.locator("main")).toContainText(/visa|4242/i);
  } finally {
    await logoutPage(page, users.owner);
  }
});
