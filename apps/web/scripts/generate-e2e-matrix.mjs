import fs from "node:fs";
import path from "node:path";

const rootDir = process.cwd();
const intakePath = path.resolve(rootDir, "docs/testing/templates/user-story-intake.json");
const weightsPath = path.resolve(rootDir, "docs/testing/templates/risk-weights.json");
const outDir = path.resolve(rootDir, "e2e/generated");
const outJson = path.resolve(outDir, "scenario-matrix.json");
const outMd = path.resolve(outDir, "scenario-matrix.md");

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function ensureDirectory(directoryPath) {
  fs.mkdirSync(directoryPath, { recursive: true });
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function toScore(story, riskWeights) {
  const weighted =
    story.business_criticality * riskWeights.business_criticality +
    story.change_frequency * riskWeights.change_frequency +
    story.user_impact * riskWeights.user_impact +
    story.security_impact * riskWeights.security_impact +
    story.compliance_impact * riskWeights.compliance_impact +
    story.defect_history * riskWeights.defect_history;

  return clamp(Math.round(weighted * 20), 0, 100);
}

function computePriority(score, story, config) {
  const criticalByTag = (story.tags ?? []).some((tag) => config.tag_overrides.critical_tags.includes(tag));
  if (score >= config.thresholds.p0_min || criticalByTag) {
    return "P0";
  }
  if (score >= config.thresholds.p1_min) {
    return "P1";
  }
  return "P2";
}

function scenarioId(moduleName, index) {
  return `E2E-${moduleName.toUpperCase()}-${String(index + 1).padStart(3, "0")}`;
}

function expandStories(stories, riskConfig) {
  const scenarios = [];
  for (const story of stories) {
    const riskScore = toScore(story, riskConfig.weights);
    const priority = computePriority(riskScore, story, riskConfig);
    const criticalFlow = priority === "P0";
    const browserMatrix = criticalFlow ? ["chromium", "firefox", "webkit"] : ["chromium"];
    const route = story.routes?.[0] ?? "/";

    for (const criterion of story.acceptance_criteria ?? []) {
      scenarios.push({
        id: scenarioId(story.module ?? "GEN", scenarios.length),
        story_id: story.id,
        title: `${story.title}: ${criterion}`,
        module: story.module ?? "general",
        route,
        priority,
        risk_score: riskScore,
        critical_flow: criticalFlow,
        browser_matrix: browserMatrix,
        steps: [
          `Authenticate as ${story.persona ?? "user"}`,
          `Navigate to ${route}`,
          `Execute action: ${criterion}`
        ],
        expected_outcome: criterion,
        tags: story.tags ?? []
      });
    }
  }
  return scenarios;
}

function buildMarkdown(matrix) {
  const lines = [];
  lines.push("# AI E2E Scenario Matrix");
  lines.push("");
  lines.push(`Generated at: ${matrix.generated_at}`);
  lines.push(`Coverage target: ${(matrix.coverage_target * 100).toFixed(0)}%`);
  lines.push(`Critical runtime target: < ${matrix.runtime_target_minutes} minutes`);
  lines.push("");
  lines.push("| ID | Story | Module | Route | Priority | Risk | Critical | Browsers |");
  lines.push("| --- | --- | --- | --- | --- | ---: | --- | --- |");
  for (const scenario of matrix.scenarios) {
    lines.push(
      `| ${scenario.id} | ${scenario.story_id} | ${scenario.module} | ${scenario.route} | ${scenario.priority} | ${scenario.risk_score} | ${scenario.critical_flow ? "Yes" : "No"} | ${scenario.browser_matrix.join(", ")} |`
    );
  }
  lines.push("");
  lines.push("## Scenario Details");
  lines.push("");
  for (const scenario of matrix.scenarios) {
    lines.push(`### ${scenario.id} - ${scenario.title}`);
    lines.push(`- Story: \`${scenario.story_id}\``);
    lines.push(`- Priority: \`${scenario.priority}\` (risk score: ${scenario.risk_score})`);
    lines.push(`- Route: \`${scenario.route}\``);
    lines.push(`- Steps:`);
    for (const step of scenario.steps) {
      lines.push(`  - ${step}`);
    }
    lines.push(`- Expected outcome: ${scenario.expected_outcome}`);
    lines.push("");
  }
  return `${lines.join("\n")}\n`;
}

function main() {
  const intake = readJson(intakePath);
  const riskConfig = readJson(weightsPath);
  const scenarios = expandStories(intake.stories ?? [], riskConfig);
  const matrix = {
    version: "1.0.0",
    generated_at: new Date().toISOString(),
    coverage_target: 0.9,
    runtime_target_minutes: 5,
    scenarios
  };

  ensureDirectory(outDir);
  fs.writeFileSync(outJson, `${JSON.stringify(matrix, null, 2)}\n`, "utf8");
  fs.writeFileSync(outMd, buildMarkdown(matrix), "utf8");

  console.log(`Generated ${scenarios.length} scenarios.`);
  console.log(`JSON: ${outJson}`);
  console.log(`Markdown: ${outMd}`);
}

main();
