"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { trackFunnelEvent } from "@/lib/funnel";
import { fetchPublicPlans, type PublicPlan } from "@/lib/product-api";

const faqs = [
  {
    question: "Can I switch plans at any time?",
    answer:
      "Yes. You can upgrade or downgrade your plan at any time. Upgrades take effect immediately; downgrades apply at the next billing cycle.",
  },
  {
    question: "Is there a free trial?",
    answer:
      "All plans include a 14-day free trial with no credit card required. You get full access to all features in your chosen plan during the trial.",
  },
  {
    question: "How does agent-based pricing work?",
    answer:
      "Agents are concurrent dial seats — the number of calls your team can run simultaneously. You can have more users logged into the platform than your agent limit; the limit applies to simultaneous active dials.",
  },
  {
    question: "What payment methods do you accept?",
    answer:
      "We accept all major credit cards (Visa, Mastercard, Amex) and ACH bank transfer for annual plans. All payments are processed securely via Stripe.",
  },
  {
    question: "Do you offer discounts for annual billing?",
    answer:
      "Yes — annual plans are billed at a ~20% discount compared to monthly. You can see the exact savings when you toggle to annual pricing above.",
  },
  {
    question: "What happens when I exceed my agent limit?",
    answer:
      "The platform will notify you when you are approaching your limit. Additional agents beyond your plan cap can be added as an add-on, or you can upgrade to the next plan.",
  },
];

export default function LandingPricingClient() {
  const [isYearly, setIsYearly] = useState(false);
  const [plans, setPlans] = useState<PublicPlan[]>([]);

  useEffect(() => {
    void fetchPublicPlans().then((response) => {
      setPlans(response);
    }).catch(() => {
      setPlans([]);
    });
  }, []);

  return (
    <>
      {/* ── Header ── */}
      <section className="bg-white px-6 pb-16 pt-20 text-center">
        <h1 className="text-4xl font-bold tracking-tight text-slate-900 lg:text-5xl">
          Simple, transparent pricing
        </h1>
        <p className="mx-auto mt-4 max-w-xl text-lg text-slate-500">
          No per-minute fees. No surprise invoices. Pick a plan and scale confidently.
        </p>

        {/* Billing Toggle */}
        <div className="mt-8 inline-flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-1">
          <button
            onClick={() => setIsYearly(false)}
            className={`rounded-lg px-5 py-2 text-sm font-medium transition-colors ${
              !isYearly ? "bg-white text-slate-900 shadow-sm" : "text-slate-500 hover:text-slate-700"
            }`}
          >
            Monthly
          </button>
          <button
            onClick={() => setIsYearly(true)}
            className={`flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-medium transition-colors ${
              isYearly ? "bg-white text-slate-900 shadow-sm" : "text-slate-500 hover:text-slate-700"
            }`}
          >
            Annual
            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">
              Save 20%
            </span>
          </button>
        </div>
      </section>

      {/* ── Plans ── */}
      <section className="px-6 pb-24">
        <div className="mx-auto max-w-6xl">
          <div className="grid gap-6 md:grid-cols-3">
            {plans.map((plan, index) => (
              <article
                key={plan.name}
                className={`relative flex flex-col rounded-2xl p-6 ${
                  index === 1
                    ? "border-2 border-indigo-600 bg-white shadow-xl"
                    : "border border-slate-200 bg-white shadow-sm"
                }`}
              >
                {index === 1 && (
                  <span className="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-indigo-600 px-4 py-1 text-xs font-semibold text-white">
                    Most Popular
                  </span>
                )}

                <div>
                  <h2 className="text-xl font-bold text-slate-900">{plan.name}</h2>
                  <p className="mt-1 text-sm text-slate-500">{plan.description ?? "Flexible plan for your team."}</p>

                  <div className="mt-6 flex items-baseline gap-1">
                    <span className="text-4xl font-bold text-slate-900">
                      ${isYearly ? Math.round(Number(plan.price_yearly ?? 0) / 12) : Number(plan.price_monthly ?? 0)}
                    </span>
                    <span className="text-sm text-slate-400">/month</span>
                  </div>
                  {isYearly && (
                    <p className="mt-1 text-sm text-slate-400">
                      Billed ${Number(plan.price_yearly ?? 0).toLocaleString()}/year
                    </p>
                  )}
                </div>

                <ul className="mt-6 flex-1 space-y-3">
                  {plan.features.map((feature) => (
                    <li key={feature.id} className="flex items-start gap-2.5 text-sm text-slate-600">
                      <svg
                        className="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                      >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
                      </svg>
                      {feature.label ?? feature.key}: {feature.value === "-1" ? "∞ Unlimited" : feature.value}
                    </li>
                  ))}
                </ul>

                <Link
                  href={"/register"}
                  onClick={() => {
                    trackFunnelEvent("pricing_cta_start_trial", { placement: "pricing_page", plan: plan.name });
                    trackFunnelEvent("signup_flow_started", { source: "pricing_page" });
                  }}
                  className={`mt-8 block w-full rounded-xl px-4 py-3 text-center text-sm font-semibold transition-colors ${
                    index === 1
                      ? "bg-indigo-600 text-white hover:bg-indigo-700"
                      : "border border-slate-200 text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                  }`}
                >
                  Start Free Trial
                </Link>
              </article>
            ))}
          </div>

          <p className="mt-8 text-center text-sm text-slate-400">
            All plans include a 14-day free trial. No credit card required.
          </p>
        </div>
      </section>

      {/* ── FAQ ── */}
      <section className="border-t border-slate-100 bg-slate-50 px-6 py-24">
        <div className="mx-auto max-w-3xl">
          <h2 className="text-2xl font-bold tracking-tight text-slate-900 text-center">
            Frequently asked questions
          </h2>

          <div className="mt-10 space-y-4">
            {faqs.map((faq) => (
              <details
                key={faq.question}
                className="group rounded-xl border border-slate-200 bg-white px-6 py-4 shadow-sm"
              >
                <summary className="flex cursor-pointer list-none items-center justify-between text-sm font-semibold text-slate-900 hover:text-indigo-600">
                  {faq.question}
                  <svg
                    className="h-5 w-5 flex-shrink-0 text-slate-400 transition-transform group-open:rotate-180"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                  >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                  </svg>
                </summary>
                <p className="mt-3 text-sm leading-relaxed text-slate-500">{faq.answer}</p>
              </details>
            ))}
          </div>
        </div>
      </section>

      {/* ── Bottom CTA ── */}
      <section className="px-6 py-20">
        <div className="mx-auto max-w-2xl text-center">
          <h2 className="text-2xl font-bold text-slate-900">Still have questions?</h2>
          <p className="mt-3 text-slate-500">
            Our team is happy to walk you through the right plan for your team size and use case.
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Link
              href="/demo"
              onClick={() => trackFunnelEvent("pricing_cta_talk_sales", { placement: "pricing_footer" })}
              className="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
            >
              Talk to Sales
            </Link>
            <Link
              href="/landing/contact"
              className="rounded-xl border border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50"
            >
              Contact Support
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}
