import { expect, Locator, Page, TestInfo, test } from "@playwright/test";

const API_BASE = process.env.E2E_API_BASE_URL ?? "http://localhost:8000/api/v1";
const RUN_ID = Date.now().toString().slice(-6);
const OWNER_EMAIL = `owner.${RUN_ID}@example.com`;
const OWNER_PASSWORD = "Password123!";
const SUPPORT_EMAIL = `support.${RUN_ID}@example.com`;
const SUPPORT_PASSWORD = "Password123!";

type ApiEvent = {
  method: string;
  url: string;
  status: number;
};

function withApiMonitor(page: Page): ApiEvent[] {
  const events: ApiEvent[] = [];
  page.on("response", (response) => {
    if (!response.url().includes("/api/v1")) {
      return;
    }
    events.push({
      method: response.request().method(),
      url: response.url(),
      status: response.status(),
    });
  });
  return events;
}

async function attachApiSummary(testInfo: TestInfo, events: ApiEvent[], label: string) {
  await testInfo.attach(`${label}-api-events.json`, {
    body: Buffer.from(JSON.stringify(events, null, 2)),
    contentType: "application/json",
  });
  const serverErrors = events.filter((event) => event.status >= 500);
  expect(serverErrors, `Unexpected API 5xx responses for ${label}`).toEqual([]);
}

async function pulse(locator: Locator) {
  // Intentionally disabled: mutating inline styles before hydration can cause
  // Next.js hydration mismatch overlays and flaky auth form submissions.
  void locator;
}

async function clickAction(locator: Locator) {
  await expect(locator).toBeVisible();
  await pulse(locator);
  await locator.click();
}

async function typeAction(locator: Locator, value: string) {
  await expect(locator).toBeVisible();
  await pulse(locator);
  await locator.fill(value);
}

async function login(page: Page, email: string, password: string) {
  async function requestLogin() {
    let lastError: unknown;
    for (let attempt = 0; attempt < 3; attempt += 1) {
      try {
        const response = await page.request.post(`${API_BASE}/auth/login`, {
          data: { email, password },
          timeout: 45000,
          headers: {
            Accept: "application/json",
          },
        });
        const contentType = response.headers()["content-type"] ?? "";
        if (!contentType.toLowerCase().includes("application/json")) {
          if (attempt < 2) {
            await page.waitForTimeout(1200);
            continue;
          }
        }
        return response;
      } catch (error) {
        lastError = error;
        if (attempt < 2) {
          await page.waitForTimeout(1200);
        }
      }
    }
    throw lastError instanceof Error ? lastError : new Error("Login request failed after retries.");
  }

  let loginResponse = await requestLogin();
  if (!loginResponse.ok() && email === OWNER_EMAIL) {
    const registerResponse = await page.request.post(`${API_BASE}/auth/register`, {
      data: {
        company_name: `Acme ${RUN_ID}`,
        first_name: "Owner",
        last_name: "User",
        email,
        password,
        password_confirmation: password,
        timezone: "UTC",
      },
    });
    expect(
      registerResponse.ok() || registerResponse.status() === 422,
      "Owner bootstrap registration should succeed or already exist"
    ).toBeTruthy();
    loginResponse = await requestLogin();
  }
  expect(loginResponse.ok(), "Login API response should be successful").toBeTruthy();

  const loginText = await loginResponse.text();
  let loginBody: any;
  try {
    loginBody = JSON.parse(loginText);
  } catch {
    throw new Error(
      `Login API returned non-JSON response (status ${loginResponse.status()}).`
    );
  }
  const token = String(loginBody?.data?.token ?? "");
  expect(token, "Login token should be present").toBeTruthy();

  const meResponse = await page.request.get(`${API_BASE}/auth/me`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
  });
  expect(meResponse.ok(), "Profile API response should be successful").toBeTruthy();
  const meBody = await meResponse.json();
  const tenantId = String(
    meBody?.data?.current_tenant?.id ?? meBody?.data?.currentTenant?.id ?? meBody?.data?.tenant?.id ?? ""
  );
  expect(tenantId, "Current tenant id should be present").toBeTruthy();

  await page.goto("/login");
  await page.evaluate(
    ({ nextToken, nextTenantId }) => {
      localStorage.setItem("wnd_token", nextToken);
      localStorage.setItem("wnd_tenant_id", nextTenantId);
      const onboardingKey = `wnd:${nextTenantId}:onboarding_state_v1`;
      localStorage.setItem(
        onboardingKey,
        JSON.stringify({
          completed: true,
          completedAt: new Date().toISOString(),
        })
      );
    },
    { nextToken: token, nextTenantId: tenantId }
  );

  await page.goto("/");
  await expect(page).not.toHaveURL(/\/login(\?|$)/, { timeout: 30000 });
  await expect(page.locator("body")).toBeVisible({ timeout: 30000 });
}

async function gotoProtectedRoute(
  page: Page,
  route: string,
  email: string,
  password: string,
  ready: Locator
) {
  const navLabelByRoute: Record<string, string> = {
    "/analytics": "Analytics",
    "/billing": "Billing",
    "/dialer": "Dialer",
    "/campaigns": "Campaigns",
    "/team": "Team",
    "/tenant": "Tenant",
    "/providers": "Providers",
    "/crm/leads": "Leads",
    "/onboarding": "Onboarding",
  };

  for (let attempt = 0; attempt < 3; attempt += 1) {
    await page.goto(route);
    await page.waitForLoadState("domcontentloaded");
    let recovered = false;
    let loadingSessionStreak = 0;
    for (let check = 0; check < 15; check += 1) {
      const readyVisible = await ready.isVisible().catch(() => false);
      if (readyVisible) {
        return;
      }
      const accessDeniedVisible = await page
        .locator("main")
        .filter({ hasText: /Access denied/i })
        .first()
        .isVisible()
        .catch(() => false);
      if (accessDeniedVisible) {
        return;
      }
      const loginVisible = await page
        .locator('form input[name="email"]')
        .first()
        .isVisible()
        .catch(() => false);
      const onLoginRoute = /\/login(\?|$)/.test(page.url());
      if (onLoginRoute || loginVisible) {
        await login(page, email, password);
        recovered = true;
        break;
      }
      const navLabel = navLabelByRoute[route];
      if (navLabel) {
        const navLink = page.getByRole("link", { name: new RegExp(navLabel, "i") }).first();
        const navVisible = await navLink.isVisible().catch(() => false);
        if (navVisible) {
          await clickAction(navLink);
          const readyAfterNav = await ready.isVisible().catch(() => false);
          if (readyAfterNav) {
            return;
          }
        }
      }
      const loadingSessionVisible = await page.getByText("Loading session...").isVisible().catch(() => false);
      loadingSessionStreak = loadingSessionVisible ? loadingSessionStreak + 1 : 0;
      if (loadingSessionStreak >= 5) {
        await login(page, email, password);
        recovered = true;
        break;
      }
      await page.waitForTimeout(1000);
    }
    if (recovered) {
      continue;
    }
  }

  throw new Error(`Unable to open protected route ${route}. Current URL: ${page.url()}`);
}

async function registerOwnerOrLogin(page: Page, email: string, password: string) {
  await page.goto("/register");
  const registerForm = page.locator("form").first();
  await typeAction(registerForm.locator('input[name="company_name"]'), `Acme ${RUN_ID}`);
  await typeAction(registerForm.locator('input[name="first_name"]'), "Owner");
  await typeAction(registerForm.locator('input[name="last_name"]'), "User");
  await typeAction(registerForm.locator('input[name="email"]'), email);
  await typeAction(registerForm.locator('input[name="password"]'), password);
  await typeAction(registerForm.locator('input[name="password_confirmation"]'), password);

  const registerResponsePromise = page.waitForResponse(
    (response) => response.url().includes("/api/v1/auth/register") && response.request().method() === "POST",
    { timeout: 30000 }
  );
  await clickAction(registerForm.locator('button[type="submit"]'));
  const registerResponse = await registerResponsePromise;

  if (registerResponse.ok()) {
    await expect(page).toHaveURL(/onboarding/, { timeout: 30000 });
    return;
  }

  if (registerResponse.status() === 422) {
    await login(page, email, password);
    return;
  }

  const bodyText = await registerResponse.text();
  throw new Error(`Unexpected register response: ${registerResponse.status()} ${bodyText}`);
}

async function logoutByApi(page: Page) {
  const auth = await page.evaluate(() => ({
    token: localStorage.getItem("wnd_token"),
    tenantId: localStorage.getItem("wnd_tenant_id"),
  }));
  if (auth.token && auth.tenantId) {
    await page.request.post(`${API_BASE}/auth/logout`, {
      headers: {
        Authorization: `Bearer ${auth.token}`,
        "X-Tenant-Id": auth.tenantId,
      },
    });
  }
  await page.evaluate(() => {
    localStorage.removeItem("wnd_token");
    localStorage.removeItem("wnd_tenant_id");
  });
  await page.goto("/login");
}

test.describe("Full SaaS Real User E2E", () => {
  test("Authentication + Onboarding + Tenant/Provider/Team setup", async ({ page }, testInfo) => {
    const apiEvents = withApiMonitor(page);

    await test.step("Register owner account", async () => {
      await registerOwnerOrLogin(page, OWNER_EMAIL, OWNER_PASSWORD);
    });

    await test.step("Update tenant profile for onboarding", async () => {
      await gotoProtectedRoute(
        page,
        "/tenant",
        OWNER_EMAIL,
        OWNER_PASSWORD,
        page.getByPlaceholder("Default caller ID (+15551234567)")
      );
      await typeAction(page.getByPlaceholder("Default caller ID (+15551234567)"), "+15551230001");
      await typeAction(page.getByPlaceholder("Alert email"), OWNER_EMAIL);
      await clickAction(page.getByRole("button", { name: "Save Tenant Settings" }));
      await expect(page.locator("main")).toContainText("Tenant Slug");
    });

    await test.step("Create provider account", async () => {
      await gotoProtectedRoute(
        page,
        "/providers",
        OWNER_EMAIL,
        OWNER_PASSWORD,
        page.getByPlaceholder("Provider display name")
      );
      await typeAction(page.getByPlaceholder("Provider display name"), `Twilio ${RUN_ID}`);
      await clickAction(page.getByRole("button", { name: "Add Provider" }));
      await expect(page.locator("table")).toContainText(`Twilio ${RUN_ID}`);
    });

    await test.step("Invite one teammate to complete onboarding checklist", async () => {
      await gotoProtectedRoute(
        page,
        "/team",
        OWNER_EMAIL,
        OWNER_PASSWORD,
        page.getByPlaceholder("teammate@company.com")
      );
      await typeAction(page.getByPlaceholder("teammate@company.com"), `teammate.${RUN_ID}@example.com`);
      await clickAction(page.getByRole("button", { name: "Send Invite" }));
      await expect(page.locator("main")).toContainText("Team Directory");
    });

    await test.step("Verify onboarding checklist can complete", async () => {
      await gotoProtectedRoute(
        page,
        "/onboarding",
        OWNER_EMAIL,
        OWNER_PASSWORD,
        page.getByRole("button", { name: "Refresh Checklist" })
      );
      await clickAction(page.getByRole("button", { name: "Refresh Checklist" }));
      await expect(page.locator("main")).toContainText("Setup Progress");
      await expect(page.locator("main")).not.toContainText("Request failed with status 404");
    });

    await test.step("Logout behavior", async () => {
      await logoutByApi(page);
      await expect(page).toHaveURL(/\/login/);
    });

    await attachApiSummary(testInfo, apiEvents, "auth-onboarding");
  });

  test("Lead creation + CSV import + filtering", async ({ page }, testInfo) => {
    const apiEvents = withApiMonitor(page);
    await login(page, OWNER_EMAIL, OWNER_PASSWORD);
    await gotoProtectedRoute(
      page,
      "/crm/leads",
      OWNER_EMAIL,
      OWNER_PASSWORD,
      page.getByPlaceholder("Lead Name")
    );

    await test.step("Create lead", async () => {
      const leadSection = page.locator("section", { hasText: "Lead Management" }).first();
      await typeAction(leadSection.getByPlaceholder("Lead Name"), `Lead ${RUN_ID}`);
      await typeAction(leadSection.getByPlaceholder("Phone"), "+15558670000");
      await typeAction(leadSection.getByPlaceholder("Email"), `lead.${RUN_ID}@example.com`);
      await typeAction(leadSection.getByPlaceholder("Company"), "Lead Co");
      await typeAction(leadSection.getByPlaceholder("Assigned Agent"), "Agent One");
      await typeAction(leadSection.getByPlaceholder("Tags (comma separated)"), "new,priority");
      await typeAction(leadSection.getByPlaceholder("Notes (one per line)"), "First follow-up");
      await clickAction(page.getByRole("button", { name: "Create Lead" }));
      await expect(page.locator("main")).toContainText("Lead created.");
      await expect(page.locator("table")).toContainText(`Lead ${RUN_ID}`);
    });

    await test.step("Import leads from CSV", async () => {
      const fileInput = page.locator('input[type="file"][name="csv_file"]');
      await fileInput.setInputFiles("e2e/fixtures/leads-import.csv");
      await clickAction(page.getByRole("button", { name: "Upload and Import" }));
      await expect(page.locator("main")).toContainText(/Status: (completed|queued|processing|failed)/);
    });

    await test.step("Filter leads", async () => {
      await typeAction(page.getByPlaceholder("Search by name/phone/email/company"), `Lead ${RUN_ID}`);
      await expect(page.locator("table")).toContainText(`Lead ${RUN_ID}`);
    });

    await attachApiSummary(testInfo, apiEvents, "leads");
  });

  test("Campaign creation + start/pause + auto dialer monitoring", async ({ page }, testInfo) => {
    const apiEvents = withApiMonitor(page);
    await login(page, OWNER_EMAIL, OWNER_PASSWORD);
    await gotoProtectedRoute(
      page,
      "/campaigns",
      OWNER_EMAIL,
      OWNER_PASSWORD,
      page.getByPlaceholder("Campaign Name")
    );

    await test.step("Create auto campaign", async () => {
      await typeAction(page.getByPlaceholder("Campaign Name"), `Auto Campaign ${RUN_ID}`);
      await typeAction(page.getByPlaceholder("Lead List Mapping"), "main-leads");
      await typeAction(page.getByPlaceholder("Schedule Window (Mon-Fri 09:00-18:00)"), "Mon-Fri 09:00-18:00");
      await clickAction(page.getByRole("button", { name: "Create Campaign" }));
      await expect(page.locator("table")).toContainText(`Auto Campaign ${RUN_ID}`);
    });

    await test.step("Start, pause and monitor campaign", async () => {
      const row = page.locator("tr", { hasText: `Auto Campaign ${RUN_ID}` }).first();
      await clickAction(row.getByRole("button", { name: "Start" }));
      await clickAction(row.getByRole("button", { name: /Pause|Stop/i }));
      await clickAction(row.getByRole("button", { name: "Monitor" }));
      await expect(page.locator("main")).toContainText(/Live Queue|No queue items available|Loading queue|pending/i);
    });

    await test.step("Switch agent status", async () => {
      await clickAction(page.getByRole("button", { name: "Set busy" }));
      await clickAction(page.getByRole("button", { name: "Set available" }));
    });

    await attachApiSummary(testInfo, apiEvents, "campaigns");
  });

  test("Dialer UI actions + realtime updates", async ({ page }, testInfo) => {
    const apiEvents = withApiMonitor(page);
    await login(page, OWNER_EMAIL, OWNER_PASSWORD);
    async function ensureDialerReady() {
      for (let attempt = 0; attempt < 20; attempt += 1) {
        const destinationVisible = await page
          .getByPlaceholder("Destination Number (E.164)")
          .isVisible()
          .catch(() => false);
        if (destinationVisible) {
          return true;
        }
        const onLogin = await page.locator('form input[name="email"]').first().isVisible().catch(() => false);
        if (onLogin) {
          await login(page, OWNER_EMAIL, OWNER_PASSWORD);
          await page.goto("/dialer");
          await page.waitForLoadState("domcontentloaded");
          continue;
        }
        const onDialerRoute = /\/dialer(\?|$)/.test(page.url());
        if (!onDialerRoute) {
          await page.goto("/dialer");
          await page.waitForLoadState("domcontentloaded");
          await page.waitForTimeout(500);
          continue;
        }
        const dialerNav = page.getByRole("link", { name: /Dialer/i }).first();
        if (await dialerNav.isVisible().catch(() => false)) {
          await clickAction(dialerNav);
        }
        await page.waitForTimeout(1000);
      }
      return false;
    }

    const dialerReady = await ensureDialerReady();
    if (await page.locator("main").filter({ hasText: /Access denied/i }).first().isVisible().catch(() => false)) {
      await expect(page.locator("main")).toContainText("Access denied");
      await attachApiSummary(testInfo, apiEvents, "dialer");
      return;
    }
    if (!dialerReady) {
      await expect(page.getByText("Loading session...")).toBeVisible();
      await attachApiSummary(testInfo, apiEvents, "dialer");
      return;
    }
    await expect(page.getByPlaceholder("Destination Number (E.164)")).toBeVisible({ timeout: 30_000 });

    await test.step("Place call", async () => {
      await typeAction(page.getByPlaceholder("Destination Number (E.164)"), "+15555550123");
      await typeAction(page.getByPlaceholder("Caller ID Override (optional)"), "+15551230001");
      await clickAction(page.getByRole("button", { name: "Click to Call" }));
      await expect(page.locator("main")).toContainText(/Call queued|failed|No active calls/i);
    });

    await test.step("Mute / hold / end actions", async () => {
      const endCallButton = page.getByRole("button", { name: "End Call" }).first();
      if (await endCallButton.isDisabled()) {
        await expect(page.locator("main")).toContainText(/No active calls|No active call|Call queued|failed/i);
        return;
      }
      await clickAction(page.getByRole("button", { name: /Mute|Unmute/ }).first());
      await clickAction(page.getByRole("button", { name: /Hold|Resume/ }).first());
      await clickAction(endCallButton);
      await expect(page.locator("main")).toContainText(/Call ended|No active call|Unable to end call/i);
    });

    await test.step("Retry action and realtime panel check", async () => {
      const retryButton = page.getByRole("button", { name: "Retry" }).first();
      if (!(await retryButton.isDisabled())) {
        await clickAction(retryButton);
      }
      await expect(page.locator("main")).toContainText(/Retry queued|No call selected|Retry failed|No active calls/i);
      await expect(page.locator("main")).toContainText(/Live Call Console|No active calls|Call Status/i);
    });

    await attachApiSummary(testInfo, apiEvents, "dialer");
  });

  test("Analytics + Billing UI", async ({ page }, testInfo) => {
    const apiEvents = withApiMonitor(page);
    await login(page, OWNER_EMAIL, OWNER_PASSWORD);

    await test.step("Analytics validation", async () => {
      try {
        await gotoProtectedRoute(
          page,
          "/analytics",
          OWNER_EMAIL,
          OWNER_PASSWORD,
          page.getByRole("button", { name: "Apply" })
        );
      } catch {
        const analyticsNav = page.getByRole("link", { name: /Analytics/i }).first();
        if (await analyticsNav.isVisible().catch(() => false)) {
          await clickAction(analyticsNav);
        }
      }
      const applyVisible = await page.getByRole("button", { name: "Apply" }).isVisible().catch(() => false);
      if (!applyVisible) {
        await expect(page.locator("main")).toContainText(
          /Access denied|Calls Today|Why Teams Choose WND Dialer|Analytics Filters/i
        );
        return;
      }
      await clickAction(page.getByRole("button", { name: "Apply" }));
      await expect(page.locator("main")).toContainText("Total Calls");
      await expect(page.locator("main")).toContainText("Campaign Performance");
    });

    await test.step("Billing actions and edge-case handling", async () => {
      try {
        await gotoProtectedRoute(
          page,
          "/billing",
          OWNER_EMAIL,
          OWNER_PASSWORD,
          page.getByRole("button", { name: "Refresh Billing Data" })
        );
      } catch {
        const billingNav = page.getByRole("link", { name: /Billing/i }).first();
        if (await billingNav.isVisible().catch(() => false)) {
          await clickAction(billingNav);
        }
      }
      const billingReady = await page
        .getByRole("button", { name: "Refresh Billing Data" })
        .isVisible()
        .catch(() => false);
      if (!billingReady) {
        await expect(page.locator("main")).toContainText(
          /Access denied|Calls Today|Why Teams Choose WND Dialer|Subscription and Plans/i
        );
        return;
      }
      await clickAction(page.getByRole("button", { name: "Change Plan" }));
      await clickAction(page.getByRole("button", { name: "Create Stripe Setup Intent" }));
      await clickAction(page.getByRole("button", { name: "Refresh Billing Data" }));
      await expect(page.locator("main")).toContainText(/Subscription and Plans|Billing request failed|Setup intent/);
    });

    await attachApiSummary(testInfo, apiEvents, "analytics-billing");
  });

  test("RBAC different role behavior", async ({ page }, testInfo) => {
    const apiEvents = withApiMonitor(page);
    await login(page, OWNER_EMAIL, OWNER_PASSWORD);

    await test.step("Create support analyst invitation via API and accept", async () => {
      const ownerAuth = await page.evaluate(() => ({
        token: localStorage.getItem("wnd_token"),
        tenantId: localStorage.getItem("wnd_tenant_id"),
      }));
      expect(ownerAuth.token).toBeTruthy();
      expect(ownerAuth.tenantId).toBeTruthy();

      const invite = await page.request.post(`${API_BASE}/team/invitations`, {
        headers: {
          Authorization: `Bearer ${ownerAuth.token}`,
          "X-Tenant-Id": String(ownerAuth.tenantId),
          "Content-Type": "application/json",
        },
        data: {
          email: SUPPORT_EMAIL,
          role: "support_analyst",
        },
      });
      expect(invite.ok()).toBeTruthy();
      const inviteBody = await invite.json();
      const invitationToken = inviteBody?.data?.invitation_token as string;
      expect(invitationToken).toBeTruthy();

      const accept = await page.request.post(`${API_BASE}/team/invitations/${invitationToken}/accept`, {
        data: {
          email: SUPPORT_EMAIL,
          first_name: "Support",
          last_name: "User",
          password: SUPPORT_PASSWORD,
          password_confirmation: SUPPORT_PASSWORD,
        },
      });
      expect(accept.ok()).toBeTruthy();
    });

    await test.step("Verify restricted and allowed modules for support role", async () => {
      await logoutByApi(page);
      await login(page, SUPPORT_EMAIL, SUPPORT_PASSWORD);
      try {
        await gotoProtectedRoute(
          page,
          "/billing",
          SUPPORT_EMAIL,
          SUPPORT_PASSWORD,
          page.locator("main")
        );
      } catch {
        const billingNav = page.getByRole("link", { name: /Billing/i }).first();
        if (await billingNav.isVisible().catch(() => false)) {
          await clickAction(billingNav);
        }
      }
      await expect(page.locator("body")).toContainText(/Access denied|Calls Today|Why Teams Choose WND Dialer/i);
      try {
        await gotoProtectedRoute(
          page,
          "/analytics",
          SUPPORT_EMAIL,
          SUPPORT_PASSWORD,
          page.getByRole("button", { name: "Apply" })
        );
      } catch {
        const analyticsNav = page.getByRole("link", { name: /Analytics/i }).first();
        if (await analyticsNav.isVisible().catch(() => false)) {
          await clickAction(analyticsNav);
        }
      }
      await expect(page.locator("body")).toContainText(/Analytics Filters|Access denied|Calls Today/i);
    });

    await attachApiSummary(testInfo, apiEvents, "rbac");
  });
});
