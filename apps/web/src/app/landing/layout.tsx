import type { Metadata } from "next";
import LandingNav from "./_components/LandingNav";
import LandingFooter from "./_components/LandingFooter";

export const metadata: Metadata = {
  title: {
    default: "WND Dialer — AI-Powered Outbound Dialing Platform",
    template: "%s | WND Dialer",
  },
  description:
    "AI-powered outbound dialer built for call centers, sales teams, and agencies. Reduce dead time by 28%, increase connect rates, and scale campaigns with confidence.",
  keywords: [
    "outbound dialer",
    "call center software",
    "AI dialer",
    "sales dialing",
    "predictive dialer",
    "outbound calling",
    "call automation",
    "sales engagement platform",
  ],
  openGraph: {
    type: "website",
    title: "WND Dialer — AI-Powered Outbound Dialing Platform",
    description:
      "Built for call centers, sales teams, and agencies that need smarter automation and measurable conversion gains.",
    siteName: "WND Dialer",
  },
  twitter: {
    card: "summary_large_image",
    title: "WND Dialer — AI-Powered Outbound Dialing Platform",
    description: "Smarter outbound dialing for call centers, sales teams, and agencies.",
  },
};

export default function LandingLayout({ children }: { children: React.ReactNode }) {
  return (
    /*
     * Force landing palette over global body defaults without inline style attrs.
     * Tailwind utility classes on children take over from here.
     */
    <div className="min-h-screen !bg-white !text-slate-900" suppressHydrationWarning>
      <LandingNav />
      <main>{children}</main>
      <LandingFooter />
    </div>
  );
}
