import ForgotPasswordClient from "./ForgotPasswordClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Forgot Password | WND Dialer",
  description: "Reset your WND Dialer password securely by providing your email address.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/forgot-password",
  },
};

export default function Page() {
  return <ForgotPasswordClient />;
}
