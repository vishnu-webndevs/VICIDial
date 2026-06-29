"use client";

import { useEffect, useState, useMemo } from "react";
import { useSearchParams } from "next/navigation";
import { Box, Button as MuiButton, Card, CardContent, Divider, Paper, Stack, Typography, CircularProgress } from "@mui/material";
import { AppShell } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

type Plan = {
  id: string;
  slug: string;
  name: string;
  description?: string | null;
  price_monthly?: number | null;
  price_yearly?: number | null;
  monthly_price_cents?: number | null;
  yearly_price_cents?: number | null;
};

export default function PaymentPage() {
  const searchParams = useSearchParams();
  const planSlug = searchParams.get("plan");
  const cycle = searchParams.get("cycle") || "monthly";

  const [plans, setPlans] = useState<Plan[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    async function loadPlans() {
      try {
        const { token, tenantId } = getTenantContext();
        const response = await apiRequest<{ data: Plan[] }>("/plans", { token, tenantId });
        setPlans(response.data);
      } catch (err) {
        setError("Failed to load plan details.");
      } finally {
        setLoading(false);
      }
    }
    void loadPlans();
  }, []);

  const selectedPlan = useMemo(() => {
    return plans.find((p) => p.slug === planSlug) || null;
  }, [plans, planSlug]);

  const priceString = useMemo(() => {
    if (!selectedPlan) return "";
    const raw = cycle === "monthly"
      ? (selectedPlan.price_monthly ?? (selectedPlan.monthly_price_cents != null ? selectedPlan.monthly_price_cents / 100 : null))
      : (selectedPlan.price_yearly ?? (selectedPlan.yearly_price_cents != null ? selectedPlan.yearly_price_cents / 100 : null));

    if (raw == null || Number(raw) <= 0) return "Custom";

    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: "USD",
      maximumFractionDigits: 0,
    }).format(Number(raw)) + (cycle === "monthly" ? " / month" : " / year");
  }, [selectedPlan, cycle]);

  return (
    <AppShell>
      <Box
        sx={{
          display: "flex",
          justifyContent: "center",
          alignItems: "center",
          minHeight: "70vh",
          p: 2,
        }}
      >
        {loading ? (
          <CircularProgress color="primary" />
        ) : (
          <Paper
            variant="outlined"
            sx={{
              width: "100%",
              maxWidth: 580,
              p: { xs: 3, md: 4 },
              borderRadius: 4,
              boxShadow: "0 8px 32px 0 rgba(148, 163, 184, 0.08)",
              border: "1px solid rgba(226, 232, 240, 0.8)",
              background: "rgba(255, 255, 255, 0.9)",
              backdropFilter: "blur(12px)",
            }}
          >
            {/* Checkout Header */}
            <Typography variant="h5" sx={{ fontWeight: 700, mb: 1, color: "text.primary" }}>
              Secure Checkout
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Upgrade your workspace subscription and unlock premium features.
            </Typography>

            {/* Selected Plan Summary Card */}
            {selectedPlan ? (
              <Card
                variant="outlined"
                sx={{
                  mb: 4,
                  borderRadius: 3,
                  background: "linear-gradient(135deg, rgba(79, 70, 229, 0.03) 0%, rgba(124, 58, 237, 0.03) 100%)",
                  borderColor: "rgba(79, 70, 229, 0.12)",
                }}
              >
                <CardContent sx={{ p: 2.5, "&:last-child": { pb: 2.5 } }}>
                  <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Box>
                      <Typography variant="subtitle2" color="primary.main" sx={{ fontWeight: 700, textTransform: "uppercase", tracking: 1, fontSize: "0.75rem" }}>
                        Selected Plan
                      </Typography>
                      <Typography variant="h6" sx={{ fontWeight: 800, mt: 0.5 }}>
                        {selectedPlan.name}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        Billing Cycle: {cycle === "monthly" ? "Monthly" : "Yearly"}
                      </Typography>
                    </Box>
                    <Box sx={{ textAlign: "right" }}>
                      <Typography variant="h6" sx={{ fontWeight: 800, color: "text.primary" }}>
                        {priceString}
                      </Typography>
                    </Box>
                  </Stack>
                </CardContent>
              </Card>
            ) : null}

            {/* Work in Progress Banner */}
            <Box
              sx={{
                p: 3,
                borderRadius: 3,
                bgcolor: "warning.lighter",
                border: "1px dashed",
                borderColor: "warning.main",
                display: "flex",
                flexDirection: "column",
                alignItems: "center",
                textAlign: "center",
                mb: 4,
              }}
            >
              {/* Construction Icon */}
              <Box
                sx={{
                  width: 48,
                  height: 48,
                  borderRadius: "50%",
                  bgcolor: "warning.main",
                  display: "flex",
                  justifyContent: "center",
                  alignItems: "center",
                  mb: 2,
                  color: "#fff",
                  boxShadow: "0 4px 12px 0 rgba(234, 179, 8, 0.2)",
                  animation: "pulse 2s infinite ease-in-out",
                  "@keyframes pulse": {
                    "0%": { transform: "scale(0.95)" },
                    "50%": { transform: "scale(1.05)" },
                    "100%": { transform: "scale(0.95)" },
                  },
                }}
              >
                <svg
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.5"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <rect x="2" y="3" width="20" height="14" rx="2" ry="2" />
                  <line x1="8" y1="21" x2="16" y2="21" />
                  <line x1="12" y1="17" x2="12" y2="21" />
                </svg>
              </Box>

              <Typography variant="subtitle1" sx={{ fontWeight: 700, color: "warning.darker", mb: 1 }}>
                Payment Gateway Maintenance
              </Typography>
              <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 440 }}>
                Our online checkout system is currently undergoing upgrades. Plan changes require manual processing during this time.
              </Typography>
            </Box>

            <Divider sx={{ mb: 3 }} />

            {/* Action Buttons */}
            <Stack direction={{ xs: "column", sm: "row" }} spacing={2} justifyContent="flex-end">
              <MuiButton
                variant="outlined"
                onClick={() => {
                  window.location.href = "/billing";
                }}
                sx={{
                  borderRadius: 2,
                  px: 3,
                  py: 1,
                  textTransform: "none",
                  fontWeight: 600,
                }}
              >
                Back to Billing
              </MuiButton>
              <MuiButton
                variant="contained"
                onClick={() => {
                  window.location.href = "/help";
                }}
                sx={{
                  borderRadius: 2,
                  px: 4,
                  py: 1,
                  textTransform: "none",
                  fontWeight: 600,
                  boxShadow: "0 4px 12px 0 rgba(79, 70, 229, 0.2)",
                }}
              >
                Contact Us
              </MuiButton>
            </Stack>
          </Paper>
        )}
      </Box>
    </AppShell>
  );
}
