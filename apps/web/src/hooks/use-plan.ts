"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { fetchSessionProfile, type SessionProfile } from "@/lib/product-api";

type PlanFeature = { value: number | boolean | string; label: string; type: "limit" | "boolean" | "text" };

type UsePlanResult = {
  plan: SessionProfile["plan"];
  usage: Record<string, number>;
  loading: boolean;
  getLimit: (featureKey: string) => number;
  isAtLimit: (featureKey: string) => boolean;
  isUnlimited: (featureKey: string) => boolean;
  isEnabled: (featureKey: string) => boolean;
  percentUsed: (featureKey: string) => number;
  refresh: () => Promise<void>;
};

function coerceNumber(value: number | boolean | string | undefined): number {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : 0;
  }
  if (typeof value === "boolean") {
    return value ? 1 : 0;
  }
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function coerceBoolean(value: number | boolean | string | undefined): boolean {
  if (typeof value === "boolean") {
    return value;
  }
  if (typeof value === "number") {
    return value > 0;
  }
  if (typeof value === "string") {
    return value === "1" || value.toLowerCase() === "true" || value.toLowerCase() === "on";
  }
  return false;
}

export function usePlan(): UsePlanResult {
  const [profile, setProfile] = useState<SessionProfile | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const next = await fetchSessionProfile();
      setProfile(next);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const featureMap = useMemo(() => profile?.plan?.features ?? {}, [profile]);
  const usage = useMemo(() => profile?.usage ?? {}, [profile]);

  const getFeature = useCallback(
    (featureKey: string): PlanFeature | null => {
      return (featureMap[featureKey] as PlanFeature | undefined) ?? null;
    },
    [featureMap]
  );

  const getLimit = useCallback(
    (featureKey: string): number => {
      const feature = getFeature(featureKey);
      if (!feature) {
        return -1;
      }
      return coerceNumber(feature.value);
    },
    [getFeature]
  );

  const isUnlimited = useCallback(
    (featureKey: string): boolean => {
      return getLimit(featureKey) === -1;
    },
    [getLimit]
  );

  const isEnabled = useCallback(
    (featureKey: string): boolean => {
      const feature = getFeature(featureKey);
      if (!feature) {
        return true;
      }
      if (feature.type === "boolean") {
        return coerceBoolean(feature.value);
      }
      return coerceNumber(feature.value) !== 0;
    },
    [getFeature]
  );

  const isAtLimit = useCallback(
    (featureKey: string): boolean => {
      const limit = getLimit(featureKey);
      const current = Number(usage[featureKey] ?? 0);
      if (limit < 0) {
        return false;
      }
      return current >= limit;
    },
    [getLimit, usage]
  );

  const percentUsed = useCallback(
    (featureKey: string): number => {
      const limit = getLimit(featureKey);
      const current = Number(usage[featureKey] ?? 0);
      if (limit <= 0 || limit === -1) {
        return 0;
      }
      return Math.min(100, Math.round((current / limit) * 100));
    },
    [getLimit, usage]
  );

  return {
    plan: profile?.plan ?? null,
    usage,
    loading,
    getLimit,
    isAtLimit,
    isUnlimited,
    isEnabled,
    percentUsed,
    refresh,
  };
}
