import { Suspense } from "react";
import RegisterClient from "../register/RegisterClient";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Accept Invitation | WND Dialer",
  description: "Accept your team invitation and complete your profile.",
  robots: {
    index: false,
    follow: false,
  },
};

export default function AcceptInvitePage() {
  return (
    <Suspense fallback={<div style={{ minHeight: "100vh", backgroundColor: "#f5f5f9" }} />}>
      <RegisterClient />
    </Suspense>
  );
}
