"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Box, Paper, Stack, Typography } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";
import { KpiCard, ToastMessage } from "@/components/ui-primitives";
import { PlanUsageCard } from "@/components/plans/PlanUsageCard";
import { fetchDashboardSummary } from "@/lib/product-api";

export default function DashboardPage() {
  const [callsToday, setCallsToday] = useState(0);
  const [activeCampaigns, setActiveCampaigns] = useState(0);
  const [agentsOnline, setAgentsOnline] = useState(0);
  const [conversionRate, setConversionRate] = useState(0);
  const [hourlyCalls, setHourlyCalls] = useState<Array<{ hour: number; calls: number }>>([]);
  const [message, setMessage] = useState("");

  const loadData = useCallback(async () => {
    try {
      const summary = await fetchDashboardSummary();
      setCallsToday(Number(summary.calls_today ?? 0));
      setActiveCampaigns(Number(summary.active_campaigns ?? 0));
      setAgentsOnline(Number(summary.agents_online ?? 0));
      setConversionRate(Number(summary.conversion_rate ?? 0));
      setHourlyCalls(summary.calls_per_hour ?? []);
      setMessage("");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load dashboard.");
    }
  }, []);

  useEffect(() => {
    const initialTimer = window.setTimeout(() => {
      void loadData();
    }, 0);
    const timer = setInterval(() => {
      void loadData();
    }, 30000);
    return () => {
      clearTimeout(initialTimer);
      clearInterval(timer);
    };
  }, [loadData]);

  const chartMax = useMemo(
    () => Math.max(...hourlyCalls.map((entry) => Number(entry.calls ?? 0)), 1),
    [hourlyCalls]
  );

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      {message ? <ToastMessage tone="error" message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2 }}>
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(4, 1fr)" } }}>
          <KpiCard label="Calls Today" value={callsToday} />
          <KpiCard label="Active Campaigns" value={activeCampaigns} />
          <KpiCard label="Agents Online" value={agentsOnline} />
          <KpiCard label="Conversion Rate" value={`${conversionRate}%`} />
        </Box>

        <SectionCard title="Hourly Calls" subtitle="Today by hour (24h format).">
          <Paper variant="outlined" sx={{ p: 1.5, overflowX: "auto" }}>
            <Stack direction="row" spacing={1} sx={{ minWidth: 840, alignItems: "flex-end" }}>
              {hourlyCalls.map((entry, index) => (
                <Box key={`${entry.hour}-${index}`} sx={{ width: 28, textAlign: "center" }}>
                  <Box
                    sx={{
                      height: `${Math.max(((entry.calls ?? 0) / chartMax) * 180, (entry.calls ?? 0) > 0 ? 6 : 2)}px`,
                      bgcolor: "primary.main",
                      borderRadius: 1,
                    }}
                    title={`${entry.hour.toString().padStart(2, "0")}:00 - ${entry.calls ?? 0} calls`}
                  />
                  <Typography variant="caption" color="text.secondary">
                    {entry.hour.toString().padStart(2, "0")}
                  </Typography>
                </Box>
              ))}
            </Stack>
          </Paper>
        </SectionCard>
        <PlanUsageCard featureKeys={["max_agents", "max_campaigns", "can_use_api"]} />
      </Box>
    </AppShell>
  );
}
