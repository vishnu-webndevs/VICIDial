"use client";

import { usePlan } from "@/hooks/use-plan";

export function UsageProgressBar({ featureKey }: { featureKey: string }) {
  const { usage, getLimit, percentUsed, isUnlimited } = usePlan();
  const current = Number(usage[featureKey] ?? 0);
  const limit = getLimit(featureKey);
  const percent = percentUsed(featureKey);

  const toneClass = percent >= 100 ? "bg-red-500" : percent >= 80 ? "bg-amber-500" : "bg-emerald-500";

  if (isUnlimited(featureKey)) {
    return (
      <div className="w-full">
        <div className="h-2 rounded bg-slate-200">
          <div className="h-2 w-full rounded bg-emerald-500" />
        </div>
        <p className="mt-1 text-xs text-slate-500">{current} / ∞</p>
      </div>
    );
  }

  return (
    <div className="w-full">
      <div className="h-2 rounded bg-slate-200">
        <div className={`h-2 rounded ${toneClass}`} style={{ width: `${Math.max(percent, 2)}%` }} />
      </div>
      <p className="mt-1 text-xs text-slate-500">
        {current} / {limit}
      </p>
    </div>
  );
}
