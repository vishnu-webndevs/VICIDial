"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { getCallDetail } from "@/lib/product-api";
import type { CallDetail } from "@/types/product";

export default function CallDetailPage() {
  const params = useParams<{ id: string }>();
  const [call, setCall] = useState<CallDetail | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    async function load() {
      const id = params?.id;
      if (!id) {
        return;
      }
      try {
        const detail = await getCallDetail(id);
        setCall(detail);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to load call detail.");
      }
    }

    void load();
  }, [params]);

  return (
    <AppShell requiredPermissions={["call.view"]}>
      <div className="grid gap-4">
        <SectionCard
          title="Call Detail View"
          subtitle="Single call inspection with timeline events."
        >
          <Link href="/calls" className="text-sm text-slate-700 underline">
            Back to Call Dashboard
          </Link>
          {error ? <p className="mt-3 text-sm text-rose-700">{error}</p> : null}
          {!call && !error ? (
            <p className="mt-3 text-sm text-slate-600">Loading call detail...</p>
          ) : null}
          {call ? (
            <div className="mt-4 grid gap-3 text-sm md:grid-cols-2">
              <p>
                <span className="text-slate-500">Call ID:</span> {call.id}
              </p>
              <p>
                <span className="text-slate-500">Direction:</span> {call.direction}
              </p>
              <p>
                <span className="text-slate-500">Status:</span> <StatusBadge label={call.status} />
              </p>
              <p>
                <span className="text-slate-500">Provider:</span>{" "}
                {call.provider?.label ?? "N/A"}
              </p>
              <p>
                <span className="text-slate-500">From:</span> {call.from_number}
              </p>
              <p>
                <span className="text-slate-500">To:</span> {call.to_number}
              </p>
              <p>
                <span className="text-slate-500">Started:</span>{" "}
                {call.started_at ? new Date(call.started_at).toLocaleString() : "N/A"}
              </p>
              <p>
                <span className="text-slate-500">Ended:</span>{" "}
                {call.ended_at ? new Date(call.ended_at).toLocaleString() : "N/A"}
              </p>
            </div>
          ) : null}
        </SectionCard>

        <SectionCard
          title="Call Timeline"
          subtitle="Chronological event stream for this call."
        >
          {call?.events?.length ? (
            <ol className="space-y-2">
              {call.events.map((event, index) => (
                <li key={`${event.type}-${index}`} className="rounded-md border border-slate-200 p-3">
                  <p className="text-sm font-medium">{event.type}</p>
                  <p className="text-xs text-slate-500">
                    {new Date(event.occurred_at).toLocaleString()}
                  </p>
                  <p className="text-xs text-slate-600">
                    Provider Event: {event.provider_event_type ?? "n/a"} | Status:{" "}
                    {event.status_after ?? "n/a"}
                  </p>
                </li>
              ))}
            </ol>
          ) : (
            <p className="text-sm text-slate-600">No timeline events recorded.</p>
          )}
        </SectionCard>
      </div>
    </AppShell>
  );
}
