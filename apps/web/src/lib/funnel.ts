"use client";

export type FunnelEventName =
  | "landing_cta_start_trial"
  | "landing_cta_book_demo"
  | "landing_cta_view_pricing"
  | "signup_flow_started"
  | "pricing_cta_start_trial"
  | "pricing_cta_talk_sales";

type FunnelEvent = {
  name: FunnelEventName;
  at: string;
  metadata?: Record<string, string | number | boolean>;
};

const FUNNEL_STORAGE_KEY = "wnd_funnel_events_v1";

export function trackFunnelEvent(name: FunnelEventName, metadata?: FunnelEvent["metadata"]): void {
  if (typeof window === "undefined") {
    return;
  }

  const nextEvent: FunnelEvent = {
    name,
    at: new Date().toISOString(),
    metadata,
  };

  const existing = readFunnelEvents();
  localStorage.setItem(FUNNEL_STORAGE_KEY, JSON.stringify([nextEvent, ...existing].slice(0, 200)));
}

export function readFunnelEvents(): FunnelEvent[] {
  if (typeof window === "undefined") {
    return [];
  }
  const raw = localStorage.getItem(FUNNEL_STORAGE_KEY);
  if (!raw) {
    return [];
  }
  try {
    return JSON.parse(raw) as FunnelEvent[];
  } catch {
    return [];
  }
}
