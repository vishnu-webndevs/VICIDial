"use client";

import Link from "next/link";
import { trackFunnelEvent } from "@/lib/funnel";

const stats = [
  { value: "28%", label: "Less dialer dead time" },
  { value: "3×", label: "Faster campaign launches" },
  { value: "10k+", label: "Agents powered" },
  { value: "99.9%", label: "Platform uptime" },
];

const features = [
  {
    title: "AI-Powered Smart Dialing",
    description:
      "Adaptive pacing algorithms learn from your campaign outcomes and automatically adjust dial rates to maximize live-answer rates.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2" />
      </svg>
    ),
  },
  {
    title: "Live Call Monitoring",
    description:
      "Supervisors get real-time visibility into every active call — listen, whisper, or barge in to coach agents without disrupting flow.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
      </svg>
    ),
  },
  {
    title: "Campaign Orchestration",
    description:
      "Build, schedule, and manage multiple campaigns simultaneously with granular controls for pacing, retry logic, and agent assignment.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
      </svg>
    ),
  },
  {
    title: "Conversion Analytics",
    description:
      "Clear dashboards surface the metrics that matter — connect rates, talk time, disposition breakdowns, and agent performance rankings.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
    ),
  },
  {
    title: "Multi-Tenant Isolation",
    description:
      "Run separate programs for multiple clients inside a single platform. Each tenant gets fully isolated data, branding, and reporting.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
      </svg>
    ),
  },
  {
    title: "Smart Retry Automation",
    description:
      "Automatically re-queue no-answers, busy signals, and voicemails with configurable wait windows and attempt limits per list.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
    ),
  },
];

const steps = [
  {
    step: "01",
    title: "Import Your Leads",
    description: "Upload a CSV or connect your CRM. WND Dialer validates, deduplicates, and segments your list automatically.",
  },
  {
    step: "02",
    title: "Configure Your Campaign",
    description: "Set your dial mode, pacing rules, retry logic, and agent routing in minutes — no engineering required.",
  },
  {
    step: "03",
    title: "Go Live & Scale",
    description: "Launch and watch real-time metrics flow in. Scale agents up or down instantly based on live performance data.",
  },
];

const testimonials = [
  {
    quote: "We reduced dialer dead time by 28% in two weeks. The smart pacing alone paid for the platform.",
    name: "Ops Lead",
    company: "B2B Sales Team",
  },
  {
    quote: "Campaign launches are now predictable and easy to manage. Our team spends time selling, not configuring.",
    name: "Director",
    company: "Growth Agency",
  },
  {
    quote: "Our reps focus on conversations instead of tool friction. Connect rates are up across every list we've run.",
    name: "Head of Revenue",
    company: "SaaS Scale-up",
  },
];

const plans = [
  {
    name: "Starter",
    price: "$49",
    description: "Best for first outbound team",
    features: ["Up to 5 agents", "1 active campaign", "Basic analytics"],
    highlighted: false,
  },
  {
    name: "Growth",
    price: "$149",
    description: "For scaling conversions",
    features: ["Up to 25 agents", "Unlimited campaigns", "Advanced analytics + retry automation"],
    highlighted: true,
  },
  {
    name: "Pro",
    price: "$399",
    description: "For multi-team performance",
    features: ["Unlimited agents", "Multi-tenant isolation", "Priority support + custom integrations"],
    highlighted: false,
  },
];

export default function LandingPage() {
  return (
    <>
      {/* ── Hero ── */}
      <section className="relative overflow-hidden bg-white px-6 pb-24 pt-20">
        {/* Subtle dot grid background */}
        <div
          className="pointer-events-none absolute inset-0 -z-10"
          style={{
            backgroundImage:
              "radial-gradient(circle at 1px 1px, rgb(226 232 240) 1px, transparent 0)",
            backgroundSize: "32px 32px",
          }}
        />
        {/* Gradient blob */}
        <div
          className="pointer-events-none absolute -top-32 right-0 -z-10 h-96 w-96 rounded-full opacity-20"
          style={{ background: "radial-gradient(circle, #6366f1 0%, transparent 70%)" }}
        />

        <div className="mx-auto max-w-4xl text-center">
          <span className="inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-indigo-50 px-4 py-1.5 text-sm font-medium text-indigo-700">
            <span className="h-1.5 w-1.5 rounded-full bg-indigo-500" />
            AI-Powered Outbound Platform
          </span>

          <h1 className="mt-6 text-5xl font-bold tracking-tight text-slate-900 lg:text-6xl">
            The smarter way to run{" "}
            <span
              style={{
                backgroundImage: "linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)",
                WebkitBackgroundClip: "text",
                WebkitTextFillColor: "transparent",
                backgroundClip: "text",
              }}
            >
              outbound campaigns
            </span>
          </h1>

          <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
            Built for call centers, sales teams, and agencies that need smarter automation, reliable operations,
            and measurable conversion gains from every campaign.
          </p>

          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            <Link
              href="/register"
              onClick={() => {
                trackFunnelEvent("landing_cta_start_trial", { placement: "hero" });
                trackFunnelEvent("signup_flow_started", { source: "landing_hero" });
              }}
              className="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
            >
              Start Free Trial
            </Link>
            <Link
              href="/landing/pricing"
              onClick={() => trackFunnelEvent("landing_cta_view_pricing", { placement: "hero" })}
              className="rounded-xl border border-slate-200 bg-white px-6 py-3 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:border-slate-300 hover:bg-slate-50"
            >
              View Pricing
            </Link>
            <Link
              href="/demo"
              onClick={() => trackFunnelEvent("landing_cta_book_demo", { placement: "hero" })}
              className="rounded-xl px-6 py-3 text-sm font-semibold text-slate-600 transition-colors hover:text-slate-900"
            >
              Book a Demo →
            </Link>
          </div>

          {/* Stats */}
          <div className="mt-16 grid grid-cols-2 gap-6 rounded-2xl border border-slate-100 bg-slate-50 p-6 sm:grid-cols-4">
            {stats.map((stat) => (
              <div key={stat.label} className="text-center">
                <div className="text-3xl font-bold text-slate-900">{stat.value}</div>
                <div className="mt-1 text-sm text-slate-500">{stat.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Social Proof Strip ── */}
      <section className="border-y border-slate-100 bg-slate-50 px-6 py-8">
        <div className="mx-auto max-w-6xl">
          <p className="text-center text-xs font-semibold uppercase tracking-widest text-slate-400">
            Trusted by outbound teams at
          </p>
          <div className="mt-6 flex flex-wrap items-center justify-center gap-10">
            {["Acme Corp", "GrowthLab", "SalesForce Pro", "NovaCalls", "ReachDirect"].map((brand) => (
              <span key={brand} className="text-sm font-semibold text-slate-400">
                {brand}
              </span>
            ))}
          </div>
        </div>
      </section>

      {/* ── Features ── */}
      <section className="px-6 py-24">
        <div className="mx-auto max-w-6xl">
          <div className="text-center">
            <h2 className="text-3xl font-bold tracking-tight text-slate-900 lg:text-4xl">
              Everything you need to run outbound at scale
            </h2>
            <p className="mx-auto mt-4 max-w-2xl text-lg text-slate-500">
              From first dial to final report — WND Dialer covers every step of your outbound workflow.
            </p>
          </div>

          <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {features.map((feature) => (
              <article
                key={feature.title}
                className="group rounded-2xl border border-slate-100 bg-white p-6 shadow-sm transition-shadow hover:shadow-md"
              >
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                  {feature.icon}
                </div>
                <h3 className="mt-4 text-base font-semibold text-slate-900">{feature.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-500">{feature.description}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* ── How It Works ── */}
      <section className="bg-slate-50 px-6 py-24">
        <div className="mx-auto max-w-6xl">
          <div className="text-center">
            <h2 className="text-3xl font-bold tracking-tight text-slate-900 lg:text-4xl">
              From zero to live calls in minutes
            </h2>
            <p className="mx-auto mt-4 max-w-xl text-lg text-slate-500">
              No long onboarding. No dev work. Just a fast path from lead list to connected conversation.
            </p>
          </div>

          <div className="mt-16 grid gap-8 md:grid-cols-3">
            {steps.map((step, index) => (
              <div key={step.step} className="relative">
                {index < steps.length - 1 && (
                  <div className="absolute right-0 top-6 hidden h-px w-1/2 translate-x-1/2 bg-indigo-100 md:block" />
                )}
                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-sm font-bold text-white shadow-sm">
                  {step.step}
                </div>
                <h3 className="mt-5 text-lg font-semibold text-slate-900">{step.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-500">{step.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Testimonials ── */}
      <section className="px-6 py-24">
        <div className="mx-auto max-w-6xl">
          <div className="text-center">
            <h2 className="text-3xl font-bold tracking-tight text-slate-900 lg:text-4xl">
              Don&apos;t take our word for it
            </h2>
            <p className="mt-4 text-lg text-slate-500">
              Teams running real outbound programs, sharing real results.
            </p>
          </div>

          <div className="mt-12 grid gap-6 md:grid-cols-3">
            {testimonials.map((item) => (
              <article
                key={item.name}
                className="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm"
              >
                {/* Quote marks */}
                <svg className="h-8 w-8 text-indigo-100" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z" />
                </svg>
                <p className="mt-3 text-sm leading-relaxed text-slate-700">{item.quote}</p>
                <div className="mt-4 border-t border-slate-100 pt-4">
                  <p className="text-sm font-semibold text-slate-900">{item.name}</p>
                  <p className="text-xs text-slate-400">{item.company}</p>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* ── Pricing Teaser ── */}
      <section className="bg-slate-50 px-6 py-24">
        <div className="mx-auto max-w-6xl">
          <div className="text-center">
            <h2 className="text-3xl font-bold tracking-tight text-slate-900 lg:text-4xl">
              Simple, predictable pricing
            </h2>
            <p className="mt-4 text-lg text-slate-500">
              Plans that grow with your team. No hidden fees. Upgrade anytime.
            </p>
          </div>

          <div className="mt-12 grid gap-6 md:grid-cols-3">
            {plans.map((plan) => (
              <article
                key={plan.name}
                className={`relative rounded-2xl p-6 ${
                  plan.highlighted
                    ? "border-2 border-indigo-600 bg-white shadow-lg"
                    : "border border-slate-200 bg-white shadow-sm"
                }`}
              >
                {plan.highlighted && (
                  <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-indigo-600 px-3 py-0.5 text-xs font-semibold text-white">
                    Most Popular
                  </span>
                )}
                <h3 className="text-lg font-semibold text-slate-900">{plan.name}</h3>
                <p className="mt-1 text-sm text-slate-500">{plan.description}</p>
                <div className="mt-4 flex items-baseline gap-1">
                  <span className="text-4xl font-bold text-slate-900">{plan.price}</span>
                  <span className="text-sm text-slate-400">/month</span>
                </div>
                <ul className="mt-6 space-y-2">
                  {plan.features.map((f) => (
                    <li key={f} className="flex items-start gap-2 text-sm text-slate-600">
                      <svg
                        className="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                      >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                      </svg>
                      {f}
                    </li>
                  ))}
                </ul>
                <Link
                  href="/register"
                  onClick={() => {
                    trackFunnelEvent("landing_cta_start_trial", { placement: "pricing_teaser", plan: plan.name });
                    trackFunnelEvent("signup_flow_started", { source: "landing_pricing_teaser" });
                  }}
                  className={`mt-6 block w-full rounded-xl px-4 py-2.5 text-center text-sm font-semibold transition-colors ${
                    plan.highlighted
                      ? "bg-indigo-600 text-white hover:bg-indigo-700"
                      : "border border-slate-200 text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                  }`}
                >
                  Get Started
                </Link>
              </article>
            ))}
          </div>

          <div className="mt-8 text-center">
            <Link
              href="/landing/pricing"
              onClick={() => trackFunnelEvent("landing_cta_view_pricing", { placement: "pricing_teaser" })}
              className="text-sm font-medium text-indigo-600 hover:text-indigo-700"
            >
              Compare all plan features →
            </Link>
          </div>
        </div>
      </section>

      {/* ── Final CTA ── */}
      <section className="px-6 py-24">
        <div className="mx-auto max-w-4xl overflow-hidden rounded-3xl bg-slate-950 px-8 py-16 text-center">
          <h2 className="text-3xl font-bold tracking-tight text-white lg:text-4xl">
            Ready to transform your outbound?
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-lg text-slate-400">
            Join hundreds of teams already running smarter campaigns with WND Dialer. Start your free trial — no credit card required.
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
            <Link
              href="/register"
              onClick={() => {
                trackFunnelEvent("landing_cta_start_trial", { placement: "bottom_cta" });
                trackFunnelEvent("signup_flow_started", { source: "landing_bottom_cta" });
              }}
              className="rounded-xl bg-indigo-500 px-8 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-400"
            >
              Start Free Trial
            </Link>
            <Link
              href="/demo"
              onClick={() => trackFunnelEvent("landing_cta_book_demo", { placement: "bottom_cta" })}
              className="rounded-xl border border-slate-700 px-8 py-3 text-sm font-semibold text-slate-300 transition-colors hover:border-slate-500 hover:text-white"
            >
              Book a Demo
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}
