"use client";

import { FormEvent, useState } from "react";
import { apiRequest } from "@/lib/api";

export default function ResetPasswordPage() {
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setMessage("");

    const formData = new FormData(event.currentTarget);
    const payload = {
      token: String(formData.get("token") ?? ""),
      email: String(formData.get("email") ?? ""),
      password: String(formData.get("password") ?? ""),
      password_confirmation: String(formData.get("password_confirmation") ?? ""),
    };

    try {
      await apiRequest<{ data: { status: string } }>("/auth/reset-password", {
        method: "POST",
        body: payload,
      });
      setMessage("Password has been reset successfully.");
      event.currentTarget.reset();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Reset failed.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-lg p-6">
      <h1 className="text-2xl font-semibold text-slate-900">Reset Password</h1>
      <form className="mt-6 space-y-3" onSubmit={onSubmit}>
        <input
          name="token"
          className="w-full rounded-md border p-2"
          placeholder="Reset Token"
          required
        />
        <input
          type="email"
          name="email"
          className="w-full rounded-md border p-2"
          placeholder="Email"
          required
        />
        <input
          type="password"
          name="password"
          className="w-full rounded-md border p-2"
          placeholder="New Password"
          required
        />
        <input
          type="password"
          name="password_confirmation"
          className="w-full rounded-md border p-2"
          placeholder="Confirm New Password"
          required
        />
        <button
          type="submit"
          disabled={loading}
          className="w-full rounded-md bg-slate-900 px-4 py-2 text-white disabled:opacity-60"
        >
          {loading ? "Submitting..." : "Reset Password"}
        </button>
      </form>
      {message ? <p className="mt-4 text-sm text-slate-700">{message}</p> : null}
    </main>
  );
}
