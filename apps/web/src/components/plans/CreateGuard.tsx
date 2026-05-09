"use client";

import { PropsWithChildren, ReactElement, cloneElement, isValidElement, useMemo, useState } from "react";
import { usePlan } from "@/hooks/use-plan";
import { UpgradePromptModal } from "@/components/plans/UpgradePromptModal";

type CreateGuardProps = PropsWithChildren<{
  featureKey: string;
  fallbackLabel?: string;
}>;

export function CreateGuard({ featureKey, fallbackLabel = "Create", children }: CreateGuardProps) {
  const { plan, isAtLimit, isEnabled } = usePlan();
  const [showModal, setShowModal] = useState(false);
  const blocked = !isEnabled(featureKey) || isAtLimit(featureKey);

  const reason = useMemo(() => {
    if (!isEnabled(featureKey)) {
      return "This feature is not enabled on your current plan.";
    }
    if (isAtLimit(featureKey)) {
      return "You have reached your current usage limit.";
    }
    return "";
  }, [featureKey, isAtLimit, isEnabled]);

  if (!blocked) {
    return <>{children}</>;
  }

  const guardedChild = isValidElement(children)
    ? cloneElement(children as ReactElement<{ onClick?: (event: unknown) => void; title?: string; className?: string; "aria-disabled"?: boolean }>, {
        onClick: (event: unknown) => {
          const pointerEvent = event as { preventDefault?: () => void; stopPropagation?: () => void };
          pointerEvent.preventDefault?.();
          pointerEvent.stopPropagation?.();
          setShowModal(true);
        },
        title: reason,
        "aria-disabled": true,
        className: `${(children.props as { className?: string }).className ?? ""} cursor-not-allowed opacity-70`,
      })
    : null;

  return (
    <>
      {guardedChild ?? (
        <div className="inline-flex" title={reason}>
          <button
            type="button"
            aria-disabled
            onClick={() => setShowModal(true)}
            className="inline-flex cursor-not-allowed rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm text-slate-500"
          >
            {fallbackLabel}
          </button>
        </div>
      )}
      <UpgradePromptModal
        open={showModal}
        onClose={() => setShowModal(false)}
        currentPlan={plan?.name ?? null}
        message={reason || "Upgrade to continue."}
      />
    </>
  );
}
