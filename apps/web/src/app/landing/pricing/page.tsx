import LandingPricingClient from "./LandingPricingClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Pricing | WND Dialer",
  description: "Simple, transparent pricing. Plans that grow with your team. No per-minute fees, no surprise invoices.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/landing/pricing",
  },
};

export default function Page() {
  return <LandingPricingClient />;
}
