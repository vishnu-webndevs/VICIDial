import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    files: ["src/**/*.{ts,tsx}"],
    ignores: ["src/ui/**/*.{ts,tsx}"],
    rules: {
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "@mui/material",
              message: "Import UI primitives from /src/ui instead of @mui/material directly.",
            },
            {
              name: "@mui/icons-material",
              message: "Wrap icons/components in /src/ui before usage in app pages.",
            },
          ],
          patterns: [
            {
              group: ["@mui/material/*", "@mui/icons-material/*"],
              message: "Use /src/ui wrappers for consistent design-system usage.",
            },
          ],
        },
      ],
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
    "ui-template/**",
    "playwright-report/**",
    "test-results/**",
  ]),
]);

export default eslintConfig;
