import { expect, Page, APIRequestContext } from "@playwright/test";

export const API_BASE = process.env.E2E_API_BASE_URL ?? "http://localhost:8000/api/v1";
const PASSWORD = "Password123!";

export type AuthContext = {
  email: string;
  password: string;
  token: string;
  tenantId: string;
  permissions: string[];
};

export type TenantUsers = {
  owner: AuthContext;
  support: AuthContext;
};

export function runKey(prefix: string): string {
  return `${prefix}-${Date.now().toString().slice(-6)}-${Math.floor(Math.random() * 1000)}`;
}

async function loginByApi(request: APIRequestContext, email: string, password: string) {
  const login = await request.post(`${API_BASE}/auth/login`, {
    data: { email, password },
    timeout: 45000,
  });
  expect(login.ok(), `login failed for ${email}`).toBeTruthy();
  const body = await login.json();
  return body?.data;
}

export async function registerOwnerByApi(request: APIRequestContext, key: string): Promise<AuthContext> {
  const email = `owner.${key}@example.com`;
  const register = await request.post(`${API_BASE}/auth/register`, {
    data: {
      company_name: `E2E ${key}`,
      first_name: "Owner",
      last_name: "Admin",
      email,
      password: PASSWORD,
      password_confirmation: PASSWORD,
      timezone: "UTC",
    },
    timeout: 45000,
  });
  expect(register.ok(), "owner register should succeed").toBeTruthy();
  const body = await register.json();

  return {
    email,
    password: PASSWORD,
    token: String(body?.data?.token ?? ""),
    tenantId: String(body?.data?.tenant?.id ?? ""),
    permissions: [],
  };
}

export async function createSupportUserByApi(request: APIRequestContext, owner: AuthContext, key: string): Promise<AuthContext> {
  const supportEmail = `support.${key}@example.com`;

  const invite = await request.post(`${API_BASE}/team/invitations`, {
    headers: {
      Authorization: `Bearer ${owner.token}`,
      "X-Tenant-Id": owner.tenantId,
      "Content-Type": "application/json",
    },
    data: {
      email: supportEmail,
      role: "support_analyst",
    },
    timeout: 45000,
  });
  expect(invite.ok(), "support invite should succeed").toBeTruthy();
  const inviteBody = await invite.json();
  const invitationToken = String(inviteBody?.data?.invitation_token ?? "");
  expect(invitationToken).not.toEqual("");

  const accept = await request.post(`${API_BASE}/team/invitations/${invitationToken}/accept`, {
    data: {
      email: supportEmail,
      first_name: "Support",
      last_name: "Analyst",
      password: PASSWORD,
      password_confirmation: PASSWORD,
    },
    timeout: 45000,
  });
  expect(accept.ok(), "support accept should succeed").toBeTruthy();

  const loginData = await loginByApi(request, supportEmail, PASSWORD);
  const tenantId = String(loginData?.memberships?.[0]?.tenant_id ?? owner.tenantId);

  return {
    email: supportEmail,
    password: PASSWORD,
    token: String(loginData?.token ?? ""),
    tenantId,
    permissions: [],
  };
}

export async function getPermissions(request: APIRequestContext, auth: AuthContext): Promise<string[]> {
  const me = await request.get(`${API_BASE}/auth/me`, {
    headers: {
      Authorization: `Bearer ${auth.token}`,
      "X-Tenant-Id": auth.tenantId,
    },
    timeout: 45000,
  });
  expect(me.ok(), "auth/me should succeed").toBeTruthy();
  const body = await me.json();
  return Array.isArray(body?.data?.permissions) ? body.data.permissions : [];
}

export async function createTenantUsers(request: APIRequestContext, key: string): Promise<TenantUsers> {
  const owner = await registerOwnerByApi(request, key);
  owner.permissions = await getPermissions(request, owner);
  const support = await createSupportUserByApi(request, owner, key);
  support.permissions = await getPermissions(request, support);
  return { owner, support };
}

export async function authenticatePage(page: Page, auth: AuthContext, route = "/") {
  await page.goto("/login");
  await page.evaluate(
    ({ token, tenantId }) => {
      localStorage.setItem("wnd_token", token);
      localStorage.setItem("wnd_tenant_id", tenantId);
    },
    { token: auth.token, tenantId: auth.tenantId }
  );
  await page.goto(route);
  await page.waitForLoadState("domcontentloaded");
}

export async function waitForAuthResolution(page: Page, timeoutMs = 60000): Promise<"login" | "main" | "loading"> {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const loginVisible = await page.locator('input[name="email"]').first().isVisible().catch(() => false);
    if (loginVisible) {
      return "login";
    }
    const mainVisible = await page.locator("main").first().isVisible().catch(() => false);
    if (mainVisible) {
      const bodyText = await page.locator("body").innerText().catch(() => "");
      if (!/Loading session\.\.\./i.test(bodyText)) {
        return "main";
      }
    }
    await page.waitForTimeout(500);
  }
  return "loading";
}

export async function logoutPage(page: Page, auth?: AuthContext) {
  if (auth?.token && auth.tenantId) {
    try {
      await page.request.post(`${API_BASE}/auth/logout`, {
        headers: {
          Authorization: `Bearer ${auth.token}`,
          "X-Tenant-Id": auth.tenantId,
        },
        timeout: 5000,
      });
    } catch {
      // Ignore logout network failures during teardown.
    }
  }
  await page.goto("/login");
  await page.evaluate(() => {
    localStorage.removeItem("wnd_token");
    localStorage.removeItem("wnd_tenant_id");
  });
}

export async function expectGuestBlocked(page: Page, route: string) {
  await page.goto(route);
  await page.waitForLoadState("domcontentloaded");
  await expect(page.locator('input[name="email"]').first()).toBeVisible({ timeout: 30000 });
}

export async function expectRouteAccessByPermission(
  page: Page,
  auth: AuthContext,
  route: string,
  requiredPermissions: string[]
) {
  await authenticatePage(page, auth, route);
  const authState = await waitForAuthResolution(page);
  const shouldAccess = requiredPermissions.every((permission) => auth.permissions.includes(permission));
  if (authState === "loading") {
    await expect(page.locator("body")).toContainText(/Loading session\.\.\.|Access denied/i);
    return;
  }
  const loginVisible = await page.locator('input[name="email"]').first().isVisible().catch(() => false);
  const main = page.locator("main").first();
  const mainVisible = await main.isVisible().catch(() => false);

  if (shouldAccess) {
    expect(loginVisible, `${auth.email} was redirected to login for ${route}`).toBeFalsy();
    if (mainVisible) {
      await expect(main).not.toContainText(/Access denied/i, { timeout: 30000 });
      return;
    }
    await expect(page.locator("body")).not.toContainText(/Access denied/i, { timeout: 30000 });
    return;
  }

  if (loginVisible) {
    await expect(page).toHaveURL(/\/login/);
    return;
  }
  if (mainVisible) {
    await expect(main).toContainText(/Access denied/i, { timeout: 30000 });
    return;
  }
  await expect(page.locator("body")).toContainText(/Access denied|Loading session\.\.\./i, { timeout: 30000 });
}

export async function captureOnFailure(page: Page, testName: string) {
  await page.screenshot({
    path: `test-results/artifacts/${testName.replace(/[^a-zA-Z0-9-]/g, "_")}.png`,
    fullPage: true,
  });
}
