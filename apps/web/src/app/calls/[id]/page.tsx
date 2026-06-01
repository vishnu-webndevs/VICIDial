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
            <div className="mt-6 grid gap-6">
              {/* Key Press & Qualification Banners */}
              {call.metadata?.digits_pressed && (
                <div className={`rounded-md border p-4 shadow-sm transition-all duration-300 ${
                  call.metadata.digits_pressed === "1"
                    ? "border-emerald-200 bg-emerald-50 text-emerald-800 shadow-emerald-100/50"
                    : "border-slate-200 bg-slate-50 text-slate-800"
                }`}>
                  <p className="font-semibold text-sm flex items-center gap-2">
                    {call.metadata.digits_pressed === "1" 
                      ? "⭐ Customer Interested / Qualified Lead" 
                      : `⌨️ IVR Key Press: ${call.metadata.digits_pressed}`}
                  </p>
                  <p className="text-xs mt-1 leading-relaxed">
                    {call.metadata.digits_pressed === "1"
                      ? "The customer responded positively by pressing 1 during the automated voice campaign. This lead has been automatically qualified in the database."
                      : `The customer pressed key ${call.metadata.digits_pressed} during the automated voice campaign.`}
                  </p>
                </div>
              )}

              {/* Call Failure or Status Warning Banners */}
              {call.status === "busy" && (
                <div className="rounded-md border border-rose-200 bg-rose-50 p-4 text-rose-800">
                  <p className="font-semibold text-sm flex items-center gap-2">
                    🚫 Customer Line Busy
                  </p>
                  <p className="text-xs mt-1 text-rose-700">
                    The call was rejected or could not connect because the customer's line was busy.
                  </p>
                </div>
              )}
              {call.status === "no_answer" && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-amber-800">
                  <p className="font-semibold text-sm flex items-center gap-2">
                    ⏳ No Answer / Unanswered
                  </p>
                  <p className="text-xs mt-1 text-amber-700">
                    The call rang successfully, but the customer did not answer the phone.
                  </p>
                </div>
              )}
              {call.status === "rejected" && (
                <div className="rounded-md border border-rose-200 bg-rose-50 p-4 text-rose-800">
                  <p className="font-semibold text-sm flex items-center gap-2">
                    🛑 Call Rejected by Customer
                  </p>
                  <p className="text-xs mt-1 text-rose-700">
                    The customer explicitly declined or hung up the call while ringing.
                  </p>
                </div>
              )}
              {call.status === "failed" && (
                <div className="rounded-md border border-red-200 bg-red-50 p-4 text-red-800">
                  <p className="font-semibold text-sm flex items-center gap-2">
                    ⚠️ Call Connection Failed
                  </p>
                  <p className="text-xs mt-1 text-red-700">
                    {call.failure_reason || "The call failed to establish due to a provider or carrier issue."}
                  </p>
                </div>
              )}

              <div className="grid gap-4 text-sm md:grid-cols-2 lg:grid-cols-3">
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Call ID</span>
                  <span className="mt-1 block font-mono text-slate-800">{call.id}</span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Direction</span>
                  <span className="mt-1 block font-semibold text-slate-800 capitalize">{call.direction}</span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Status</span>
                  <span className="mt-1 block"><StatusBadge label={call.status} /></span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">From (Caller)</span>
                  <span className="mt-1 block font-mono text-slate-800">{call.from_number || "N/A"}</span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">To (Customer)</span>
                  <span className="mt-1 block font-mono text-slate-800">{call.to_number}</span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Provider Adaptor</span>
                  <span className="mt-1 block text-slate-800 font-semibold">{call.provider?.label ?? "N/A"}</span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Call Started</span>
                  <span className="mt-1 block text-slate-800">
                    {call.started_at ? new Date(call.started_at).toLocaleString() : "N/A"}
                  </span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Call Ended</span>
                  <span className="mt-1 block text-slate-800">
                    {call.ended_at ? new Date(call.ended_at).toLocaleString() : "N/A"}
                  </span>
                </div>
                <div className="rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-400">Talktime / Duration</span>
                  <span className="mt-1 block font-semibold text-slate-800">
                    {call.duration_seconds !== null && call.duration_seconds !== undefined
                      ? `${call.duration_seconds} seconds`
                      : "0 seconds"}
                  </span>
                </div>
              </div>
            </div>
          ) : null}
        </SectionCard>

        <SectionCard
          title="Call Timeline"
          subtitle="Chronological event stream for this call."
        >
          {call?.events?.length ? (
            <ol className="space-y-3">
              {call.events.map((event, index) => {
                const getEventTitle = (type: string) => {
                  if (type === 'call.initiated') return '📞 Outbound Call Initiated';
                  if (type === 'call.ringing') return '🔔 Ringing';
                  if (type === 'call.answered') return '✅ Call Answered by Customer';
                  if (type === 'call.completed') return '🏁 Call Completed';
                  if (type === 'call.failed') return '⚠️ Connection Failed';
                  if (type === 'call.gather_digits') {
                    const d = event.payload?.digits ?? '1';
                    return `⌨️ IVR Key Press: Gathered '${d}'`;
                  }
                  return type;
                };

                return (
                  <li key={`${event.type}-${index}`} className={`rounded-md border p-3 shadow-sm transition-all duration-200 ${
                    event.type === 'call.gather_digits'
                      ? event.payload?.digits === '1'
                        ? 'border-emerald-200 bg-emerald-50/50 shadow-emerald-50/50'
                        : 'border-slate-200 bg-slate-50/50'
                      : 'border-slate-200'
                  }`}>
                    <p className="text-sm font-semibold text-slate-800">{getEventTitle(event.type)}</p>
                    <p className="text-xs text-slate-400 mt-0.5">
                      {new Date(event.occurred_at).toLocaleString()}
                    </p>
                    <p className="text-xs text-slate-600 mt-1.5">
                      Provider Event: <span className="font-mono text-slate-500">{event.provider_event_type ?? "n/a"}</span> | Status:{" "}
                      <span className="font-semibold text-slate-700 capitalize">{event.status_after ?? "n/a"}</span>
                    </p>
                  </li>
                );
              })}
            </ol>
          ) : (
            <p className="text-sm text-slate-600">No timeline events recorded.</p>
          )}
        </SectionCard>
      </div>
    </AppShell>
  );
}
