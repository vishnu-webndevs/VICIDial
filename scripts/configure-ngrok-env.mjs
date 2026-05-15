import { readFileSync, writeFileSync, existsSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, "..");
const apiEnvPath = resolve(repoRoot, "apps/api/.env");
const webEnvPath = resolve(repoRoot, "apps/web/.env.local");

const argv = process.argv.slice(2);
const urlArgIndex = argv.findIndex((value) => value === "--url");
const explicitUrl = urlArgIndex >= 0 ? argv[urlArgIndex + 1] : null;

function readEnvValue(filePath, key) {
  if (!existsSync(filePath)) {
    return null;
  }

  const contents = readFileSync(filePath, "utf8");
  const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const match = contents.match(new RegExp(`^${escapedKey}=(.*)$`, "m"));
  return match?.[1]?.trim() || null;
}

async function discoverNgrokUrl() {
  if (explicitUrl) {
    return explicitUrl;
  }

  if (process.env.NGROK_URL) {
    return process.env.NGROK_URL;
  }

  const candidatePorts = [4040, 4041, 4042];
  for (const port of candidatePorts) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}/api/tunnels`);
      if (!response.ok) {
        continue;
      }
      const payload = await response.json();
      const publicUrl = payload?.tunnels?.find((tunnel) => tunnel.public_url?.startsWith("https://"))?.public_url;
      if (publicUrl) {
        return publicUrl;
      }
    } catch {
      // Skip unreachable ngrok local API ports.
    }
  }

  const existingAppUrl = readEnvValue(apiEnvPath, "APP_URL");
  if (existingAppUrl?.includes("ngrok")) {
    return existingAppUrl;
  }

  return null;
}

function normalizeOrigin(value) {
  if (!value) {
    return null;
  }

  try {
    const normalized = new URL(value).origin;
    return normalized.replace(/\/+$/, "");
  } catch {
    return null;
  }
}

function upsertEnvLine(current, key, value) {
  const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pattern = new RegExp(`^${escapedKey}=.*$`, "m");
  const line = `${key}=${value}`;

  if (pattern.test(current)) {
    return current.replace(pattern, line);
  }

  const suffix = current.endsWith("\n") || current.length === 0 ? "" : "\n";
  return `${current}${suffix}${line}\n`;
}

const ngrokOrigin = normalizeOrigin(await discoverNgrokUrl());

if (!ngrokOrigin) {
  console.error(
    "Could not resolve an ngrok URL. Run ngrok first, or pass one explicitly: node scripts/configure-ngrok-env.mjs --url https://your-domain.ngrok-free.app"
  );
  process.exit(1);
}

let apiEnv = readFileSync(apiEnvPath, "utf8");
apiEnv = upsertEnvLine(apiEnv, "APP_URL", ngrokOrigin);
apiEnv = upsertEnvLine(
  apiEnv,
  "CORS_ALLOWED_ORIGINS",
  `http://localhost:3000,http://127.0.0.1:3000,${ngrokOrigin}`
);
apiEnv = upsertEnvLine(
  apiEnv,
  "CORS_ALLOWED_ORIGIN_PATTERNS",
  "^https://.*\\.ngrok-free\\.app$,^https://.*\\.ngrok-free\\.dev$"
);
apiEnv = upsertEnvLine(apiEnv, "CORS_SUPPORTS_CREDENTIALS", "true");
writeFileSync(apiEnvPath, apiEnv, "utf8");

let webEnv = existsSync(webEnvPath) ? readFileSync(webEnvPath, "utf8") : "";
webEnv = upsertEnvLine(webEnv, "NEXT_PUBLIC_API_BASE_URL", `${ngrokOrigin}/api/v1`);
writeFileSync(webEnvPath, webEnv, "utf8");

console.log(`APP_URL set to ${ngrokOrigin} in apps/api/.env`);
console.log(`CORS_ALLOWED_ORIGINS updated for localhost + ngrok in apps/api/.env`);
console.log(`NEXT_PUBLIC_API_BASE_URL set to ${ngrokOrigin}/api/v1 in apps/web/.env.local`);
