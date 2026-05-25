"use client";

import { FormEvent, useState } from "react";
import { apiRequest } from "@/lib/api";

export default function ResetPasswordClient() {
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setMessage("");

    const formData = new FormData(event.currentTarget);
    const payload = {
      token: String(formData.get("token") ?? "").trim(),
      email: String(formData.get("email") ?? "").trim(),
      password: String(formData.get("password") ?? ""),
      password_confirmation: String(formData.get("password_confirmation") ?? ""),
    };

    if (payload.password !== payload.password_confirmation) {
      setMessage("Passwords do not match.");
      setLoading(false);
      return;
    }

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
          className="w-full rounded-md border p-2 text-slate-900 placeholder:text-slate-400 focus:outline-indigo-500"
          placeholder="Reset Token"
          required
          maxLength={255}
        />
        <input
          type="email"
          name="email"
          className="w-full rounded-md border p-2 text-slate-900 placeholder:text-slate-400 focus:outline-indigo-500"
          placeholder="Email"
          required
          maxLength={100}
        />
        <input
          type="password"
          name="password"
          className="w-full rounded-md border p-2 text-slate-900 placeholder:text-slate-400 focus:outline-indigo-500"
          placeholder="New Password"
          required
          maxLength={64}
        />
        <input
          type="password"
          name="password_confirmation"
          className="w-full rounded-md border p-2 text-slate-900 placeholder:text-slate-400 focus:outline-indigo-500"
          placeholder="Confirm New Password"
          required
          maxLength={64}
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
