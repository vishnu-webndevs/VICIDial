import ResetPasswordClient from "./ResetPasswordClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Reset Password | WND Dialer",
  description: "Securely reset your WND Dialer password by choosing a strong new password.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/reset-password",
  },
};

export default function Page() {
  return <ResetPasswordClient />;
}
