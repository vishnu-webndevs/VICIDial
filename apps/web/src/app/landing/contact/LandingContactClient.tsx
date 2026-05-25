"use client";

import { useState } from "react";

const contactInfo = [
  {
    title: "Sales",
    description: "Talk to our team about the right plan for your outbound program.",
    detail: "sales@wnddialer.com",
    icon: (
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
      </svg>
    ),
  },
  {
    title: "Support",
    description: "Get help from our support team — typically respond within a few hours.",
    detail: "support@wnddialer.com",
    icon: (
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
    ),
  },
  {
    title: "Headquarters",
    description: "WND Dialer, Inc.",
    detail: "San Francisco, CA 94105",
    icon: (
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    ),
  },
];

type FormState = {
  name: string;
  email: string;
  company: string;
  subject: string;
  message: string;
};

type SubmitStatus = "idle" | "submitting" | "success" | "error";

export default function LandingContactClient() {
  const [form, setForm] = useState<FormState>({
    name: "",
    email: "",
    company: "",
    subject: "general",
    message: "",
  });
  const [status, setStatus] = useState<SubmitStatus>("idle");

  function handleChange(e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) {
    setForm((prev) => ({ ...prev, [e.target.name]: e.target.value }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setStatus("submitting");

    // Clean and validate inputs on submission to prevent empty spaces or excessive/malicious payloads
    const sanitizedForm = {
      name: form.name.trim(),
      email: form.email.trim(),
      company: form.company.trim(),
      subject: form.subject,
      message: form.message.trim(),
    };

    if (!sanitizedForm.name || !sanitizedForm.email || !sanitizedForm.message) {
      setStatus("error");
      return;
    }

    // Simulate form submission
    await new Promise((r) => setTimeout(r, 1200));
    setStatus("success");
  }

  return (
    <>
      {/* ── Header ── */}
      <section className="bg-white px-6 pb-16 pt-20 text-center">
        <h1 className="text-4xl font-bold tracking-tight text-slate-900 lg:text-5xl">Get in touch</h1>
        <p className="mx-auto mt-4 max-w-xl text-lg text-slate-500">
          Whether you have a sales question or need support — our team is ready to help.
        </p>
      </section>

      {/* ── Content ── */}
      <section className="px-6 pb-24">
        <div className="mx-auto grid max-w-5xl gap-12 lg:grid-cols-[1fr_1.4fr]">
          {/* Contact Info */}
          <div>
            <h2 className="text-lg font-semibold text-slate-900">Contact options</h2>
            <div className="mt-6 space-y-6">
              {contactInfo.map((info) => (
                <div key={info.title} className="flex gap-4">
                  <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                    {info.icon}
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-slate-900">{info.title}</h3>
                    <p className="mt-0.5 text-xs text-slate-500">{info.description}</p>
                    <p className="mt-1 text-sm font-medium text-indigo-600">{info.detail}</p>
                  </div>
                </div>
              ))}
            </div>

            <div className="mt-10 rounded-2xl border border-slate-100 bg-slate-50 p-5">
              <h3 className="text-sm font-semibold text-slate-900">Response times</h3>
              <ul className="mt-3 space-y-2">
                {[
                  { label: "Sales inquiries", time: "Within 1 business day" },
                  { label: "Support (Growth/Pro)", time: "Within 4 hours" },
                  { label: "Support (Starter)", time: "Within 1 business day" },
                ].map((item) => (
                  <li key={item.label} className="flex items-center justify-between text-sm">
                    <span className="text-slate-500">{item.label}</span>
                    <span className="font-medium text-slate-700">{item.time}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>

          {/* Form */}
          <div className="rounded-2xl border border-slate-100 bg-white p-8 shadow-sm">
            {status === "success" ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
                  <svg className="h-8 w-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <h3 className="mt-4 text-lg font-semibold text-slate-900">Message sent!</h3>
                <p className="mt-2 text-sm text-slate-500">
                  We&apos;ve received your message and will get back to you shortly.
                </p>
                <button
                  onClick={() => {
                    setStatus("idle");
                    setForm({ name: "", email: "", company: "", subject: "general", message: "" });
                  }}
                  className="mt-6 text-sm font-medium text-indigo-600 hover:text-indigo-700"
                >
                  Send another message
                </button>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-5">
                <h2 className="text-lg font-semibold text-slate-900">Send us a message</h2>

                {status === "error" && (
                  <div className="rounded-xl bg-red-50 p-3 text-xs text-red-600">
                    Please fill out all required fields correctly.
                  </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label htmlFor="name" className="block text-sm font-medium text-slate-700">
                      Full name <span className="text-red-500">*</span>
                    </label>
                    <input
                      id="name"
                      name="name"
                      type="text"
                      required
                      maxLength={100}
                      value={form.name}
                      onChange={handleChange}
                      placeholder="Jane Smith"
                      className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                  <div>
                    <label htmlFor="email" className="block text-sm font-medium text-slate-700">
                      Work email <span className="text-red-500">*</span>
                    </label>
                    <input
                      id="email"
                      name="email"
                      type="email"
                      required
                      maxLength={100}
                      value={form.email}
                      onChange={handleChange}
                      placeholder="jane@company.com"
                      className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div>
                  <label htmlFor="company" className="block text-sm font-medium text-slate-700">
                    Company name
                  </label>
                  <input
                    id="company"
                    name="company"
                    type="text"
                    maxLength={100}
                    value={form.company}
                    onChange={handleChange}
                    placeholder="Acme Corp"
                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label htmlFor="subject" className="block text-sm font-medium text-slate-700">
                    Subject <span className="text-red-500">*</span>
                  </label>
                  <select
                    id="subject"
                    name="subject"
                    required
                    value={form.subject}
                    onChange={handleChange}
                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  >
                    <option value="general">General inquiry</option>
                    <option value="sales">Sales / Pricing</option>
                    <option value="support">Technical support</option>
                    <option value="demo">Book a demo</option>
                    <option value="partnership">Partnership</option>
                  </select>
                </div>

                <div>
                  <label htmlFor="message" className="block text-sm font-medium text-slate-700">
                    Message <span className="text-red-500">*</span>
                  </label>
                  <textarea
                    id="message"
                    name="message"
                    required
                    rows={5}
                    maxLength={1000}
                    value={form.message}
                    onChange={handleChange}
                    placeholder="Tell us how we can help..."
                    className="mt-1 w-full resize-none rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  />
                </div>

                <button
                  type="submit"
                  disabled={status === "submitting"}
                  className="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {status === "submitting" ? "Sending…" : "Send Message"}
                </button>
              </form>
            )}
          </div>
        </div>
      </section>
    </>
  );
}
