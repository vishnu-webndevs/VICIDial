import { getTenantScopedStorageKey } from "@/lib/tenant-context";

const ONBOARDING_STATE_KEY = "onboarding_state_v1";

type OnboardingState = {
  completed: boolean;
  completedAt?: string;
};

function getScopedKey(tenantId: string | null): string {
  return getTenantScopedStorageKey(ONBOARDING_STATE_KEY, tenantId);
}

export function isOnboardingComplete(tenantId: string | null): boolean {
  if (typeof window === "undefined") {
    return false;
  }

  const raw = localStorage.getItem(getScopedKey(tenantId));
  if (!raw) {
    return false;
  }

  try {
    const state = JSON.parse(raw) as OnboardingState;
    return Boolean(state.completed);
  } catch {
    return false;
  }
}

export function setOnboardingComplete(tenantId: string | null): void {
  if (typeof window === "undefined") {
    return;
  }

  const payload: OnboardingState = {
    completed: true,
    completedAt: new Date().toISOString(),
  };
  localStorage.setItem(getScopedKey(tenantId), JSON.stringify(payload));
}

export function resetOnboarding(tenantId: string | null): void {
  if (typeof window === "undefined") {
    return;
  }

  localStorage.removeItem(getScopedKey(tenantId));
}
