import { defineConfig, devices } from "@playwright/test";

const baseURL = process.env.E2E_BASE_URL ?? "http://localhost:3000";
const isCI = process.env.CI === "true";
const crossBrowser = process.env.E2E_CROSS_BROWSER === "true";
const headed = process.env.E2E_HEADED === "true";
const fullyParallel = process.env.E2E_FULLY_PARALLEL === "true";
const configuredWorkers = Number(process.env.E2E_WORKERS ?? 0);
const configuredViewportWidth = Number(process.env.E2E_VIEWPORT_WIDTH ?? 0);
const configuredViewportHeight = Number(process.env.E2E_VIEWPORT_HEIGHT ?? 0);
const viewportWidth =
  Number.isFinite(configuredViewportWidth) && configuredViewportWidth > 0
    ? configuredViewportWidth
    : 1440;
const viewportHeight =
  Number.isFinite(configuredViewportHeight) && configuredViewportHeight > 0
    ? configuredViewportHeight
    : 900;
const workers = Number.isFinite(configuredWorkers) && configuredWorkers > 0
  ? configuredWorkers
  : isCI
    ? 2
    : 1;

const projects = crossBrowser
  ? [
      {
        name: "chromium",
        use: { ...devices["Desktop Chrome"] },
      },
      {
        name: "firefox",
        use: { ...devices["Desktop Firefox"] },
      },
      {
        name: "webkit",
        use: { ...devices["Desktop Safari"] },
      },
    ]
  : [
      {
        name: "chromium",
        use: { ...devices["Desktop Chrome"] },
      },
    ];

export default defineConfig({
  testDir: "./e2e",
  fullyParallel,
  workers,
  forbidOnly: isCI,
  timeout: 180_000,
  expect: {
    timeout: 15_000,
  },
  retries: isCI ? 1 : 0,
  outputDir: "test-results/artifacts",
  reporter: [
    ["list"],
    ["html", { open: "never" }],
    ["json", { outputFile: "test-results/results.json" }],
    ["junit", { outputFile: "test-results/junit.xml" }],
  ],
  use: {
    baseURL,
    headless: !headed,
    viewport: { width: viewportWidth, height: viewportHeight },
    actionTimeout: 20_000,
    navigationTimeout: 30_000,
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
    video: "retain-on-failure",
  },
  projects,
});
