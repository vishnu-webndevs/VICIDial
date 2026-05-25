import RegisterClient from "./RegisterClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Register | WND Dialer",
  description: "Create your owner account and start your 14-day free trial on WND Dialer today.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/register",
  },
};

export default function Page() {
  return <RegisterClient />;
}
