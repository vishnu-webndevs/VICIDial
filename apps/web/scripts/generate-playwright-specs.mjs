import fs from "node:fs";
import path from "node:path";

const rootDir = process.cwd();
const matrixPath = path.resolve(rootDir, "e2e/generated/scenario-matrix.json");
const outDir = path.resolve(rootDir, "e2e/generated/specs");

function ensureDirectory(directoryPath) {
  fs.mkdirSync(directoryPath, { recursive: true });
}

function sanitizeFileName(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

function buildSpecContent(scenario) {
  const tags = [scenario.priority.toLowerCase(), scenario.critical_flow ? "critical" : "standard"]
    .map((tag) => `@${tag}`)
    .join(" ");

  return `import { expect, test } from "@playwright/test";

test("${tags} ${scenario.id} ${scenario.title}", async ({ page }) => {
  // Source story: ${scenario.story_id}
  // Risk score: ${scenario.risk_score}
  // Priority: ${scenario.priority}
  await page.goto("${scenario.route}");

  // TODO: Replace with generated steps from your page object/actions layer.
  await expect(page).toHaveURL(/${scenario.route.replace(/\//g, "\\/")}/);

  // Expected outcome:
  // ${scenario.expected_outcome}
});
`;
}

function main() {
  if (!fs.existsSync(matrixPath)) {
    console.error(`Matrix not found at ${matrixPath}`);
    console.error("Run: npm run e2e:matrix");
    process.exit(1);
  }

  const matrix = JSON.parse(fs.readFileSync(matrixPath, "utf8"));
  const scenarios = matrix.scenarios ?? [];
  ensureDirectory(outDir);

  for (const scenario of scenarios) {
    const filename = `${sanitizeFileName(scenario.id)}.spec.ts`;
    const filePath = path.resolve(outDir, filename);
    if (fs.existsSync(filePath)) {
      continue;
    }
    fs.writeFileSync(filePath, buildSpecContent(scenario), "utf8");
  }

  console.log(`Scaffolded specs for ${scenarios.length} scenarios in ${outDir}`);
}

main();
