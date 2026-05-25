import DemoClient from "./DemoClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Interactive Demo | WND Dialer",
  description: "Try WND Dialer right in your browser with our interactive playground. No registration or credit card required.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/demo",
  },
};

export default function Page() {
  return <DemoClient />;
}
