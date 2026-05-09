import fs from "node:fs/promises";
import path from "node:path";
import { chromium } from "@playwright/test";

const ROOT = "C:/xampp/htdocs/vicidial";
const SCREENSHOTS_DIR = path.join(ROOT, "features", "screenshots");
const INDEX_URL = "file:///C:/xampp/htdocs/vicidial/features/index.html";

async function ensureOutputDir() {
  await fs.mkdir(SCREENSHOTS_DIR, { recursive: true });
}

async function capture(page, fileName) {
  await page.screenshot({
    path: path.join(SCREENSHOTS_DIR, fileName),
    fullPage: true,
  });
}

async function clearFilters(page) {
  await page.locator("#searchInput").fill("");
  await page.locator("#roleFilter").selectOption("all");
  await page.locator("#statusFilter").selectOption("all");
  const microToggle = page.locator("#microToggle");
  if (await microToggle.isChecked()) {
    await microToggle.uncheck();
  }
}

async function waitForRender(page) {
  await page.waitForLoadState("domcontentloaded");
  await page.waitForFunction(() => {
    const sections = document.querySelectorAll("details.role-section");
    const metricsReady = Boolean(document.querySelector("#visibleCount")?.textContent?.trim());
    return sections.length > 0 && metricsReady;
  });
}

async function run() {
  await ensureOutputDir();

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    deviceScaleFactor: 1.5,
  });
  const page = await context.newPage();

  try {
    await page.goto(INDEX_URL);
    await waitForRender(page);
    await capture(page, "33-index-overview-default.png");

    await page.locator("#searchInput").fill("billing");
    await page.waitForTimeout(250);
    await capture(page, "34-index-search-filter-billing.png");

    await clearFilters(page);
    await page.locator("#roleFilter").selectOption({ label: "Company Admin" });
    await page.waitForTimeout(250);
    await capture(page, "35-index-role-filter-company-admin.png");

    await clearFilters(page);
    await page.locator("#statusFilter").selectOption("Partial");
    await page.waitForTimeout(250);
    await capture(page, "36-index-status-filter-partial.png");

    await clearFilters(page);
    await page.locator("#microToggle").check();
    await page.waitForTimeout(250);
    await capture(page, "37-index-include-micro-features.png");

    await page.locator("#searchInput").fill("zzzz-no-match");
    await page.waitForTimeout(250);
    await capture(page, "38-index-empty-state-no-match.png");

    await clearFilters(page);
    await page.waitForTimeout(200);
    await page.locator("details.role-section:nth-of-type(2) > summary").click();
    await page.waitForTimeout(250);
    await capture(page, "39-index-role-accordion-expanded.png");

    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");
    await page.waitForTimeout(250);
    await capture(page, "40-index-keyboard-focus-search.png");

    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(350);
    await capture(page, "41-index-responsive-mobile-layout.png");
  } finally {
    await context.close();
    await browser.close();
  }
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
