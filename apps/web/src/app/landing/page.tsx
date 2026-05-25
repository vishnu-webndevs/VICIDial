import LandingClient from "./LandingClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "WND Dialer - AI-Powered Outbound Platform",
  description: "Built for call centers, sales teams, and agencies that need smarter outbound automation.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/landing",
  },
};

export default function Page() {
  return <LandingClient />;
}
