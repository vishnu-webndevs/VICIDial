"use client";

import { PropsWithChildren, ReactNode, useEffect, useRef, useState } from "react";

type LoadingIndicatorProps = {
  label?: string;
  className?: string;
};

type GatePhase = "loading" | "fading" | "loaded";

type LoadingGateProps = PropsWithChildren<{
  isLoading: boolean;
  label?: string;
  minDurationMs?: number;
  fadeDurationMs?: number;
  className?: string;
  errorFallback?: ReactNode;
  hasError?: boolean;
}>;

export function LoadingIndicator({
  label = "Loading...",
  className = "",
}: LoadingIndicatorProps) {
  return (
    <div
      className={`app-loading-indicator ${className}`.trim()}
      role="status"
      aria-live="polite"
      aria-label={label}
    >
      <div className="app-loading-spinner-wrap" aria-hidden="true">
        <div className="app-loading-spinner" />
      </div>
      <p className="app-loading-label">{label}</p>
    </div>
  );
}

export function LoadingGate({
  isLoading,
  label = "Loading...",
  minDurationMs = 500,
  fadeDurationMs = 100,
  className = "",
  children,
  hasError = false,
  errorFallback,
}: LoadingGateProps) {
  const [phase, setPhase] = useState<GatePhase>(isLoading ? "loading" : "loaded");
  const loadingStartedAtRef = useRef<number>(0);

  useEffect(() => {
    let loadingPhaseTimer: ReturnType<typeof setTimeout> | undefined;
    let fadeTimer: ReturnType<typeof setTimeout> | undefined;

    if (isLoading) {
      loadingStartedAtRef.current = Date.now();
      loadingPhaseTimer = setTimeout(() => {
        setPhase("loading");
      }, 0);
      return () => {
        if (loadingPhaseTimer) clearTimeout(loadingPhaseTimer);
        if (fadeTimer) clearTimeout(fadeTimer);
      };
    }

    const elapsed = Date.now() - loadingStartedAtRef.current;
    const remainingMs = Math.max(0, minDurationMs - elapsed);

    const minDurationTimer = setTimeout(() => {
      setPhase("fading");
      fadeTimer = setTimeout(() => {
        setPhase("loaded");
      }, fadeDurationMs);
    }, remainingMs);

    return () => {
      if (loadingPhaseTimer) clearTimeout(loadingPhaseTimer);
      if (minDurationTimer) clearTimeout(minDurationTimer);
      if (fadeTimer) clearTimeout(fadeTimer);
    };
  }, [fadeDurationMs, isLoading, minDurationMs]);

  const shouldShowLoader = phase !== "loaded";
  const shouldShowContent = phase === "loaded";

  return (
    <div
      className={`app-loading-gate ${className}`.trim()}
      aria-busy={shouldShowLoader}
    >
      <div
        className={`app-loading-content ${
          shouldShowContent ? "app-loading-content--visible" : "app-loading-content--hidden"
        }`}
      >
        {hasError && errorFallback ? errorFallback : children}
      </div>

      {shouldShowLoader ? (
        <div
          className={`app-loading-overlay ${
            phase === "fading" ? "app-loading-overlay--fade" : ""
          }`.trim()}
        >
          <LoadingIndicator label={label} />
        </div>
      ) : null}
    </div>
  );
}

export function HydrationLoadingGate({
  children,
  label = "Preparing application...",
  minDurationMs = 500,
}: PropsWithChildren<{
  label?: string;
  minDurationMs?: number;
}>) {
  // Keep server and first client render consistent to avoid hydration mismatch.
  const [isHydrating, setIsHydrating] = useState(true);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setIsHydrating(false);
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  return (
    <LoadingGate isLoading={isHydrating} label={label} minDurationMs={minDurationMs}>
      {children}
    </LoadingGate>
  );
}
