import LoginClient from "./LoginClient";
import type { Metadata } from "next";
import { Suspense } from "react";

export const metadata: Metadata = {
  title: "Login | WND Dialer",
  description: "Sign-in to your WND Dialer account and start running smarter outbound campaigns.",
  robots: {
    index: true,
    follow: true,
  },
  alternates: {
    canonical: "/login",
  },
};

export default function Page() {
  return (
    <Suspense>
      <LoginClient />
    </Suspense>
  );
}
