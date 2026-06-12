"use client";

import { FormEvent, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { apiRequest } from "@/lib/api";
import { fetchSessionProfile, recoverAccount } from "@/lib/product-api";
import type { LoginResponse } from "@/types/auth";
import { isOnboardingComplete } from "@/lib/onboarding";
import {
  clearSession,
  getSessionStorageState,
  getRoleAwareRoute,
  saveSession,
  syncTenantFromProfile,
} from "@/lib/auth-session";
import {
  Box,
  Button,
  FormTextField,
  Link as MuiLink,
  Typography,
} from "@/ui";

export default function LoginClient() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [message, setMessage] = useState<string>("");
  const [loading, setLoading] = useState(false);
  const [pendingDeletion, setPendingDeletion] = useState<{
    scheduledAt: string;
    email: string;
    password: string;
  } | null>(null);
  const [recovering, setRecovering] = useState(false);

  useEffect(() => {
    const { token } = getSessionStorageState();
    if (token) {
      router.replace("/dashboard");
    }
  }, [router]);

  useEffect(() => {
    const scheduledAt = searchParams.get("accountDeletionScheduledAt");
    if (scheduledAt) {
      const dateText = Number.isNaN(new Date(scheduledAt).getTime())
        ? scheduledAt
        : new Date(scheduledAt).toLocaleString();
      setMessage(`Account deletion requested. Your account is scheduled for permanent deletion on ${dateText}.`);
    }
  }, [searchParams]);

  async function onRecover() {
    if (!pendingDeletion) {
      return;
    }

    setRecovering(true);
    setMessage("");
    try {
      const response = await recoverAccount(pendingDeletion.email, pendingDeletion.password);
      saveSession(response.token);
      setPendingDeletion(null);
      const profile = await fetchSessionProfile();
      syncTenantFromProfile(profile);
      const onboardingDone = isOnboardingComplete(profile.current_tenant?.id ?? null);
      router.push(getRoleAwareRoute(profile, onboardingDone));
    } catch (error) {
      clearSession();
      const errorMessage =
        error instanceof Error ? error.message : "Account recovery failed.";
      setMessage(errorMessage);
    } finally {
      setRecovering(false);
    }
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setMessage("");
    setPendingDeletion(null);

    const formData = new FormData(event.currentTarget);
    const payload = {
      email: String(formData.get("email") ?? "").trim(),
      password: String(formData.get("password") ?? ""),
    };

    try {
      type LoginApiError = {
        error: {
          code: string;
          message?: string;
          scheduled_permanent_deletion_at?: string;
        };
      };
      type LoginApiResponse = (LoginResponse & { success?: boolean; message?: string }) | LoginApiError;

      const response = await apiRequest<LoginApiResponse>("/auth/login", {
        method: "POST",
        body: payload,
      });

      if ("error" in response && response.error?.code === "ACCOUNT_PENDING_DELETION") {
        const scheduledAt = String(response.error.scheduled_permanent_deletion_at ?? "");
        setPendingDeletion({
          scheduledAt,
          email: payload.email,
          password: payload.password,
        });
        const dateText = Number.isNaN(new Date(scheduledAt).getTime())
          ? scheduledAt
          : new Date(scheduledAt).toLocaleString();
        setMessage(`Your account is scheduled for deletion on ${dateText}. You can recover it within the 15-day grace period.`);
        setLoading(false);
        return;
      }
      if ("error" in response && response.error?.code === "ACCOUNT_PERMANENTLY_DELETED") {
        setMessage("This account has been permanently deleted.");
        setLoading(false);
        return;
      }

      if ("error" in response && response.error) {
        setMessage(response.error.message || "Login failed.");
        setLoading(false);
        return;
      }

      if ("success" in response && response.success === false) {
        setMessage(("message" in response && response.message) || "Invalid credentials provided.");
        setLoading(false);
        return;
      }

      if (!("data" in response)) {
        setMessage("Login failed.");
        setLoading(false);
        return;
      }

      saveSession(response.data.token);
      const profile = await fetchSessionProfile();
      syncTenantFromProfile(profile);
      const onboardingDone = isOnboardingComplete(profile.current_tenant?.id ?? null);
      router.push(getRoleAwareRoute(profile, onboardingDone));
    } catch (error) {
      clearSession();
      const errorMessage =
        error instanceof Error ? error.message : "Login failed.";
      setMessage(errorMessage);
    } finally {
      setLoading(false);
    }
  }

  return (
    <Box
      sx={{
        minHeight: "100dvh",
        display: "flex",
        alignItems: { xs: "flex-start", sm: "center" },
        justifyContent: "center",
        bgcolor: "#f5f5f9",
        py: { xs: 2, sm: 5 },
        px: { xs: 2, sm: 0 },
        overflowY: "auto",
      }}
    >
      <Box sx={{ width: "100%", maxWidth: 460, my: { xs: 2, sm: 0 } }}>
        <Box
          sx={{
            bgcolor: "#fff",
            borderRadius: "0.375rem",
            boxShadow: "0 2px 6px rgba(67, 89, 113, 0.12)",
            p: { xs: 3, sm: 5 },
          }}
        >
          {/* Logo */}
          <Box
            sx={{
              display: "flex",
              justifyContent: "center",
              mb: 4,
              gap: 1.5,
              alignItems: "center",
            }}
          >
            <svg
              width="25"
              viewBox="0 0 25 42"
              xmlns="http://www.w3.org/2000/svg"
              xmlnsXlink="http://www.w3.org/1999/xlink"
            >
              <defs>
                <path
                  d="M13.7918663,0.358365126 L3.39788168,7.44174259 C0.566865006,9.69408886 -0.379795268,12.4788597 0.557900856,15.7960551 C0.68998853,16.2305145 1.09562888,17.7872135 3.12357076,19.2293357 C3.8146334,19.7207684 5.32369333,20.3834223 7.65075054,21.2172976 L7.59773219,21.2525164 L2.63468769,24.5493413 C0.445452254,26.3002124 0.0884951797,28.5083815 1.56381646,31.1738486 C2.83770406,32.8170431 5.20850219,33.2640127 7.09180128,32.5391577 C8.347334,32.0559211 11.4559176,30.0011079 16.4175519,26.3747182 C18.0338572,24.4997857 18.6973423,22.4544883 18.4080071,20.2388261 C17.963753,17.5346866 16.1776345,15.5799961 13.0496516,14.3747546 L10.9194936,13.4715819 L18.6192054,7.984237 L13.7918663,0.358365126 Z"
                  id="login-path-1"
                />
              </defs>
              <g stroke="none" strokeWidth="1" fill="none" fillRule="evenodd">
                <g transform="translate(-27.000000, -15.000000)">
                  <g transform="translate(27.000000, 15.000000)">
                    <g transform="translate(0.000000, 8.000000)">
                      <use fill="#696cff" xlinkHref="#login-path-1" />
                    </g>
                  </g>
                </g>
              </g>
            </svg>
            <Typography
              sx={{
                fontWeight: 700,
                fontSize: "1.375rem",
                letterSpacing: -0.5,
                textTransform: "lowercase",
                color: "#566a7f",
              }}
            >
              WND Dialer
            </Typography>
          </Box>

          <Typography
            variant="h4"
            sx={{ mb: 0.5, fontWeight: 500, color: "#566a7f" }}
          >
            Welcome! 👋
          </Typography>
          <Typography
            variant="body2"
            sx={{ mb: 4, color: "#697a8d" }}
          >
            Please sign-in to your account and start the adventure
          </Typography>

          {pendingDeletion ? (
            <Box
              sx={{
                mb: 3,
                p: 2,
                borderRadius: "0.375rem",
                bgcolor: "#fff5f2",
                border: "1px solid #ff3e1d",
              }}
            >
              <Typography variant="subtitle2" sx={{ color: "#ff3e1d", mb: 0.5 }}>
                Account pending deletion
              </Typography>
              <Typography variant="body2" sx={{ color: "#566a7f", mb: 1.5 }}>
                Your account is scheduled for deletion on{" "}
                {Number.isNaN(new Date(pendingDeletion.scheduledAt).getTime())
                  ? pendingDeletion.scheduledAt
                  : new Date(pendingDeletion.scheduledAt).toLocaleString()}
                . Want to recover it?
              </Typography>
              <Button
                onClick={() => void onRecover()}
                disabled={recovering || loading}
                fullWidth
              >
                {recovering ? "Recovering..." : "Recover Account"}
              </Button>
            </Box>
          ) : null}

          <Box component="form" onSubmit={onSubmit}>
            <Box sx={{ mb: 3 }}>
              <Typography
                component="label"
                htmlFor="email"
                sx={{
                  display: "block",
                  mb: 0.5,
                  fontSize: "0.8125rem",
                  fontWeight: 400,
                  color: "#566a7f",
                }}
              >
                Email Address
              </Typography>
              <FormTextField
                id="email"
                type="email"
                name="email"
                placeholder="Enter your email address"
                required
                autoFocus
                inputProps={{ maxLength: 100 }}
              />
            </Box>

            <Box sx={{ mb: 3 }}>
              <Box
                sx={{
                  display: "flex",
                  justifyContent: "space-between",
                  mb: 0.5,
                  flexWrap: "wrap",
                  rowGap: 0.5,
                }}
              >
                <Typography
                  component="label"
                  htmlFor="password"
                  sx={{
                    fontSize: "0.8125rem",
                    fontWeight: 400,
                    color: "#566a7f",
                  }}
                >
                  Password
                </Typography>
                <MuiLink
                  component={Link}
                  href="/forgot-password"
                  underline="none"
                  sx={{
                    fontSize: "0.8125rem",
                    color: "#696cff",
                  }}
                >
                  Forgot Password?
                </MuiLink>
              </Box>
              <FormTextField
                id="password"
                type="password"
                name="password"
                placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                required
                inputProps={{ maxLength: 64 }}
              />
            </Box>

            <Button type="submit" disabled={loading} fullWidth>
              {loading ? "Signing in..." : "Sign in"}
            </Button>
          </Box>

          <Typography
            variant="body2"
            sx={{
              textAlign: "center",
              mt: 3,
              color: "#697a8d",
            }}
          >
            New on our platform?{" "}
            <MuiLink
              component={Link}
              href="/register"
              underline="none"
              sx={{ color: "#696cff" }}
            >
              Create an account
            </MuiLink>
          </Typography>

          {message ? (
            <Typography
              variant="body2"
              sx={{
                mt: 2,
                p: 1.5,
                borderRadius: "0.375rem",
                bgcolor: "#ffe7e3",
                color: "#ff3e1d",
                fontSize: "0.8125rem",
              }}
            >
              {message}
            </Typography>
          ) : null}
        </Box>
      </Box>
    </Box>
  );
}
