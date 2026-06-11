"use client";

import { useEffect, useMemo, useState } from "react";
import { Alert, Box, Button as MuiButton, MenuItem, Paper, TextField, Typography } from "@mui/material";
import { AppShell, SectionCard } from "@/components/app-shell";
import { PlanUsageCard } from "@/components/plans/PlanUsageCard";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";
import { clearSessionCache } from "@/lib/session-cache";

type Plan = {
  id: string;
  slug: string;
  name: string;
  description?: string | null;
  sort_order?: number;
  is_active?: boolean;
  is_public?: boolean;
  price_monthly?: number | null;
  price_yearly?: number | null;
  monthly_price_cents?: number | null;
  yearly_price_cents?: number | null;
};

type Subscription = {
  id: string;
  status: string;
  billing_cycle: "monthly" | "yearly";
  plan: Plan;
  trial_ends_at?: string | null;
};

function formatPlanPrice(plan: Plan, cycle: "monthly" | "yearly"): string {
  const raw = cycle === "monthly"
    ? (plan.price_monthly ?? (plan.monthly_price_cents != null ? plan.monthly_price_cents / 100 : null))
    : (plan.price_yearly ?? (plan.yearly_price_cents != null ? plan.yearly_price_cents / 100 : null));

  if (raw == null || Number(raw) <= 0) {
    return "Custom";
  }

  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 0,
  }).format(Number(raw));
}

export default function BillingPage() {
  const [plans, setPlans] = useState<Plan[]>([]);
  const [subscription, setSubscription] = useState<Subscription | null>(null);
  const [selectedPlanSlug, setSelectedPlanSlug] = useState<string>("");
  const [billingCycle, setBillingCycle] = useState<"monthly" | "yearly">("monthly");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string>("");

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const { token, tenantId } = getTenantContext();
        const [plansResponse, subscriptionResponse] = await Promise.all([
          apiRequest<{ data: Plan[] }>("/plans", { token, tenantId }),
          apiRequest<{ data: Subscription }>("/subscription", { token, tenantId }),
        ]);

        if (cancelled) return;

        const nextPlans = [...plansResponse.data].sort(
          (a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0)
        );

        setPlans(nextPlans);
        setSubscription(subscriptionResponse.data);
        setSelectedPlanSlug(subscriptionResponse.data.plan.slug);
        setBillingCycle(subscriptionResponse.data.billing_cycle);
      } catch (error) {
        if (!cancelled) {
          setMessage(error instanceof Error ? error.message : "Failed to load billing settings.");
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void load();

    return () => {
      cancelled = true;
    };
  }, []);

  const selectedPlan = useMemo(
    () => plans.find((plan) => plan.slug === selectedPlanSlug) ?? null,
    [plans, selectedPlanSlug]
  );

  const isTrialExpired = useMemo(() => {
    if (!subscription) return false;
    if (subscription.status === "trialing" && subscription.trial_ends_at) {
      return new Date(subscription.trial_ends_at) < new Date();
    }
    return ["canceled", "unpaid", "past_due"].includes(subscription.status);
  }, [subscription]);

  async function onSaveSubscription() {
    if (!selectedPlanSlug) return;

    setSaving(true);
    setMessage("");

    try {
      const { token, tenantId } = getTenantContext();
      const response = await apiRequest<{ data: Subscription }>("/subscription", {
        method: "PUT",
        token,
        tenantId,
        body: {
          plan_slug: selectedPlanSlug,
          billing_cycle: billingCycle,
        },
      });

      setSubscription(response.data);
      setSelectedPlanSlug(response.data.plan.slug);
      setBillingCycle(response.data.billing_cycle);
      setMessage("Plan updated successfully. Redirecting...");
      clearSessionCache();
      setTimeout(() => {
        window.location.href = "/dashboard";
      }, 1000);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to update plan.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <AppShell>
      <Box sx={{ display: "grid", gap: 2 }}>
        {isTrialExpired && (
          <Alert severity="error">
            Your plan has expired. Please select a plan and billing cycle below to reactivate your account.
          </Alert>
        )}
        <SectionCard
          title="Plan"
          subtitle="Token-based billing is disabled. Choose the plan and billing cycle that apply to your company."
        >
        <Paper variant="outlined" sx={{ p: 3 }}>
          <Box sx={{ display: "flex", justifyContent: "flex-end", mb: 2 }}>
            <MuiButton
              variant="contained"
              onClick={onSaveSubscription}
              disabled={saving || loading || !selectedPlanSlug}
            >
              {saving ? "Saving..." : "Save plan"}
            </MuiButton>
          </Box>

          <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" } }}>
            <TextField
              select
              label="Plan"
              value={selectedPlanSlug}
              onChange={(event) => setSelectedPlanSlug(event.target.value)}
              fullWidth
              disabled={loading || plans.length === 0}
            >
              {plans.map((plan) => (
                <MenuItem key={plan.id} value={plan.slug}>
                  {plan.name}
                </MenuItem>
              ))}
            </TextField>

            <TextField
              select
              label="Billing cycle"
              value={billingCycle}
              onChange={(event) => setBillingCycle(event.target.value as "monthly" | "yearly")}
              fullWidth
              disabled={loading}
            >
              <MenuItem value="monthly">Monthly</MenuItem>
              <MenuItem value="yearly">Yearly</MenuItem>
            </TextField>
          </Box>

          {selectedPlan && (
            <Box sx={{ mt: 2 }}>
              <Typography variant="body2" color="text.secondary">
                {selectedPlan.description || "No plan description provided."}
              </Typography>
              <Typography variant="h6" sx={{ mt: 1 }}>
                {formatPlanPrice(selectedPlan, billingCycle)} / {billingCycle === "monthly" ? "month" : "year"}
              </Typography>
            </Box>
          )}

          {subscription && (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
              Current subscription: {subscription.plan.name} ({subscription.billing_cycle}, {subscription.status})
            </Typography>
          )}

          {message && (
            <Typography variant="body2" color="primary" sx={{ mt: 2 }}>
              {message}
            </Typography>
          )}
        </Paper>
      </SectionCard>

      <SectionCard
        title="Usage"
        subtitle="Live usage limits are enforced by your plan configuration."
      >
        <PlanUsageCard featureKeys={["max_agents", "max_campaigns", "can_use_api"]} />
      </SectionCard>
      </Box>
    </AppShell>
  );
}
