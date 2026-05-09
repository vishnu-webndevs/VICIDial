import path from "node:path";
import { chromium, firefox, webkit } from "@playwright/test";

const ROOT = "C:/xampp/htdocs/vicidial";
const URL = "file:///C:/xampp/htdocs/vicidial/features/how-to-use.html";
const OUTPUT_PREFIX = path.join(ROOT, "features", "screenshots", "guide-smoke");

const browsers = [
  { name: "chromium", launcher: chromium },
  { name: "firefox", launcher: firefox },
  { name: "webkit", launcher: webkit }
];

const viewports = [
  { name: "desktop", size: { width: 1440, height: 900 } },
  { name: "tablet", size: { width: 834, height: 1112 } },
  { name: "mobile", size: { width: 390, height: 844 } }
];

async function verifyCoreInteractions(page) {
  await page.waitForLoadState("domcontentloaded");
  await page.waitForSelector("#guideSearch");
  await page.waitForSelector("#categoryFilter");
  await page.waitForSelector("#difficultyFilter");
  await page.waitForSelector("#topicFilter");

  await page.locator("#guideSearch").fill("billing");
  await page.waitForTimeout(250);

  const visibleAfterSearch = await page.locator(".section[id]:not(.filtered-out)").count();
  if (visibleAfterSearch < 1) {
    throw new Error("Search did not return visible sections.");
  }

  await page.locator("#difficultyFilter").selectOption("advanced");
  await page.waitForTimeout(250);

  const visibleAfterDifficulty = await page.locator(".section[id]:not(.filtered-out)").count();
  if (visibleAfterDifficulty < 1) {
    throw new Error("Difficulty filter removed all sections unexpectedly.");
  }

  await page.locator("#guideSearch").fill("no-match-token-xyz");
  await page.waitForTimeout(250);
  const emptyVisible = await page.locator("#emptyState.visible").count();
  if (emptyVisible !== 1) {
    throw new Error("Empty-state panel did not appear for a no-match query.");
  }

  await page.locator("#resetFilters").click();
  await page.waitForTimeout(250);
}

async function run() {
  const report = [];

  for (const browserSpec of browsers) {
    let browser;
    try {
      browser = await browserSpec.launcher.launch({ headless: true });
    } catch (error) {
      report.push({
        browser: browserSpec.name,
        status: "skipped",
        reason: String(error.message || error)
      });
      continue;
    }

    try {
      for (const viewport of viewports) {
        const context = await browser.newContext({
          viewport: viewport.size,
          deviceScaleFactor: 1.25
        });
        const page = await context.newPage();
        await page.goto(URL);
        await verifyCoreInteractions(page);
        await page.screenshot({
          path: `${OUTPUT_PREFIX}-${browserSpec.name}-${viewport.name}.png`,
          fullPage: true
        });
        await context.close();
      }
      report.push({ browser: browserSpec.name, status: "passed" });
    } catch (error) {
      report.push({
        browser: browserSpec.name,
        status: "failed",
        reason: String(error.message || error)
      });
    } finally {
      await browser.close();
    }
  }

  for (const item of report) {
    if (item.status === "passed") {
      console.log(`[PASS] ${item.browser}`);
    } else {
      console.log(`[${item.status.toUpperCase()}] ${item.browser}: ${item.reason}`);
    }
  }

  const hasFailure = report.some((item) => item.status === "failed");
  if (hasFailure) {
    process.exitCode = 1;
  }
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
