"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiRequest } from "@/lib/api";
import type { RegisterResponse } from "@/types/auth";
import { resetOnboarding } from "@/lib/onboarding";
import { saveSession } from "@/lib/auth-session";
import {
  Box,
  Button,
  FormTextField,
  Link as MuiLink,
  Typography,
} from "@/ui";

export default function RegisterPage() {
  const router = useRouter();
  const [message, setMessage] = useState<string>("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setMessage("");

    const formData = new FormData(event.currentTarget);
    const payload = {
      company_name: String(formData.get("company_name") ?? ""),
      first_name: String(formData.get("first_name") ?? ""),
      last_name: String(formData.get("last_name") ?? ""),
      email: String(formData.get("email") ?? ""),
      password: String(formData.get("password") ?? ""),
      password_confirmation: String(
        formData.get("password_confirmation") ?? ""
      ),
      timezone: "UTC",
    };

    try {
      const response = await apiRequest<RegisterResponse>("/auth/register", {
        method: "POST",
        body: payload,
      });
      saveSession(response.data.token, response.data.tenant.id);
      resetOnboarding(response.data.tenant.id);
      router.push("/onboarding");
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : "Registration failed.";
      setMessage(errorMessage);
    } finally {
      setLoading(false);
    }
  }

  return (
    <Box
      sx={{
        minHeight: "100vh",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        bgcolor: "#f5f5f9",
        py: 5,
      }}
    >
      <Box sx={{ width: "100%", maxWidth: 460 }}>
        <Box
          sx={{
            bgcolor: "#fff",
            borderRadius: "0.375rem",
            boxShadow: "0 2px 6px rgba(67, 89, 113, 0.12)",
            p: { xs: 4, sm: 5 },
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
                  id="reg-path-1"
                />
              </defs>
              <g stroke="none" strokeWidth="1" fill="none" fillRule="evenodd">
                <g transform="translate(-27.000000, -15.000000)">
                  <g transform="translate(27.000000, 15.000000)">
                    <g transform="translate(0.000000, 8.000000)">
                      <use fill="#696cff" xlinkHref="#reg-path-1" />
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
            Adventure starts here 🚀
          </Typography>
          <Typography variant="body2" sx={{ mb: 4, color: "#697a8d" }}>
            Create your owner account and workspace
          </Typography>

          <Box component="form" onSubmit={onSubmit}>
            <Box sx={{ mb: 3 }}>
              <Typography
                component="label"
                htmlFor="company_name"
                sx={{
                  display: "block",
                  mb: 0.5,
                  fontSize: "0.8125rem",
                  color: "#566a7f",
                }}
              >
                Company Name
              </Typography>
              <FormTextField
                id="company_name"
                name="company_name"
                placeholder="Company Name"
                required
              />
            </Box>
            <Box sx={{ display: "flex", gap: 2, mb: 3 }}>
              <Box sx={{ flex: 1 }}>
                <Typography
                  component="label"
                  htmlFor="first_name"
                  sx={{
                    display: "block",
                    mb: 0.5,
                    fontSize: "0.8125rem",
                    color: "#566a7f",
                  }}
                >
                  First Name
                </Typography>
                <FormTextField
                  id="first_name"
                  name="first_name"
                  placeholder="First Name"
                  required
                />
              </Box>
              <Box sx={{ flex: 1 }}>
                <Typography
                  component="label"
                  htmlFor="last_name"
                  sx={{
                    display: "block",
                    mb: 0.5,
                    fontSize: "0.8125rem",
                    color: "#566a7f",
                  }}
                >
                  Last Name
                </Typography>
                <FormTextField
                  id="last_name"
                  name="last_name"
                  placeholder="Last Name"
                  required
                />
              </Box>
            </Box>
            <Box sx={{ mb: 3 }}>
              <Typography
                component="label"
                htmlFor="email"
                sx={{
                  display: "block",
                  mb: 0.5,
                  fontSize: "0.8125rem",
                  color: "#566a7f",
                }}
              >
                Email
              </Typography>
              <FormTextField
                id="email"
                type="email"
                name="email"
                placeholder="Enter your email"
                required
              />
            </Box>
            <Box sx={{ mb: 3 }}>
              <Typography
                component="label"
                htmlFor="password"
                sx={{
                  display: "block",
                  mb: 0.5,
                  fontSize: "0.8125rem",
                  color: "#566a7f",
                }}
              >
                Password
              </Typography>
              <FormTextField
                id="password"
                type="password"
                name="password"
                placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                required
              />
            </Box>
            <Box sx={{ mb: 3 }}>
              <Typography
                component="label"
                htmlFor="password_confirmation"
                sx={{
                  display: "block",
                  mb: 0.5,
                  fontSize: "0.8125rem",
                  color: "#566a7f",
                }}
              >
                Confirm Password
              </Typography>
              <FormTextField
                id="password_confirmation"
                type="password"
                name="password_confirmation"
                placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                required
              />
            </Box>
            <Button type="submit" disabled={loading} fullWidth>
              {loading ? "Submitting..." : "Sign up"}
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
            Already have an account?{" "}
            <MuiLink
              component={Link}
              href="/login"
              underline="none"
              sx={{ color: "#696cff" }}
            >
              Sign in instead
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
