import LandingContactClient from "./LandingContactClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Contact Us | WND Dialer",
  description: "Get in touch with WND Dialer. Talk to our sales team about the right plan or contact customer support.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/landing/contact",
  },
};

export default function Page() {
  return <LandingContactClient />;
}
