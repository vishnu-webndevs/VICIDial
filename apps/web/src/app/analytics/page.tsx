"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";
import { EmptyPanel, KpiCard } from "@/components/ui-primitives";
import {
  fetchPlannedFeatureStatus,
  fetchAgentAnalytics,
  fetchCampaignAnalytics,
  fetchTrendAnalytics,
  listCampaigns,
} from "@/lib/product-api";
import type { PlannedFeatureStatus } from "@/lib/product-api";
import type { AgentAnalytics, Campaign, CampaignAnalytics, TrendPoint } from "@/types/product";

function defaultDateRange() {
  const formatLocalDate = (date: Date) => {
    const tzOffsetMs = date.getTimezoneOffset() * 60 * 1000;
    return new Date(date.getTime() - tzOffsetMs).toISOString().slice(0, 10);
  };

  const now = new Date();
  const to = formatLocalDate(now);
  const fromDate = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
  const from = formatLocalDate(fromDate);
  return { from, to };
}

export default function AnalyticsPage() {
  const range = defaultDateRange();
  const [from, setFrom] = useState(range.from);
  const [to, setTo] = useState(range.to);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [campaignId, setCampaignId] = useState("");
  const [agentId, setAgentId] = useState("");
  const [trendGroupBy, setTrendGroupBy] = useState<"day" | "hour">("day");
  const [campaignStats, setCampaignStats] = useState<CampaignAnalytics[]>([]);
  const [agentStats, setAgentStats] = useState<AgentAnalytics[]>([]);
  const [trendStats, setTrendStats] = useState<TrendPoint[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [drillCampaign, setDrillCampaign] = useState("");
  const [plannedFeatures, setPlannedFeatures] = useState<PlannedFeatureStatus[]>([]);

  const load = useCallback(async () => {
    setLoading(true);
    setMessage("");
    try {
      const [campaignData, agentData, trendData] = await Promise.all([
        fetchCampaignAnalytics({ from, to, campaign_id: campaignId || undefined }),
        fetchAgentAnalytics({ from, to, agent_id: agentId || undefined }),
        fetchTrendAnalytics({ from, to, group_by: trendGroupBy }),
      ]);
      setCampaignStats(campaignData);
      setAgentStats(agentData);
      setTrendStats(trendData);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load analytics.");
    } finally {
      setLoading(false);
    }
  }, [from, to, campaignId, agentId, trendGroupBy]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    void (async () => {
      try {
        const [campaignData, featureData] = await Promise.all([
          listCampaigns(),
          fetchPlannedFeatureStatus(),
        ]);
        setCampaigns(campaignData);
        setPlannedFeatures(featureData.features ?? []);
      } catch {
        // Ignore campaign list loading failures for analytics view.
      }
    })();
  }, []);

  function onApply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void load();
  }

  const summary = useMemo(() => {
    const totals = campaignStats.reduce(
      (acc, row) => {
        acc.totalCalls += row.total_calls;
        acc.connected += row.connected_calls;
        acc.unsuccessful += row.unsuccessful_calls;
        acc.duration += row.total_duration_seconds;
        return acc;
      },
      { totalCalls: 0, connected: 0, unsuccessful: 0, duration: 0 }
    );
    const successRate =
      totals.totalCalls > 0 ? Number(((totals.connected / totals.totalCalls) * 100).toFixed(2)) : 0;
    return {
      totalCalls: totals.totalCalls,
      successRate,
      failureRate: totals.totalCalls > 0 ? Number((100 - successRate).toFixed(2)) : 0,
      avgDuration: totals.totalCalls > 0 ? Number((totals.duration / totals.totalCalls).toFixed(2)) : 0,
    };
  }, [campaignStats]);

  const topCampaignBars = useMemo(
    () =>
      campaignStats.map((item) => ({
        label: item.campaign_id ? item.campaign_id.slice(0, 8) : "Unmapped",
        success: item.connected_calls,
        failed: item.unsuccessful_calls,
      })),
    [campaignStats]
  );

  const agentBars = useMemo(
    () =>
      agentStats.map((item) => ({
        label: item.agent_id.slice(0, 8),
        success: item.connected_calls,
        failed: Math.max(item.total_calls - item.connected_calls, 0),
      })),
    [agentStats]
  );

  const comparison = useMemo(() => {
    const points = trendStats.slice(-14);
    if (points.length < 2) return { delta: 0 };
    const half = Math.floor(points.length / 2);
    const previous = points.slice(0, half).reduce((sum, item) => sum + item.total_calls, 0);
    const current = points.slice(half).reduce((sum, item) => sum + item.total_calls, 0);
    const delta = previous === 0 ? 0 : Number((((current - previous) / previous) * 100).toFixed(2));
    return { delta };
  }, [trendStats]);

  const drillData = campaignStats.find((item) => (item.campaign_id ?? "none") === drillCampaign) ?? null;

  return (
    <AppShell requiredPermissions={["analytics.view"]}>
      <SectionCard
        title="Analytics Filters"
        subtitle="Call metrics, agent performance, and campaign performance."
      >
        <Box component="form" sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(4, 1fr)" } }} onSubmit={onApply}>
          <TextField
            type="date"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
            size="medium"
          />
          <TextField
            type="date"
            value={to}
            onChange={(event) => setTo(event.target.value)}
            size="medium"
          />
          <MuiButton type="submit" variant="contained">
            Apply
          </MuiButton>
          <TextField
            select
            value={campaignId}
            onChange={(event) => setCampaignId(event.target.value)}
            size="medium"
          >
            <MenuItem value="">All campaigns</MenuItem>
            {campaigns.map((campaign) => (
              <MenuItem key={campaign.id} value={campaign.id}>
                {campaign.name}
              </MenuItem>
            ))}
          </TextField>
          <TextField
            value={agentId}
            onChange={(event) => setAgentId(event.target.value)}
            placeholder="Agent ID filter"
            size="medium"
          />
          <TextField
            select
            value={trendGroupBy}
            onChange={(event) => setTrendGroupBy(event.target.value as "day" | "hour")}
            size="medium"
          >
            <MenuItem value="day">Daily Trend</MenuItem>
            <MenuItem value="hour">Hourly Trend</MenuItem>
          </TextField>
          {loading ? <Typography variant="body2" color="text.secondary" sx={{ alignSelf: "center" }}>Loading...</Typography> : null}
        </Box>
        {message ? <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>{message}</Typography> : null}
      </SectionCard>

      <Box sx={{ mt: 2, display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)", xl: "repeat(5, 1fr)" } }}>
        <KpiCard label="Total Calls" value={summary.totalCalls} />
        <KpiCard label="Success Rate" value={`${summary.successRate}%`} />
        <KpiCard label="Failure Rate" value={`${summary.failureRate}%`} />
        <KpiCard label="Avg Duration" value={`${summary.avgDuration}s`} />
        <KpiCard
          label="Volume Delta"
          value={`${comparison.delta >= 0 ? "+" : ""}${comparison.delta}%`}
          hint="Current period vs previous period"
        />
      </Box>

      <Box sx={{ mt: 2, display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "repeat(2, 1fr)" } }}>
        <SectionCard title="Agent Leaderboard" subtitle="Success-rate ranking by agent">
          <GroupedBarChart items={agentBars} />
          <Table size="medium" sx={{ mt: 1.5 }}>
            <TableHead>
              <TableRow>
                <TableCell>Agent</TableCell>
                <TableCell>Calls</TableCell>
                <TableCell>Success</TableCell>
                <TableCell>Avg Duration</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {agentStats.map((item) => (
                <TableRow key={item.agent_id}>
                  <TableCell>{item.agent_id.slice(0, 8)}</TableCell>
                  <TableCell>{item.total_calls}</TableCell>
                  <TableCell>{item.success_rate}%</TableCell>
                  <TableCell>{item.avg_duration_seconds}s</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </SectionCard>

        <SectionCard title="Campaign Performance" subtitle="Outcome split and efficiency per campaign">
          <GroupedBarChart items={topCampaignBars} />
          <Table size="medium" sx={{ mt: 1.5 }}>
            <TableHead>
              <TableRow>
                <TableCell>Campaign</TableCell>
                <TableCell>Calls</TableCell>
                <TableCell>Success</TableCell>
                <TableCell>Failure</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {campaignStats.map((item) => (
                <TableRow key={`${item.campaign_id ?? "none"}`}>
                  <TableCell>{item.campaign_id ? item.campaign_id.slice(0, 8) : "Unmapped"}</TableCell>
                  <TableCell>{item.total_calls}</TableCell>
                  <TableCell>{item.success_rate}%</TableCell>
                  <TableCell>
                    {item.total_calls > 0
                      ? Number(((item.unsuccessful_calls / item.total_calls) * 100).toFixed(2))
                      : 0}
                    %
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </SectionCard>
      </Box>

      <Box sx={{ mt: 2, display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "repeat(2, 1fr)" } }}>
        <SectionCard title="Trend Line" subtitle="Time-based dial volume and connected outcomes.">
          <TrendLineChart
            items={trendStats.map((item) => ({
              label: item.bucket,
              primary: item.total_calls,
              secondary: item.connected_calls,
            }))}
          />
        </SectionCard>
        <SectionCard title="Outcome Breakdown" subtitle="Connected vs unsuccessful distribution.">
          <DonutChart
            slices={[
              { label: "Connected", value: campaignStats.reduce((sum, i) => sum + i.connected_calls, 0) },
              {
                label: "Unsuccessful",
                value: campaignStats.reduce((sum, i) => sum + i.unsuccessful_calls, 0),
              },
            ]}
          />
        </SectionCard>
      </Box>

      <SectionCard title="Drill-Down" subtitle="Inspect a campaign segment in detail.">
        <Box sx={{ maxWidth: 320 }}>
          <TextField
            select
            value={drillCampaign}
            onChange={(event) => setDrillCampaign(event.target.value)}
            size="medium"
            fullWidth
          >
            <MenuItem value="">Select campaign segment</MenuItem>
            {campaignStats.map((item) => (
              <MenuItem key={item.campaign_id ?? "none"} value={item.campaign_id ?? "none"}>
                {item.campaign_id ? item.campaign_id.slice(0, 8) : "Unmapped"}
              </MenuItem>
            ))}
          </TextField>
        </Box>
        {drillData ? (
          <Box sx={{ mt: 1.5, display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}>
            <KpiCard label="Calls" value={drillData.total_calls} />
            <KpiCard label="Connected" value={drillData.connected_calls} />
            <KpiCard label="Avg Duration" value={`${drillData.avg_duration_seconds}s`} />
          </Box>
        ) : (
          <Box sx={{ mt: 1.5 }}>
            <EmptyPanel
              title="No segment selected"
              description="Choose a campaign segment to inspect detailed conversion metrics."
            />
          </Box>
        )}
      </SectionCard>

      <SectionCard
        title="Roadmap Planned Features"
        subtitle="Current implementation status for roadmap items previously marked as planned."
      >
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)", xl: "repeat(3, 1fr)" } }}>
          {plannedFeatures.map((feature) => (
            <Paper key={feature.feature_key} variant="outlined" sx={{ p: 1.5 }}>
              <Typography variant="caption" color="text.secondary">
                {feature.feature_key}
              </Typography>
              <Typography variant="body2" sx={{ mt: 0.5, fontWeight: 600 }}>
                Status: {feature.status}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Evidence Count: {feature.evidence_count}
              </Typography>
            </Paper>
          ))}
          {plannedFeatures.length === 0 ? (
            <Typography variant="body2" color="text.secondary">
              No roadmap feature telemetry is available yet.
            </Typography>
          ) : null}
        </Box>
      </SectionCard>
    </AppShell>
  );
}

function GroupedBarChart({
  items,
}: {
  items: Array<{ label: string; success: number; failed: number }>;
}) {
  const max = Math.max(...items.map((item) => item.success + item.failed), 1);
  return (
    <Stack spacing={1}>
      {items.slice(0, 8).map((item) => (
        <Box key={item.label}>
          <Box sx={{ mb: 0.5, display: "flex", justifyContent: "space-between" }}>
            <Typography variant="caption" sx={{ pr: 1.5 }}>{item.label}</Typography>
            <Typography variant="caption">
              {item.success} / {item.failed}
            </Typography>
          </Box>
          <Box sx={{ height: 12, overflow: "hidden", borderRadius: 1, bgcolor: "action.hover", display: "flex" }}>
            <Box sx={{ bgcolor: "success.main", width: `${((item.success / max) * 100).toFixed(2)}%` }} />
            <Box sx={{ bgcolor: "error.main", width: `${((item.failed / max) * 100).toFixed(2)}%` }} />
          </Box>
        </Box>
      ))}
      {items.length === 0 ? <Typography variant="body2" color="text.secondary">No data available.</Typography> : null}
    </Stack>
  );
}

function TrendLineChart({
  items,
}: {
  items: Array<{ label: string; primary: number; secondary: number }>;
}) {
  if (items.length === 0) {
    return <Typography variant="body2" color="text.secondary">No trend data available.</Typography>;
  }

  const points = items.slice(-14);
  const max = Math.max(...points.map((item) => Math.max(item.primary, item.secondary)), 1);
  const width = 560;
  const height = 220;
  const pad = 20;
  const x = (index: number) => pad + (index * (width - pad * 2)) / Math.max(points.length - 1, 1);
  const y = (value: number) => height - pad - (value / max) * (height - pad * 2);
  const totalPath = points
    .map((item, index) => `${index === 0 ? "M" : "L"}${x(index)},${y(item.primary)}`)
    .join(" ");
  const connectedPath = points
    .map((item, index) => `${index === 0 ? "M" : "L"}${x(index)},${y(item.secondary)}`)
    .join(" ");

  return (
    <Stack spacing={1}>
      <Box component="svg" viewBox={`0 0 ${width} ${height}`} sx={{ height: 224, width: "100%", borderRadius: 2, border: 1, borderColor: "divider", bgcolor: "background.paper" }}>
        <path d={totalPath} fill="none" stroke="#0f172a" strokeWidth="2.5" />
        <path d={connectedPath} fill="none" stroke="#059669" strokeWidth="2.5" />
      </Box>
      <Box sx={{ display: "flex", alignItems: "center", gap: 2 }}>
        <Typography variant="caption"><Box component="span" sx={{ display: "inline-block", width: 8, height: 8, borderRadius: "50%", bgcolor: "#0f172a", mr: 0.5 }} /> Total Calls</Typography>
        <Typography variant="caption"><Box component="span" sx={{ display: "inline-block", width: 8, height: 8, borderRadius: "50%", bgcolor: "#059669", mr: 0.5 }} /> Connected Calls</Typography>
      </Box>
      <Box sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" } }}>
        {points.slice(-4).map((item) => (
          <Paper key={item.label} variant="outlined" sx={{ p: 1 }}>
            <Typography variant="caption" color="text.secondary">{item.label}</Typography>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>Total {item.primary}</Typography>
            <Typography variant="body2" sx={{ color: "success.dark" }}>Connected {item.secondary}</Typography>
          </Paper>
        ))}
      </Box>
    </Stack>
  );
}

function DonutChart({ slices }: { slices: Array<{ label: string; value: number }> }) {
  const total = slices.reduce((sum, slice) => sum + slice.value, 0);
  if (total === 0) {
    return <Typography variant="body2" color="text.secondary">No outcome data available.</Typography>;
  }

  const radius = 70;
  const circumference = 2 * Math.PI * radius;
  const colors = ["#059669", "#ef4444", "#0f172a", "#334155"];
  const arcData = slices.map((slice) => {
    const ratio = slice.value / total;
    const dash = ratio * circumference;
    return { label: slice.label, value: slice.value, dash };
  });

  return (
    <Stack spacing={1.5}>
      <Box sx={{ display: "flex", alignItems: "center", justifyContent: "center" }}>
        <Box component="svg" viewBox="0 0 220 220" sx={{ height: 208, width: 208 }}>
          <g transform="translate(110,110)">
            {arcData.map((slice, index) => {
              const previousDashTotal = arcData
                .slice(0, index)
                .reduce((sum, item) => sum + item.dash, 0);
              return (
                <circle
                  key={slice.label}
                  r={radius}
                  cx="0"
                  cy="0"
                  fill="none"
                  stroke={colors[index % colors.length]}
                  strokeWidth="20"
                  strokeDasharray={`${slice.dash} ${circumference - slice.dash}`}
                  strokeDashoffset={-previousDashTotal}
                  transform="rotate(-90)"
                />
              );
            })}
            <text x="0" y="-4" textAnchor="middle" className="fill-slate-900 text-[14px] font-semibold">
              {total}
            </text>
            <text x="0" y="16" textAnchor="middle" className="fill-slate-500 text-[10px]">
              Total Calls
            </text>
          </g>
        </Box>
      </Box>
      {slices.map((slice, index) => {
        const ratio = Number(((slice.value / total) * 100).toFixed(2));
        return (
          <Paper
            key={slice.label}
            variant="outlined"
            sx={{ px: 1.5, py: 1, display: "flex", alignItems: "center", justifyContent: "space-between" }}
          >
            <Typography variant="body2" sx={{ display: "inline-flex", alignItems: "center", gap: 1 }}>
              <Box component="span" sx={{ width: 10, height: 10, borderRadius: "50%", bgcolor: colors[index % colors.length] }} />
              {slice.label}
            </Typography>
            <Typography variant="body2">
              {slice.value} ({ratio}%)
            </Typography>
          </Paper>
        );
      })}
    </Stack>
  );
}
