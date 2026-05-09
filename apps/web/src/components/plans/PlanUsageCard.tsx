"use client";

import Link from "next/link";
import { usePlan } from "@/hooks/use-plan";
import { UsageProgressBar } from "@/components/plans/UsageProgressBar";

type PlanUsageCardProps = {
  featureKeys: string[];
};

export function PlanUsageCard({ featureKeys }: PlanUsageCardProps) {
  const { plan, isEnabled, isAtLimit } = usePlan();

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-slate-900">Plan: {plan?.name ?? "N/A"}</h3>
        <Link href="/landing/pricing" className="text-xs font-medium text-indigo-600 hover:text-indigo-700">
          Upgrade
        </Link>
      </div>
      <div className="mt-3 space-y-3">
        {featureKeys.map((featureKey) => {
          if (!isEnabled(featureKey)) {
            return (
              <div key={featureKey} className="rounded-md bg-slate-50 p-2">
                <p className="text-xs text-slate-500">{featureKey}</p>
                <p className="text-xs font-medium text-slate-700">Not enabled</p>
              </div>
            );
          }

          return (
            <div key={featureKey}>
              <div className="mb-1 flex items-center justify-between">
                <p className="text-xs text-slate-500">{featureKey}</p>
                {isAtLimit(featureKey) ? <span className="text-xs text-red-600">At limit</span> : null}
              </div>
              <UsageProgressBar featureKey={featureKey} />
            </div>
          );
        })}
      </div>
    </section>
  );
}
