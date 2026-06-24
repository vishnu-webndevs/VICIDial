"use client";

import type { SessionProfile } from "@/lib/product-api";
import { clearSessionCache } from "@/lib/session-cache";

export type SessionStorageState = {
  token: string | null;
  tenantId: string | null;
};

export function getSessionStorageState(): SessionStorageState {
  if (typeof window === "undefined") {
    return { token: null, tenantId: null };
  }

  return {
    token: localStorage.getItem("wnd_token"),
    tenantId: localStorage.getItem("wnd_tenant_id"),
  };
}

export function saveSession(token: string, tenantId?: string | null): void {
  if (typeof window === "undefined") {
    return;
  }

  localStorage.setItem("wnd_token", token);
  if (tenantId) {
    localStorage.setItem("wnd_tenant_id", tenantId);
  }
}

export function clearSession(): void {
  clearSessionCache();
  if (typeof window === "undefined") {
    return;
  }

  localStorage.removeItem("wnd_token");
  localStorage.removeItem("wnd_tenant_id");
}

export function syncTenantFromProfile(profile: SessionProfile): void {
  if (typeof window === "undefined") {
    return;
  }

  if (profile.current_tenant?.id) {
    localStorage.setItem("wnd_tenant_id", profile.current_tenant.id);
  }
}

export function getRoleAwareRoute(
  profile: SessionProfile,
  onboardingDone: boolean
): string {
  const role = profile.role?.slug ?? "";
  if (!onboardingDone && profile.current_tenant?.id) {
    return "/onboarding";
  }

  if (
    profile.is_platform_admin ||
    role === "platform_super_admin" ||
    role === "super_admin"
  ) {
    return "/super-admin";
  }

  return "/dashboard";
}
