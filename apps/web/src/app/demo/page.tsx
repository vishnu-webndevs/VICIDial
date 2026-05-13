"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { Box, Button, Card, DataTable, MuiButton, Paper, Snackbar, Stack, Typography } from "@/ui";

type DemoLead = {
  id: string;
  full_name: string;
  company: string;
  status: "new" | "contacted" | "qualified" | "won" | "lost";
};

type DemoCampaign = {
  id: string;
  name: string;
  status: "running" | "draft" | "paused";
  queue: number;
  conversion: number;
};

type DemoDataset = {
  leads: DemoLead[];
  campaigns: DemoCampaign[];
  analytics: {
    callsToday: number;
    connectRate: number;
    avgHandleTimeSec: number;
    pipelineValue: string;
  };
};

const initialDemoDataset: DemoDataset = {
  leads: [
    { id: "L-101", full_name: "Emma Carter", company: "Northline Media", status: "qualified" },
    { id: "L-102", full_name: "Liam Ortiz", company: "Brightpath Logistics", status: "contacted" },
    { id: "L-103", full_name: "Noah Bennett", company: "Bluepeak Labs", status: "new" },
    { id: "L-104", full_name: "Sophia Hayes", company: "Everfield Agency", status: "won" },
    { id: "L-105", full_name: "Mason Price", company: "Aurora Retail Group", status: "lost" },
  ],
  campaigns: [
    { id: "C-201", name: "Q2 Renewals", status: "running", queue: 42, conversion: 31 },
    { id: "C-202", name: "SMB Outbound", status: "draft", queue: 28, conversion: 24 },
    { id: "C-203", name: "Churn Recovery", status: "paused", queue: 13, conversion: 18 },
  ],
  analytics: {
    callsToday: 386,
    connectRate: 42,
    avgHandleTimeSec: 214,
    pipelineValue: "$128,400",
  },
};

export default function DemoPage() {
  const [dataset, setDataset] = useState<DemoDataset>(initialDemoDataset);
  const [lastResetAt, setLastResetAt] = useState<string>("");
  const [snackbarOpen, setSnackbarOpen] = useState(false);

  const wonLeads = useMemo(
    () => dataset.leads.filter((lead) => lead.status === "won").length,
    [dataset.leads]
  );

  function resetDemo() {
    setDataset(initialDemoDataset);
    setLastResetAt(new Date().toLocaleTimeString());
    setSnackbarOpen(true);
  }

  return (
    <Box component="main" sx={{ mx: "auto", width: "100%", maxWidth: 1200, px: 3, py: 5 }}>
      <Card title="Explore WND Dialer Without Setup" subtitle="Demo Mode">
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1, maxWidth: 760 }}>
          This workspace is preloaded with realistic leads, active campaigns, and analytics so buyers can understand
          value in under 5 minutes.
        </Typography>
        <Stack direction="row" flexWrap="wrap" gap={1} sx={{ mt: 2 }}>
          <Button onClick={resetDemo}>Reset Demo</Button>
          <MuiButton component={Link} href="/register" variant="outlined" size="medium">
            Start Free Trial
          </MuiButton>
          <MuiButton component={Link} href="/onboarding" variant="outlined" size="medium">
            View Guided Onboarding
          </MuiButton>
        </Stack>
        {lastResetAt ? (
          <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: "block" }}>
            Demo reset at {lastResetAt}
          </Typography>
        ) : null}
      </Card>

      <Box
        component="section"
        sx={{ mt: 3, display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)", xl: "repeat(5, 1fr)" } }}
      >
        <Card title="Calls Today">{dataset.analytics.callsToday}</Card>
        <Card title="Connect Rate">{dataset.analytics.connectRate}%</Card>
        <Card title="Avg Handle Time">{dataset.analytics.avgHandleTimeSec}s</Card>
        <Card title="Pipeline Value">{dataset.analytics.pipelineValue}</Card>
        <Card title="Won Leads">{wonLeads}</Card>
      </Box>

      <Box component="section" sx={{ mt: 3, display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "repeat(2, 1fr)" } }}>
        <Card title="Sample Leads" subtitle="Represents a realistic mixed pipeline with lifecycle statuses.">
          <DataTable
            rows={dataset.leads}
            rowKey={(row) => row.id}
            columns={[
              { key: "full_name", label: "Lead" },
              { key: "company", label: "Company" },
              {
                key: "status",
                label: "Status",
                render: (value) => String(value).replace("_", " "),
              },
            ]}
          />
        </Card>

        <Card title="Sample Campaigns" subtitle="Live-like queue and conversion stats for demo storytelling.">
          <Stack spacing={1}>
            {dataset.campaigns.map((campaign) => (
              <Paper key={campaign.id} variant="outlined" sx={{ p: 1.5 }}>
                <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{campaign.name}</Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ textTransform: "capitalize" }}>
                    {campaign.status}
                  </Typography>
                </Box>
                <Typography variant="body2" color="text.secondary">Queue: {campaign.queue}</Typography>
                <Typography variant="body2" color="text.secondary">Conversion: {campaign.conversion}%</Typography>
              </Paper>
            ))}
          </Stack>
        </Card>
      </Box>
      <Snackbar
        open={snackbarOpen}
        onClose={() => setSnackbarOpen(false)}
        severity="success"
        message="Demo dataset reset"
      />
    </Box>
  );
}
