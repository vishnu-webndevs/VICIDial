import { test, expect } from "@playwright/test";
import { API_BASE, authenticatePage, createTenantUsers, logoutPage, runKey } from "../support/test-helpers";

const EXPECTED_FEATURE_KEYS = [
  "ai_receptionist_intent_handling",
  "whatsapp_messaging_channel",
  "microsoft_graph_booking_sync",
  "unified_reporting_layer",
  "workflow_automation_engine",
  "advanced_governance_controls",
] as const;

test.describe("Planned Features Roadmap", () => {
  test("[feature:planned-status-api] returns six implemented planned features", async ({ request }) => {
    const users = await createTenantUsers(request, runKey("planned-status-api"));
    try {
      const response = await request.get(`${API_BASE}/features/planned/status`, {
        headers: {
          Authorization: `Bearer ${users.owner.token}`,
          "X-Tenant-Id": users.owner.tenantId,
        },
      });

      expect(response.ok(), "planned status API should respond successfully").toBeTruthy();
      const payload = await response.json();
      const features: Array<{ feature_key: string; status: string; evidence_count: number }> =
        payload?.data?.features ?? [];

      expect(features).toHaveLength(6);
      const keys = features.map((feature) => feature.feature_key).sort();
      expect(keys).toEqual([...EXPECTED_FEATURE_KEYS].sort());

      for (const feature of features) {
        expect(feature.status).toBe("implemented");
        expect(typeof feature.evidence_count).toBe("number");
        expect(feature.evidence_count).toBeGreaterThanOrEqual(0);
      }
    } finally {
      await request.post(`${API_BASE}/auth/logout`, {
        headers: {
          Authorization: `Bearer ${users.owner.token}`,
          "X-Tenant-Id": users.owner.tenantId,
        },
      }).catch(() => {
        // Ignore teardown failures.
      });
    }
  });

  test("[feature:planned-status-ui] analytics shows planned feature cards", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("planned-status-ui"));
    try {
      await authenticatePage(page, users.owner, "/analytics");
      await expect(
        page.getByRole("heading", { name: "Roadmap Planned Features" })
      ).toBeVisible({ timeout: 30000 });

      for (const key of EXPECTED_FEATURE_KEYS) {
        const card = page.locator("main").locator("div,section,paper", { hasText: key }).first();
        await expect(card).toContainText(/Status:\s*implemented/i);
      }
    } finally {
      await logoutPage(page, users.owner);
    }
  });
});
