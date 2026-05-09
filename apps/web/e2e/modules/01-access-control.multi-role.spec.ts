import { test, expect } from "@playwright/test";
import featureCatalog from "../support/feature-catalog.json";
import {
  createTenantUsers,
  expectGuestBlocked,
  expectRouteAccessByPermission,
  logoutPage,
  runKey,
} from "../support/test-helpers";

type FeatureRow = {
  id: string;
  route: string;
  required_permissions: string[];
};

const features = featureCatalog.features as FeatureRow[];

test.describe("Feature Access Control Matrix (Admin/Regular/Guest)", () => {
  for (const feature of features) {
    test(`[feature:${feature.id}] admin can access ${feature.route}`, async ({ page, request }) => {
      const users = await createTenantUsers(request, runKey(feature.id));
      try {
        await expectRouteAccessByPermission(page, users.owner, feature.route, feature.required_permissions);
      } finally {
        await logoutPage(page, users.owner);
      }
    });

    test(`[feature:${feature.id}] regular user access policy on ${feature.route}`, async ({ page, request }) => {
      const users = await createTenantUsers(request, runKey(feature.id));
      try {
        await expectRouteAccessByPermission(page, users.support, feature.route, feature.required_permissions);
      } finally {
        await logoutPage(page, users.support);
      }
    });

    test(`[feature:${feature.id}] guest is blocked from ${feature.route}`, async ({ page }) => {
      await expectGuestBlocked(page, feature.route);
      await expect(page).toHaveURL(/\/login/);
    });
  }

  test("[role:premium] premium-like owner can access billing", async ({ page, request }) => {
    const users = await createTenantUsers(request, runKey("premium"));
    try {
      await expectRouteAccessByPermission(page, users.owner, "/billing", ["billing.view"]);
      await expect(page.locator("main")).toContainText(/Subscription and Plans|Access denied/i);
    } finally {
      await logoutPage(page, users.owner);
    }
  });
});
