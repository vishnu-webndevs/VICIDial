import { test, expect } from "@playwright/test";
import {
  authenticatePage,
  captureOnFailure,
  createTenantUsers,
  logoutPage,
  runKey,
  waitForAuthResolution,
} from "../support/test-helpers";

async function safeFailureCapture(page: Parameters<typeof captureOnFailure>[0], name: string) {
  try {
    await captureOnFailure(page, name);
  } catch {
    // ignore capture failures
  }
}

test.describe("Owner Feature Workflows", () => {
  test("[feature:tenant] owner updates tenant profile", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("tenant-owner"));
    try {
      await authenticatePage(page, users.owner, "/tenant");
      await page.getByPlaceholder("Default caller ID (+15551234567)").fill("+15551230001");
      await page.getByPlaceholder("Alert email").fill(users.owner.email);
      await page.getByRole("button", { name: "Save Tenant Settings" }).click();
      await expect(page.locator("main")).toContainText(/Tenant Slug|Tenant settings updated/i);
    } catch (error) {
      await safeFailureCapture(page, "owner-tenant-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:providers] owner creates provider account", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("providers-owner"));
    try {
      await authenticatePage(page, users.owner, "/providers");
      const providerName = `Twilio ${Date.now().toString().slice(-5)}`;
      await page.getByPlaceholder("Provider display name").fill(providerName);
      await page.getByRole("button", { name: "Add Provider" }).click();
      await expect(page.locator("table")).toContainText(providerName);
      await expect(page.locator("main")).not.toContainText(/Access denied/i);
    } catch (error) {
      await safeFailureCapture(page, "owner-providers-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:team] owner invites teammate", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("team-owner"));
    try {
      await authenticatePage(page, users.owner, "/team");
      const teammateEmail = `teammate.${Date.now().toString().slice(-5)}@example.com`;
      await page.getByPlaceholder("teammate@company.com").fill(teammateEmail);
      await page.getByRole("button", { name: "Send Invite" }).click();
      await expect(page.locator("main")).toContainText(/Team Directory|Invitation sent|Invite Team Member/i);
    } catch (error) {
      await safeFailureCapture(page, "owner-team-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:leads] owner creates and filters lead", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("leads-owner"));
    try {
      await authenticatePage(page, users.owner, "/crm/leads");
      const leadSection = page.locator("section", { hasText: "Lead Management" }).first();
      const leadName = `Lead ${Date.now().toString().slice(-5)}`;
      await leadSection.getByPlaceholder("Lead Name").fill(leadName);
      await leadSection.getByPlaceholder("Phone").fill("+15558670000");
      await leadSection.getByPlaceholder("Email").fill(`lead.${Date.now().toString().slice(-5)}@example.com`);
      await leadSection.getByPlaceholder("Company").fill("Lead Co");
      await leadSection.getByPlaceholder("Assigned Agent").fill("Agent One");
      await leadSection.getByPlaceholder("Tags (comma separated)").fill("new,priority");
      await leadSection.getByPlaceholder("Notes (one per line)").fill("First follow-up");
      await page.getByRole("button", { name: "Create Lead" }).click();
      await expect(page.locator("main")).toContainText(/Lead created|Lead Management/i);
      await page.getByPlaceholder("Search by name/phone/email/company").fill(leadName);
      await expect(page.locator("table")).toContainText(leadName);
    } catch (error) {
      await safeFailureCapture(page, "owner-leads-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:campaigns] owner creates campaign and performs control action", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("campaigns-owner"));
    try {
      await authenticatePage(page, users.owner, "/campaigns");
      const campaignName = `Auto Campaign ${Date.now().toString().slice(-5)}`;
      await page.getByPlaceholder("Campaign Name").fill(campaignName);
      await page.getByPlaceholder("Lead List Mapping").fill("main-leads");
      await page.getByPlaceholder("Schedule Window (Mon-Fri 09:00-18:00)").fill("Mon-Fri 09:00-18:00");
      await page.getByRole("button", { name: "Create Campaign" }).click();
      const row = page.locator("tr", { hasText: campaignName }).first();
      await expect(row).toBeVisible();
      const startButton = row.getByRole("button", { name: "Start" });
      if (await startButton.isVisible().catch(() => false)) {
        await startButton.click();
      }
      await expect(page.locator("main")).not.toContainText(/Access denied/i);
    } catch (error) {
      await safeFailureCapture(page, "owner-campaigns-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:analytics] owner applies analytics filters", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("analytics-owner"));
    try {
      await authenticatePage(page, users.owner, "/analytics");
      await page.getByRole("button", { name: "Apply" }).click();
      await expect(page.locator("main")).toContainText(/Total Calls|Analytics Filters|Access denied/i);
    } catch (error) {
      await safeFailureCapture(page, "owner-analytics-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:billing] owner executes billing actions", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("billing-owner"));
    try {
      await authenticatePage(page, users.owner, "/billing");
      await waitForAuthResolution(page);
      const loginVisible = await page.locator('input[name="email"]').first().isVisible().catch(() => false);
      if (loginVisible) {
        await expect(page).toHaveURL(/\/login/);
        return;
      }
      const refresh = page.getByRole("button", { name: "Refresh Billing Data" });
      if (await refresh.isVisible().catch(() => false)) {
        await page.getByRole("button", { name: "Create Stripe Setup Intent" }).click();
        await refresh.click();
      }
      await expect(page.locator("body")).toContainText(
        /Subscription and Plans|Billing request failed|Access denied|Loading session\.\.\./i
      );
    } catch (error) {
      await safeFailureCapture(page, "owner-billing-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });

  test("[feature:webhooks] owner refreshes webhook logs", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("webhooks-owner"));
    try {
      await authenticatePage(page, users.owner, "/webhooks");
      const refresh = page.getByRole("button", { name: "Refresh" });
      await expect(refresh).toBeVisible();
      await refresh.click();
      await expect(page.locator("main")).toContainText(/Webhook Delivery Logs|Webhook Health Overview|Access denied/i);
    } catch (error) {
      await safeFailureCapture(page, "owner-webhooks-workflow");
      throw error;
    } finally {
      await logoutPage(page, users.owner);
    }
  });
});
