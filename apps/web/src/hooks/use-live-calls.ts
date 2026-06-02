"use client";

import { useEffect, useMemo, useState } from "react";
import { listCalls, streamCallEvents } from "@/lib/product-api";
import type { CallRecord } from "@/types/product";

export function useLiveCalls() {
  const [calls, setCalls] = useState<CallRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string>("");

  async function load() {
    try {
      const response = await listCalls({
        per_page: 50,
        sort: "-created_at",
      });
      setCalls(response.calls);
      setError("");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load calls.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
    let cursor = "";
    let cancelled = false;
    let controller: AbortController | null = null;

    const startStream = async () => {
      while (!cancelled) {
        controller = new AbortController();
        try {
          await streamCallEvents({
            cursor,
            signal: controller.signal,
            onCursor(nextCursor) {
              cursor = nextCursor;
            },
            onMessage(nextCall) {
              setCalls((prev) => {
                const next = [nextCall, ...prev.filter((item) => item.id !== nextCall.id)];
                return next.slice(0, 200);
              });
              setError("");
            },
          });
        } catch (err) {
          if (!cancelled) {
            setError(err instanceof Error ? err.message : "Realtime stream disconnected.");
            await new Promise((resolve) => setTimeout(resolve, 1500));
          }
        } finally {
          controller = null;
        }
      }
    };

    void startStream();

    return () => {
      cancelled = true;
      controller?.abort();
    };
  }, []);

  const liveCalls = useMemo(() => {
    const active = calls.filter((call) =>
      ["queued", "ringing", "in_progress"].includes(call.status)
    );
    const seen = new Set<string>();
    return active.filter((call) => {
      if (seen.has(call.to_number)) {
        return false;
      }
      seen.add(call.to_number);
      return true;
    });
  }, [calls]);

  return { calls, liveCalls, loading, error, refresh: load };
}
