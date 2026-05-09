"use client";

import Link from "next/link";
import { trackFunnelEvent } from "@/lib/funnel";

const plans = [
  { name: "Starter", monthly: "$49", yearly: "$470.40", bestFor: "Early-stage teams" },
  { name: "Growth", monthly: "$149", yearly: "$1,430.40", bestFor: "Scaling outbound ops" },
  { name: "Pro", monthly: "$399", yearly: "$3,830.40", bestFor: "Multi-team operations" },
];

export default function PricingPage() {
  return (
    <main className="mx-auto w-full max-w-6xl px-6 py-12">
      <header>
        <h1 className="text-3xl font-bold text-slate-900">Pricing</h1>
        <p className="mt-2 text-slate-600">Transparent plans with usage quotas and upgrade flexibility.</p>
      </header>

      <section className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {plans.map((plan) => (
          <article key={plan.name} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">{plan.name}</h2>
            <p className="mt-1 text-sm text-slate-600">{plan.bestFor}</p>
            <p className="mt-4 text-xl font-bold text-slate-900">{plan.monthly}/mo</p>
            <p className="text-sm text-slate-500">{plan.yearly}/year</p>
          </article>
        ))}
      </section>

      <div className="mt-8 flex flex-wrap gap-3">
        <Link
          href="/register"
          onClick={() => {
            trackFunnelEvent("pricing_cta_start_trial", { placement: "footer" });
            trackFunnelEvent("signup_flow_started", { source: "pricing_page" });
          }}
          className="rounded-md bg-slate-900 px-5 py-2 text-sm font-medium text-white"
        >
          Start Trial
        </Link>
        <Link
          href="/demo"
          onClick={() => trackFunnelEvent("pricing_cta_talk_sales", { placement: "footer" })}
          className="rounded-md border border-slate-300 px-5 py-2 text-sm font-medium text-slate-800"
        >
          Talk to Sales
        </Link>
      </div>
    </main>
  );
}
