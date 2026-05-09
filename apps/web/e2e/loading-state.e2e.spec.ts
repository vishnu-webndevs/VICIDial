import { expect, test } from "@playwright/test";

test.describe("loading state management", () => {
  test("shows accessible hydration loader for at least 500ms then fades to login", async ({ page }) => {
    const startedAt = Date.now();

    await page.goto("/login");

    const loader = page.getByRole("status", { name: "Loading WND Dialer..." });
    await expect(loader).toBeVisible();
    await expect(loader).toBeHidden({ timeout: 5_000 });

    const elapsedMs = Date.now() - startedAt;
    expect(elapsedMs).toBeGreaterThanOrEqual(500);

    await expect(page.locator('form input[name="email"]')).toBeVisible({ timeout: 10_000 });
  });

  test("holds session loader on slow network and renders content without broken layout", async ({
    page,
  }) => {
    await page.addInitScript(() => {
      localStorage.setItem("wnd_token", "loading-test-token");
      localStorage.setItem("wnd_tenant_id", "tenant-loading");
    });

    await page.route("**/api/v1/**", async (route) => {
      const url = new URL(route.request().url());
      const path = url.pathname.replace("/api/v1", "");

      if (path === "/auth/me") {
        await new Promise((resolve) => setTimeout(resolve, 1200));
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({
            data: {
              id: "user-loading",
              email: "owner@example.com",
              first_name: "Owner",
              last_name: "User",
              is_platform_admin: true,
              current_tenant: {
                id: "tenant-loading",
                name: "Tenant Loading",
                slug: "tenant-loading",
                status: "active",
              },
              permissions: ["*"],
            },
          }),
        });
      }

      if (path === "/tenant") {
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({
            data: {
              id: "tenant-loading",
              name: "Tenant Loading",
              slug: "tenant-loading",
              status: "active",
              settings: {
                timezone: "UTC",
                locale: "en",
                date_format: "Y-m-d",
                voice_locale: "en-US",
              },
            },
          }),
        });
      }

      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: [] }),
      });
    });

    await page.goto("/tenant");

    const sessionLoader = page.getByRole("status", { name: "Loading session..." });
    await expect(sessionLoader).toBeVisible();
    await expect(sessionLoader).toBeHidden({ timeout: 8_000 });

    await expect(page.getByPlaceholder("Default caller ID (+15551234567)")).toBeVisible();
  });

  test("keeps loading icon centered with 1:1 ratio on mobile and throttled cpu", async ({
    page,
    browserName,
  }) => {
    test.skip(browserName !== "chromium", "CPU throttling via CDP is chromium-only.");

    await page.setViewportSize({ width: 390, height: 844 });
    const cdpSession = await page.context().newCDPSession(page);
    await cdpSession.send("Emulation.setCPUThrottlingRate", { rate: 4 });

    await page.goto("/login");

    const spinnerWrap = page.locator(".app-loading-spinner-wrap");
    await expect(spinnerWrap).toBeVisible();
    const box = await spinnerWrap.boundingBox();
    expect(box).not.toBeNull();
    if (!box) {
      return;
    }

    const viewport = page.viewportSize();
    expect(viewport).not.toBeNull();
    if (!viewport) {
      return;
    }

    const centerX = box.x + box.width / 2;
    const centerY = box.y + box.height / 2;

    expect(Math.abs(box.width - box.height)).toBeLessThanOrEqual(2);
    expect(Math.abs(centerX - viewport.width / 2)).toBeLessThanOrEqual(viewport.width * 0.12);
    expect(Math.abs(centerY - viewport.height / 2)).toBeLessThanOrEqual(viewport.height * 0.12);
  });
});
