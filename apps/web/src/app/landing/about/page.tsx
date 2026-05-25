import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "About | WND Dialer",
  description:
    "Learn about WND Dialer — our mission, values, and the team building the next generation of outbound calling software.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/landing/about",
  },
};

const values = [
  {
    title: "Performance First",
    description:
      "Every feature we build is measured against a simple question: does this help agents close more conversations? If not, it doesn't ship.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" />
      </svg>
    ),
  },
  {
    title: "Radical Transparency",
    description:
      "Clear pricing, clear data, clear limits. No surprise overages, no black-box algorithms, no vendor lock-in tactics.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
      </svg>
    ),
  },
  {
    title: "Reliability at Scale",
    description:
      "Call centers can't afford downtime. We build for 99.9% uptime and design graceful fallbacks for every failure mode we can anticipate.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
      </svg>
    ),
  },
  {
    title: "Customer Obsession",
    description:
      "We sit in on customer calls, read every support ticket, and ship updates based on actual operational pain — not internal roadmap politics.",
    icon: (
      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    ),
  },
];

const team = [
  { name: "Alex Rivera", role: "Co-founder & CEO", background: "Former VP Sales, ran 80-agent outbound floor" },
  { name: "Priya Nair", role: "Co-founder & CTO", background: "10 years building real-time telephony systems" },
  { name: "Jordan Mills", role: "Head of Product", background: "Ex-call center manager turned product designer" },
  { name: "Sam Chen", role: "Head of Engineering", background: "Distributed systems engineer, ex-Twilio" },
];

export default function AboutPage() {
  return (
    <>
      {/* ── Hero ── */}
      <section className="bg-white px-6 pb-20 pt-20">
        <div className="mx-auto max-w-3xl text-center">
          <span className="inline-flex items-center gap-2 rounded-full border border-indigo-100 bg-indigo-50 px-4 py-1.5 text-sm font-medium text-indigo-700">
            <span className="h-1.5 w-1.5 rounded-full bg-indigo-500" />
            About WND Dialer
          </span>
          <h1 className="mt-6 text-4xl font-bold tracking-tight text-slate-900 lg:text-5xl">
            Built by people who ran outbound floors
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-slate-500">
            WND Dialer was born out of frustration with legacy dialers — clunky, expensive, and built for the
            wrong era. We set out to build the platform we always wished we had.
          </p>
        </div>
      </section>

      {/* ── Mission ── */}
      <section className="bg-slate-950 px-6 py-20 text-center">
        <div className="mx-auto max-w-3xl">
          <p className="text-xs font-semibold uppercase tracking-widest text-indigo-400">Our Mission</p>
          <blockquote className="mt-6 text-2xl font-medium leading-relaxed text-white lg:text-3xl">
            &ldquo;To give every outbound team — regardless of size — the same dialing intelligence that was
            previously only available to enterprise call centers with million-dollar budgets.&rdquo;
          </blockquote>
        </div>
      </section>

      {/* ── Story ── */}
      <section className="px-6 py-24">
        <div className="mx-auto max-w-4xl">
          <div className="grid gap-12 md:grid-cols-2 md:items-center">
            <div>
              <h2 className="text-3xl font-bold tracking-tight text-slate-900">
                The story behind the platform
              </h2>
              <div className="mt-6 space-y-4 text-slate-600">
                <p>
                  In 2021, our co-founders Alex and Priya were on opposite sides of the same problem. Alex was
                  managing an 80-agent outbound floor and spending half his week firefighting dialer issues.
                  Priya was building telephony infrastructure and watching her clients struggle with tools that
                  hadn&apos;t evolved in a decade.
                </p>
                <p>
                  They met at a sales tech conference, compared notes, and decided to build something better
                  together. WND Dialer launched in 2022 with three customers and a single belief: outbound
                  calling should be fast to set up, easy to manage, and transparent in its results.
                </p>
                <p>
                  Today, WND Dialer powers thousands of agents across call centers, sales teams, and agencies —
                  and we&apos;re still just getting started.
                </p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              {[
                { value: "2022", label: "Founded" },
                { value: "10k+", label: "Agents powered" },
                { value: "500+", label: "Customers" },
                { value: "99.9%", label: "Uptime SLA" },
              ].map((stat) => (
                <div
                  key={stat.label}
                  className="rounded-2xl border border-slate-100 bg-slate-50 p-6 text-center"
                >
                  <div className="text-3xl font-bold text-slate-900">{stat.value}</div>
                  <div className="mt-1 text-sm text-slate-500">{stat.label}</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* ── Values ── */}
      <section className="bg-slate-50 px-6 py-24">
        <div className="mx-auto max-w-6xl">
          <div className="text-center">
            <h2 className="text-3xl font-bold tracking-tight text-slate-900">How we operate</h2>
            <p className="mt-4 text-lg text-slate-500">
              The principles that guide every product decision we make.
            </p>
          </div>

          <div className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {values.map((value) => (
              <article
                key={value.title}
                className="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm"
              >
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                  {value.icon}
                </div>
                <h3 className="mt-4 text-base font-semibold text-slate-900">{value.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-500">{value.description}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* ── Team ── */}
      <section className="px-6 py-24">
        <div className="mx-auto max-w-4xl">
          <div className="text-center">
            <h2 className="text-3xl font-bold tracking-tight text-slate-900">The team</h2>
            <p className="mt-4 text-lg text-slate-500">
              Operators and engineers who&apos;ve lived the problem we&apos;re solving.
            </p>
          </div>

          <div className="mt-12 grid gap-6 sm:grid-cols-2">
            {team.map((member) => (
              <article
                key={member.name}
                className="flex items-start gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm"
              >
                <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-indigo-600 text-base font-bold text-white">
                  {member.name.charAt(0)}
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">{member.name}</h3>
                  <p className="text-sm font-medium text-indigo-600">{member.role}</p>
                  <p className="mt-1 text-xs text-slate-400">{member.background}</p>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* ── CTA ── */}
      <section className="border-t border-slate-100 bg-slate-50 px-6 py-20 text-center">
        <h2 className="text-2xl font-bold text-slate-900">Want to see what we&apos;ve built?</h2>
        <p className="mx-auto mt-3 max-w-md text-slate-500">
          Try WND Dialer free for 14 days or book a walkthrough with our team.
        </p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          <Link
            href="/register"
            className="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            Start Free Trial
          </Link>
          <Link
            href="/landing/contact"
            className="rounded-xl border border-slate-200 px-6 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-white"
          >
            Get in Touch
          </Link>
        </div>
      </section>
    </>
  );
}
