import fs from "node:fs";
import path from "node:path";

const rootDir = process.cwd();
const catalogPath = path.resolve(rootDir, "e2e/support/feature-catalog.json");
const resultsPath = path.resolve(rootDir, "test-results/results.json");
const outputDir = path.resolve(rootDir, "test-results");
const outJson = path.resolve(outputDir, "coverage-summary.json");
const outMd = path.resolve(outputDir, "coverage-summary.md");

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function getFeatureTag(title = "") {
  const matched = title.match(/\[feature:([a-zA-Z0-9_-]+)\]/);
  return matched?.[1] ?? null;
}

function testPassed(testCase) {
  const results = Array.isArray(testCase?.results) ? testCase.results : [];
  return results.some((result) => result.status === "passed");
}

function collectCoveredFeatures(node, covered) {
  if (!node || typeof node !== "object") {
    return;
  }

  if (Array.isArray(node.specs)) {
    for (const spec of node.specs) {
      const tag = getFeatureTag(String(spec?.title ?? ""));
      if (!tag) {
        continue;
      }
      const testCases = Array.isArray(spec?.tests) ? spec.tests : [];
      if (testCases.some((testCase) => testPassed(testCase))) {
        covered.add(tag);
      }
    }
  }

  if (Array.isArray(node.suites)) {
    for (const suite of node.suites) {
      collectCoveredFeatures(suite, covered);
    }
  }
}

function collectCoveredFeaturesFromResults(results) {
  const covered = new Set();
  collectCoveredFeatures(results, covered);
  return covered;
}

function testPassedOldShape(test) {
  const results = Array.isArray(test.results) ? test.results : [];
  return results.some((result) => result.status === "passed");
}

function toMarkdown(summary) {
  const lines = [
    "# E2E Coverage Summary",
    "",
    `- Feature coverage: ${summary.coverage_percent}% (${summary.covered_features}/${summary.total_features})`,
    `- Critical coverage: ${summary.critical_coverage_percent}% (${summary.covered_critical}/${summary.total_critical})`,
    `- Coverage target met (>= ${summary.target_percent}%): ${summary.coverage_target_met ? "yes" : "no"}`,
    "",
    "## Uncovered Features",
  ];

  if (summary.uncovered_features.length === 0) {
    lines.push("- None");
  } else {
    for (const row of summary.uncovered_features) {
      lines.push(`- ${row.id} (${row.route})`);
    }
  }

  lines.push("", "## Critical Uncovered Features");
  if (summary.uncovered_critical.length === 0) {
    lines.push("- None");
  } else {
    for (const row of summary.uncovered_critical) {
      lines.push(`- ${row.id} (${row.route})`);
    }
  }

  lines.push("");
  return `${lines.join("\n")}\n`;
}

function main() {
  const catalog = readJson(catalogPath);
  const features = Array.isArray(catalog.features) ? catalog.features : [];

  const covered = new Set();
  if (fs.existsSync(resultsPath)) {
    const results = readJson(resultsPath);
    for (const tag of collectCoveredFeaturesFromResults(results)) {
      covered.add(tag);
    }

    // Backward compatibility for result shapes where title and results coexist in the same test node.
    const legacyTests = Array.isArray(results.tests) ? results.tests : [];
    for (const test of legacyTests) {
      const tag = getFeatureTag(String(test.title ?? ""));
      if (tag && testPassedOldShape(test)) {
        covered.add(tag);
      }
    }
  }

  const coveredFeatures = features.filter((feature) => covered.has(feature.id));
  const uncoveredFeatures = features.filter((feature) => !covered.has(feature.id));
  const criticalFeatures = features.filter((feature) => feature.critical);
  const coveredCritical = criticalFeatures.filter((feature) => covered.has(feature.id));
  const uncoveredCritical = criticalFeatures.filter((feature) => !covered.has(feature.id));
  const targetPercent = 90;

  const summary = {
    generated_at: new Date().toISOString(),
    target_percent: targetPercent,
    total_features: features.length,
    covered_features: coveredFeatures.length,
    coverage_percent: features.length === 0 ? 0 : Number(((coveredFeatures.length / features.length) * 100).toFixed(2)),
    total_critical: criticalFeatures.length,
    covered_critical: coveredCritical.length,
    critical_coverage_percent:
      criticalFeatures.length === 0 ? 0 : Number(((coveredCritical.length / criticalFeatures.length) * 100).toFixed(2)),
    coverage_target_met: features.length > 0 && (coveredFeatures.length / features.length) * 100 >= targetPercent,
    uncovered_features: uncoveredFeatures,
    uncovered_critical: uncoveredCritical,
  };

  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }
  fs.writeFileSync(outJson, `${JSON.stringify(summary, null, 2)}\n`, "utf8");
  fs.writeFileSync(outMd, toMarkdown(summary), "utf8");

  console.log(`Coverage summary JSON: ${outJson}`);
  console.log(`Coverage summary Markdown: ${outMd}`);
}

main();
