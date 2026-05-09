import { getSessionStorageState } from "@/lib/auth-session";

export type TenantContext = {
  token: string | null;
  tenantId: string | null;
};

export function getTenantContext(): TenantContext {
  return getSessionStorageState();
}

export function getTenantScopedStorageKey(
  key: string,
  tenantId: string | null
): string {
  return `wnd:${tenantId ?? "no-tenant"}:${key}`;
}
