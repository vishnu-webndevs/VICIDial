import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      "@typescript-eslint/no-explicit-any": "off",
      "@typescript-eslint/no-unused-vars": ["warn", { "argsIgnorePattern": "^_", "varsIgnorePattern": "^_" }],
      "@typescript-eslint/no-namespace": "off",
      "react-hooks/set-state-in-effect": "warn",
      "@next/next/no-img-element": "warn",
    },
  },
  {
    files: ["src/**/*.{ts,tsx}"],
    ignores: ["src/ui/**/*.{ts,tsx}"],
    rules: {
      "no-restricted-imports": [
        "warn",
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
