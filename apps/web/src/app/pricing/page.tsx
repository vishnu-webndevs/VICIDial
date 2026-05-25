import PricingClient from "./PricingClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Pricing | WND Dialer",
  description: "Transparent plans with usage quotas and upgrade flexibility.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/pricing",
  },
};

export default function Page() {
  return <PricingClient />;
}
