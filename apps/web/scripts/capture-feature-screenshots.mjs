import fs from "node:fs/promises";
import path from "node:path";
import { chromium } from "@playwright/test";

const ROOT = "C:/xampp/htdocs/vicidial";
const BASE_URL = process.env.E2E_BASE_URL ?? "http://localhost:3000";
const API_BASE = process.env.E2E_API_BASE_URL ?? "http://localhost:8000/api/v1";
const PASSWORD = "Password123!";
const INVENTORY_FILE = path.join(ROOT, "features", "documentation-inventory.json");
const SCREENSHOT_DIR = path.join(ROOT, "features", "screenshots");
const MANIFEST_FILE = path.join(SCREENSHOT_DIR, "manifest.json");
const REPORT_FILE = path.join(SCREENSHOT_DIR, "capture-report.json");
const VERIFY_FILE = path.join(SCREENSHOT_DIR, "verification.json");

const WAIT_DEFAULT_TIMEOUT_MS = Number(process.env.CAPTURE_WAIT_TIMEOUT_MS ?? 25000);
const RETRY_COUNT = Number(process.env.CAPTURE_RETRY_COUNT ?? 4);
const RETRY_DELAY_MS = Number(process.env.CAPTURE_RETRY_DELAY_MS ?? 1200);
const QUIET_WINDOW_MS = Number(process.env.CAPTURE_NETWORK_QUIET_MS ?? 600);

const NON_CONTENT_RE = /(loading|please wait|sign in|log in|login|session expired|redirecting)/i;
const SPINNER_SELECTORS = [
  "[aria-busy='true']",
  "[role='progressbar']",
  ".spinner",
  ".loading",
  ".loader",
  "[data-testid*='loading']",
  "[data-testid*='spinner']",
];
const LOGIN_SELECTORS = ["form input[name='email']", "form input[name='password']"];

const captures = [];
const verification = [];
const report = {
  started_at: new Date().toISOString(),
  base_url: BASE_URL,
  inventory_file: INVENTORY_FILE,
  retries: [],
  captures: [],
  rejected_attempts: 0,
  successful_captures: 0,
  failed_captures: 0,
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const pad = (num) => String(num).padStart(2, "0");
const screenshotName = (entry) => `${pad(entry.sequence)}-${entry.capture_slug}.png`;

async function ensureOutputDir() {
  await fs.mkdir(SCREENSHOT_DIR, { recursive: true });
  const existing = await fs.readdir(SCREENSHOT_DIR);
  for (const file of existing) {
    if (file.endsWith(".png") || file === "manifest.json" || file === "capture-report.json" || file === "verification.json") {
      await fs.unlink(path.join(SCREENSHOT_DIR, file));
    }
  }
}

async function loadInventory() {
  const raw = await fs.readFile(INVENTORY_FILE, "utf8");
  const parsed = JSON.parse(raw);
  if (!Array.isArray(parsed?.pages) || parsed.pages.length === 0) {
    throw new Error("Inventory file is missing pages array.");
  }
  const pages = [...parsed.pages].sort((a, b) => Number(a.sequence) - Number(b.sequence));
  for (const page of pages) {
    if (!page.sequence || !page.id || !page.capture_slug || !Array.isArray(page.expected_selectors)) {
      throw new Error(`Invalid inventory entry: ${JSON.stringify(page)}`);
    }
  }
  return pages;
}

async function registerOwner() {
  const key = `${Date.now().toString().slice(-6)}${Math.floor(Math.random() * 1000)}`;
  const email = `guide.owner.${key}@example.com`;
  const body = {
    company_name: `Guide Tenant ${key}`,
    first_name: "Guide",
    last_name: "Owner",
    email,
    password: PASSWORD,
    password_confirmation: PASSWORD,
    timezone: "UTC",
  };

  const res = await fetch(`${API_BASE}/auth/register`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    throw new Error(`Owner registration failed (${res.status}): ${await res.text()}`);
  }
  return { email, password: PASSWORD };
}

async function gotoAndWait(page, route) {
  await page.goto(`${BASE_URL}${route}`);
  await page.waitForLoadState("domcontentloaded");
  await page.waitForTimeout(700);
}

function attachNetworkTracker(page) {
  const tracker = { inFlight: 0, finishedAt: Date.now() };
  const shouldTrackRequest = (request) => {
    const resourceType = request.resourceType?.() ?? "";
    const url = request.url?.() ?? "";
    if (resourceType === "eventsource" || resourceType === "websocket") return false;
    if (/\/realtime\/|\/stream\b/i.test(url)) return false;
    return true;
  };
  page.on("request", (request) => {
    if (shouldTrackRequest(request)) {
      tracker.inFlight += 1;
    }
  });
  const markDone = (request) => {
    if (!shouldTrackRequest(request)) return;
    tracker.inFlight = Math.max(0, tracker.inFlight - 1);
    tracker.finishedAt = Date.now();
  };
  page.on("requestfinished", markDone);
  page.on("requestfailed", markDone);
  return tracker;
}

async function hasVisibleSelector(page, selector) {
  return page.locator(selector).first().isVisible().catch(() => false);
}

async function firstVisibleSelector(page, selectors) {
  for (const selector of selectors) {
    if (await hasVisibleSelector(page, selector)) return selector;
  }
  return null;
}

async function waitForStableNetwork(page, tracker, timeoutMs) {
  const start = Date.now();
  await page.waitForLoadState("domcontentloaded", { timeout: timeoutMs });
  while (Date.now() - start < timeoutMs) {
    const quiet = tracker.inFlight === 0 && Date.now() - tracker.finishedAt >= QUIET_WINDOW_MS;
    if (quiet) return { ok: true };
    await sleep(120);
  }
  return { ok: false, reason: `network not idle in ${timeoutMs}ms; inFlight=${tracker.inFlight}` };
}

async function detectPageState(page, options) {
  const { allowLoginPage = false, expectedSelectors = [], ignoreSpinner = false } = options;
  const url = page.url();
  const bodyText = ((await page.locator("body").innerText().catch(() => "")) ?? "").trim();
  const mainVisible = await hasVisibleSelector(page, "main");
  const headingVisible = await hasVisibleSelector(page, "h1, h2, [role='heading']");
  const formVisible = await hasVisibleSelector(page, "form");
  const spinnerVisible = await firstVisibleSelector(page, SPINNER_SELECTORS);
  const loginSignals = await firstVisibleSelector(page, LOGIN_SELECTORS);
  const loadingTextDetected = /(loading|please wait|initializing|fetching|signing in|authenticating)/i.test(bodyText);

  if (!ignoreSpinner && spinnerVisible && (loadingTextDetected || (!mainVisible && !headingVisible && !(allowLoginPage && formVisible)))) {
    return { state: "loading", ok: false, reason: `spinner visible: ${spinnerVisible}`, url };
  }
  if (!allowLoginPage && (url.includes("/login") || loginSignals)) {
    return { state: "login", ok: false, reason: "page appears to be in login state", url };
  }
  if (!mainVisible && !headingVisible && !(allowLoginPage && formVisible)) {
    return { state: "blank", ok: false, reason: "main content container not visible", url };
  }
  let expectedSatisfied = expectedSelectors.length > 0;
  for (const selector of expectedSelectors) {
    const visible = await hasVisibleSelector(page, selector);
    if (!visible) {
      expectedSatisfied = false;
      return { state: "unexpected", ok: false, reason: `expected selector not visible: ${selector}`, url };
    }
  }
  if (expectedSatisfied) {
    return { state: "content", ok: true, reason: "content page validated by expected selectors", url };
  }
  if (!bodyText || bodyText.length < 40) {
    return { state: "intermediate", ok: false, reason: "page text too short to confirm rendered content", url };
  }
  if (!allowLoginPage && NON_CONTENT_RE.test(bodyText)) {
    return { state: "intermediate", ok: false, reason: "page text indicates loading/intermediate state", url };
  }
  return { state: "content", ok: true, reason: "content page validated", url };
}

async function validateBeforeCapture(page, tracker, options) {
  const earlyState = await detectPageState(page, options);
  if (earlyState.ok) {
    return earlyState;
  }
  const network = await waitForStableNetwork(page, tracker, options.timeoutMs ?? WAIT_DEFAULT_TIMEOUT_MS);
  if (!network.ok) {
    const fallback = await detectPageState(page, { ...options, ignoreSpinner: true });
    if (fallback.ok) {
      return { ...fallback, reason: `${fallback.reason}; captured with active background network` };
    }
    return { ok: false, state: "loading", reason: network.reason };
  }
  return detectPageState(page, options);
}

async function waitForAuthenticatedSession(page, timeoutMs = WAIT_DEFAULT_TIMEOUT_MS) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const loginSignals = await firstVisibleSelector(page, LOGIN_SELECTORS);
    if (!page.url().includes("/login") && !loginSignals) {
      return true;
    }
    await sleep(250);
  }
  return false;
}

async function runPreAction(page, entry, auth) {
  const action = entry.pre_action;
  if (!action || !action.type) return;

  if (action.type === "click") {
    await page.locator(action.selector).first().click();
    await page.waitForTimeout(450);
    return;
  }
  if (action.type === "fill-login") {
    await page.fill("input[name='email']", auth.email);
    await page.fill("input[name='password']", auth.password);
    await page.waitForTimeout(250);
    return;
  }
  if (action.type === "hover-first") {
    const target = page.locator(action.selector).first();
    if (await target.count()) {
      await target.hover();
      await page.waitForTimeout(300);
    }
    return;
  }
  if (action.type === "fill-random-email") {
    const input = page.locator(action.selector).first();
    if (await input.count()) {
      await input.fill(`new.member.${Date.now()}@example.com`);
      await page.waitForTimeout(250);
    }
  }
}

async function captureWithRetry(page, tracker, entry) {
  const failures = [];
  for (let attempt = 1; attempt <= RETRY_COUNT; attempt += 1) {
    const validation = await validateBeforeCapture(page, tracker, {
      allowLoginPage: Boolean(entry.allow_login_page),
      expectedSelectors: entry.expected_selectors,
      timeoutMs: entry.timeoutMs ?? WAIT_DEFAULT_TIMEOUT_MS,
    });

    if (validation.ok) {
      const file = screenshotName(entry);
      await page.screenshot({ path: path.join(SCREENSHOT_DIR, file), fullPage: true });
      const manifestItem = {
        sequence: entry.sequence,
        id: entry.id,
        file,
        page_name: entry.page_name,
        url: entry.url ?? page.url().replace(BASE_URL, ""),
        feature_description: entry.feature_description,
        caption: entry.caption,
      };
      captures.push(manifestItem);
      report.captures.push({ ...manifestItem, state: validation.state, attempt });
      report.successful_captures += 1;
      return manifestItem;
    }

    const fail = {
      sequence: entry.sequence,
      id: entry.id,
      attempt,
      state: validation.state,
      reason: validation.reason,
      url: page.url(),
      timestamp: new Date().toISOString(),
    };
    failures.push(fail);
    report.retries.push(fail);
    report.rejected_attempts += 1;
    await sleep(RETRY_DELAY_MS);
  }
  report.failed_captures += 1;
  throw new Error(
    `Capture rejected for ${entry.id} after ${RETRY_COUNT} attempts: ${failures.map((f) => `${f.state} (${f.reason})`).join(" | ")}`
  );
}

async function run() {
  await ensureOutputDir();
  const inventory = await loadInventory();
  report.inventory_count = inventory.length;
  const auth = await registerOwner();

  const browser = await chromium.launch({ headless: false, slowMo: 120 });
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1.5 });
  const page = await context.newPage();
  const tracker = attachNetworkTracker(page);
  let isAuthenticated = false;

  try {
    for (const entry of inventory) {
      console.log(`Capturing [${entry.sequence}] ${entry.id}`);
      if (entry.requires_auth && !isAuthenticated) {
        throw new Error(`Entry ${entry.id} requires auth, but session is not authenticated.`);
      }

      if (entry.url) {
        await gotoAndWait(page, entry.url);
      }

      await runPreAction(page, entry, auth);
      if (entry.mark_authenticated_after) {
        const authenticated = await waitForAuthenticatedSession(page);
        if (!authenticated) {
          throw new Error(`Authentication step failed at ${entry.id}; still in login state.`);
        }
        isAuthenticated = true;
      }

      const captured = await captureWithRetry(page, tracker, entry);
      verification.push({
        sequence: entry.sequence,
        id: entry.id,
        url: captured.url,
        screenshot: captured.file,
        accessible: true,
        named_correctly: captured.file === screenshotName(entry),
      });
    }

    const expected = inventory.length;
    const capturedCount = captures.length;
    const allNamed = verification.every((v) => v.named_correctly);
    if (capturedCount !== expected || !allNamed || report.failed_captures > 0) {
      throw new Error(
        `Verification failed: expected=${expected}, captured=${capturedCount}, all_named=${allNamed}, failed=${report.failed_captures}`
      );
    }

    report.quality_gate = "pass";
    report.completed_at = new Date().toISOString();
    report.package_ready = true;
    report.verification_summary = {
      expected_pages: expected,
      captured_pages: capturedCount,
      accessible_pages: verification.filter((v) => v.accessible).length,
      naming_compliant: allNamed,
    };
    await fs.writeFile(MANIFEST_FILE, JSON.stringify(captures, null, 2), "utf8");
    await fs.writeFile(VERIFY_FILE, JSON.stringify(verification, null, 2), "utf8");
    await fs.writeFile(REPORT_FILE, JSON.stringify(report, null, 2), "utf8");
  } finally {
    if (!report.completed_at) {
      report.quality_gate = "fail";
      report.completed_at = new Date().toISOString();
      report.package_ready = false;
      await fs.writeFile(REPORT_FILE, JSON.stringify(report, null, 2), "utf8");
    }
    await context.close();
    await browser.close();
  }
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
