"use client";

import Link from "next/link";

type UpgradePromptModalProps = {
  open: boolean;
  onClose: () => void;
  title?: string;
  message?: string;
  currentPlan?: string | null;
  nextPlan?: string | null;
};

export function UpgradePromptModal({
  open,
  onClose,
  title = "Usage limit reached",
  message = "You have reached your current plan limit.",
  currentPlan,
  nextPlan,
}: UpgradePromptModalProps) {
  if (!open) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 className="text-lg font-semibold text-slate-900">{title}</h3>
        <p className="mt-2 text-sm text-slate-600">{message}</p>
        {currentPlan ? (
          <p className="mt-2 text-sm text-slate-500">
            Current plan: <span className="font-medium text-slate-700">{currentPlan}</span>
          </p>
        ) : null}
        {nextPlan ? (
          <p className="mt-1 text-sm text-slate-500">
            Recommended upgrade: <span className="font-medium text-slate-700">{nextPlan}</span>
          </p>
        ) : null}
        <div className="mt-4 flex flex-wrap gap-2">
          <Link
            href="/landing/pricing"
            className="inline-flex rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700"
          >
            {nextPlan ? `Upgrade to ${nextPlan}` : "View Plans"}
          </Link>
          <button
            type="button"
            onClick={onClose}
            className="inline-flex rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Not now
          </button>
        </div>
      </div>
    </div>
  );
}
