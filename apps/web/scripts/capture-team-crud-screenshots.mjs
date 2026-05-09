import fs from "node:fs/promises";
import path from "node:path";
import { chromium } from "@playwright/test";

const ROOT = "C:/xampp/htdocs/vicidial";
const BASE_URL = process.env.E2E_BASE_URL ?? "http://localhost:3000";
const API_BASE = process.env.E2E_API_BASE_URL ?? "http://localhost:8000/api/v1";
const PASSWORD = "Password123!";
const CRUD_DIR = path.join(ROOT, "features", "screenshots");

async function registerOwner() {
  const key = `${Date.now().toString().slice(-6)}${Math.floor(Math.random() * 1000)}`;
  const email = `crud.owner.${key}@example.com`;
  const body = {
    company_name: `CRUD Tenant ${key}`,
    first_name: "Crud",
    last_name: "Owner",
    email,
    password: PASSWORD,
    password_confirmation: PASSWORD,
    timezone: "UTC",
  };

  const response = await fetch(`${API_BASE}/auth/register`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    throw new Error(`Registration failed (${response.status}): ${await response.text()}`);
  }

  const payload = await response.json();
  const token = payload?.data?.token;
  const tenantId = payload?.data?.tenant?.id;
  if (!token || !tenantId) {
    throw new Error("Registration response did not include token and tenant id.");
  }

  return { email, password: PASSWORD, token, tenantId };
}

async function ensureOutputDir() {
  await fs.mkdir(CRUD_DIR, { recursive: true });
}

async function screenshot(page, fileName) {
  await page.screenshot({
    path: path.join(CRUD_DIR, fileName),
    fullPage: true,
  });
}

async function captureCrudFlow() {
  await ensureOutputDir();
  const auth = await registerOwner();

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    deviceScaleFactor: 1.5,
  });
  await context.addInitScript(
    ({ token, tenantId }) => {
      window.localStorage.setItem("wnd_token", token);
      window.localStorage.setItem("wnd_tenant_id", tenantId);
    },
    { token: auth.token, tenantId: auth.tenantId }
  );
  const page = await context.newPage();

  try {
    await page.goto(`${BASE_URL}/team`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(1800);

    const bodyText = await page.locator("body").innerText().catch(() => "");
    console.log("TEAM_URL", page.url());
    console.log("TEAM_BODY_PREVIEW", String(bodyText).slice(0, 320).replace(/\s+/g, " "));

    const inviteHeading = page.getByText("Invite Team Member");
    const teamHeading = page.getByText("Team Directory");
    const hasInvite = await inviteHeading.isVisible().catch(() => false);
    const hasTeam = await teamHeading.isVisible().catch(() => false);
    if (!hasInvite && !hasTeam) {
      await screenshot(page, "29-team-crud-read-directory.png");
      throw new Error("Team page did not render CRUD controls after authentication.");
    }

    await screenshot(page, "29-team-crud-read-directory.png");

    const inviteEmail = `team.member.${Date.now()}@example.com`;
    const invitedUserRows = page.locator("tr", { hasText: "Invited user" });
    const invitedBefore = await invitedUserRows.count();
    await page.getByPlaceholder("teammate@company.com").fill(inviteEmail);
    await page.getByRole("button", { name: "Send Invite" }).click();
    await page.waitForTimeout(1200);
    for (let i = 0; i < 20; i += 1) {
      const invitedAfter = await invitedUserRows.count();
      if (invitedAfter > invitedBefore) {
        break;
      }
      await page.waitForTimeout(300);
    }
    await screenshot(page, "30-team-crud-create-invite.png");

    const invitedRow = page.locator("tr", { hasText: "Invited user" }).first();
    await invitedRow.waitFor({ timeout: 15000 });
    await invitedRow.locator("[role='combobox']").first().click();
    await page.getByRole("option", { name: "Disabled" }).click();
    await invitedRow.getByRole("button", { name: "Save" }).click();
    await page.waitForTimeout(1200);
    await screenshot(page, "31-team-crud-update-member-status.png");

    await invitedRow.getByRole("button", { name: "Remove" }).click();
    await page.waitForTimeout(1200);
    await screenshot(page, "32-team-crud-delete-member.png");
  } finally {
    await context.close();
    await browser.close();
  }
}

captureCrudFlow().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
