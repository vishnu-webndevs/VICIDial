"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Box, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";
import { EmptyPanel } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

function toIso(value: Date): string {
  return value.toISOString().slice(0, 10);
}

const dayOrder = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

type HeatmapPoint = { day: string; hour: number; calls: number };
type CampaignRow = { campaign_id: string; campaign_name: string; calls: number; connected: number };
type AgentRow = { agent_id: string; agent_name: string; calls: number; connected: number; connect_rate?: number };

export default function ReportsPage() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [heatmap, setHeatmap] = useState<HeatmapPoint[]>([]);
  const [campaigns, setCampaigns] = useState<CampaignRow[]>([]);
  const [agents, setAgents] = useState<AgentRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");

  useEffect(() => {
    const today = new Date();
    const start = new Date(today);
    start.setDate(today.getDate() - 30);
    setFrom(toIso(start));
    setTo(toIso(today));
  }, []);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const { token, tenantId } = getTenantContext();
      const [heatmapResponse, campaignResponse, agentResponse] = await Promise.all([
        apiRequest<{ data: HeatmapPoint[] }>(`/analytics/heatmap?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, { token, tenantId }),
        apiRequest<{ data: CampaignRow[] }>(`/analytics/campaigns?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, { token, tenantId }),
        apiRequest<{ data: AgentRow[] }>(`/analytics/agents?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, { token, tenantId }),
      ]);
      setHeatmap(heatmapResponse.data ?? []);
      setCampaigns(campaignResponse.data ?? []);
      setAgents(agentResponse.data ?? []);
      setMessage("");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load report data.");
    } finally {
      setLoading(false);
    }
  }, [from, to]);

  useEffect(() => {
    if (!from || !to) {
      return;
    }
    void loadData();
  }, [from, to, loadData]);

  const heatmapGrid = useMemo(() => {
    const grid = new Map<string, number>();
    heatmap.forEach((point) => {
      const day = point.day?.slice(0, 3) ?? "";
      grid.set(`${day}-${point.hour}`, Number(point.calls ?? 0));
    });
    return dayOrder.map((day) =>
      Array.from({ length: 24 }, (_, hour) => ({
        day,
        hour,
        calls: grid.get(`${day}-${hour}`) ?? 0,
      }))
    );
  }, [heatmap]);

  const heatmapMax = useMemo(() => Math.max(1, ...heatmapGrid.flat().map((cell) => cell.calls)), [heatmapGrid]);
  const campaignMax = useMemo(
    () => Math.max(1, ...campaigns.map((row) => Math.max(Number(row.calls ?? 0), Number(row.connected ?? 0)))),
    [campaigns]
  );

  return (
    <AppShell requiredPermissions={["analytics.view"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard title="Filters" subtitle="Adjust date range for all report panels.">
          <Box sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}>
            <TextField type="date" size="medium" value={from} onChange={(event) => setFrom(event.target.value)} />
            <TextField type="date" size="medium" value={to} onChange={(event) => setTo(event.target.value)} />
            <TextField size="medium" value="Applied to all reports" disabled />
          </Box>
        </SectionCard>
        {message ? <Typography color="error">{message}</Typography> : null}

        <SectionCard title="Heatmap" subtitle="7 x 24 call volume grid with hover details.">
          {loading ? (
            <Typography variant="body2" color="text.secondary">Loading heatmap...</Typography>
          ) : heatmapGrid.flat().length === 0 ? (
            <EmptyPanel title="No heatmap data" description="Heatmap values are unavailable for this period." />
          ) : (
            <Paper variant="outlined" sx={{ p: 1.25, overflowX: "auto" }}>
              <Box sx={{ minWidth: 980, display: "grid", gap: 0.5 }}>
                {heatmapGrid.map((row) => (
                  <Stack key={row[0].day} direction="row" spacing={0.5}>
                    <Typography variant="caption" sx={{ width: 30 }}>{row[0].day}</Typography>
                    {row.map((cell) => (
                      <Box
                        key={`${cell.day}-${cell.hour}`}
                        title={`${cell.day} ${cell.hour.toString().padStart(2, "0")}:00 - ${cell.calls} calls`}
                        sx={{
                          width: 20,
                          height: 20,
                          borderRadius: 0.5,
                          bgcolor: `rgba(25, 118, 210, ${Math.max(cell.calls / heatmapMax, 0.08)})`,
                        }}
                      />
                    ))}
                  </Stack>
                ))}
              </Box>
            </Paper>
          )}
        </SectionCard>

        <SectionCard title="Campaign Chart" subtitle="Horizontal bars for calls vs connected.">
          {loading ? (
            <Typography variant="body2" color="text.secondary">Loading campaign chart...</Typography>
          ) : campaigns.length === 0 ? (
            <EmptyPanel title="No campaign analytics" description="No campaign analytics were returned." />
          ) : (
            <Stack spacing={1}>
              {campaigns.map((campaign) => (
                <Paper key={campaign.campaign_id} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="body2" sx={{ mb: 0.75 }}>{campaign.campaign_name}</Typography>
                  <Box sx={{ mb: 0.5, height: 10, borderRadius: 1, bgcolor: "action.hover" }}>
                    <Box sx={{ width: `${Math.max((Number(campaign.calls ?? 0) / campaignMax) * 100, 2)}%`, height: "100%", bgcolor: "primary.light", borderRadius: 1 }} />
                  </Box>
                  <Box sx={{ height: 10, borderRadius: 1, bgcolor: "action.hover" }}>
                    <Box sx={{ width: `${Math.max((Number(campaign.connected ?? 0) / campaignMax) * 100, 2)}%`, height: "100%", bgcolor: "success.main", borderRadius: 1 }} />
                  </Box>
                  <Typography variant="caption" color="text.secondary">Calls: {campaign.calls} | Connected: {campaign.connected}</Typography>
                </Paper>
              ))}
            </Stack>
          )}
        </SectionCard>

        <SectionCard title="Agent Table" subtitle="Connect rate with color-coded mini bars.">
          {loading ? (
            <Typography variant="body2" color="text.secondary">Loading agents...</Typography>
          ) : agents.length === 0 ? (
            <EmptyPanel title="No agents" description="No agent analytics were returned for this period." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto" }}>
              <Table size="medium">
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Agent</TableCell>
                    <TableCell>Calls</TableCell>
                    <TableCell>Connected</TableCell>
                    <TableCell>Connect Rate</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {agents.map((agent) => {
                    const calls = Number(agent.calls ?? 0);
                    const connected = Number(agent.connected ?? 0);
                    const rate = agent.connect_rate ?? (calls ? (connected / calls) * 100 : 0);
                    const color = rate > 20 ? "success.main" : rate >= 10 ? "warning.main" : "error.main";
                    return (
                      <TableRow key={agent.agent_id}>
                        <TableCell>{agent.agent_name}</TableCell>
                        <TableCell>{calls}</TableCell>
                        <TableCell>{connected}</TableCell>
                        <TableCell>
                          <Box sx={{ display: "flex", alignItems: "center", gap: 1 }}>
                            <Box sx={{ width: 90, height: 8, borderRadius: 1, bgcolor: "action.hover" }}>
                              <Box
                                sx={{
                                  width: `${Math.min(Math.max(rate, 0), 100)}%`,
                                  height: "100%",
                                  borderRadius: 1,
                                  bgcolor: color,
                                }}
                              />
                            </Box>
                            <Typography variant="caption">{rate.toFixed(1)}%</Typography>
                          </Box>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </Paper>
          )}
        </SectionCard>
      </Box>
    </AppShell>
  );
}
