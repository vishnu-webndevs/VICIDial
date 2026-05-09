"use client";

import { AppShell, SectionCard } from "@/components/app-shell";

const faqs = [
  {
    question: "How do I connect a telephony provider?",
    answer: "Go to Providers, add credentials, and run Test Connection before enabling failover.",
  },
  {
    question: "How do I upgrade or downgrade my plan?",
    answer: "Open Billing, choose a plan, select monthly or yearly cycle, then apply the plan change.",
  },
  {
    question: "How do I export call history?",
    answer: "Open Call Dashboard, apply filters if needed, and use Export CSV to download records.",
  },
  {
    question: "Where can I review audit and webhook activity?",
    answer: "Use Audit Logs for user actions and Webhooks for provider delivery events.",
  },
];

export default function HelpPage() {
  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      <div className="grid gap-4">
        <SectionCard title="Help and Support" subtitle="Self-service guidance and support channels.">
          <div className="grid gap-3 text-sm">
            {faqs.map((item) => (
              <div key={item.question} className="rounded-md border border-slate-200 p-3">
                <p className="font-medium text-slate-900">{item.question}</p>
                <p className="mt-1 text-slate-600">{item.answer}</p>
              </div>
            ))}
          </div>
        </SectionCard>

        <SectionCard title="Support Channels" subtitle="Use the right channel based on urgency.">
          <div className="grid gap-2 text-sm">
            <p>
              Product Support: <span className="font-medium">support@wnddialer.com</span>
            </p>
            <p>
              Billing Support: <span className="font-medium">billing@wnddialer.com</span>
            </p>
            <p>
              Urgent Incident Escalation: <span className="font-medium">ops@wnddialer.com</span>
            </p>
            <p className="text-slate-600">Include tenant ID, timestamp, and screenshot for faster resolution.</p>
          </div>
        </SectionCard>
      </div>
    </AppShell>
  );
}
