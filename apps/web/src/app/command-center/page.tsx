"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, KpiCard, ToastMessage } from "@/components/ui-primitives";
import { listAgentActivities, listCampaigns, loadCampaignCommandCenter, loadCampaignStatus, stopCampaign, updateAgentSession } from "@/lib/product-api";
import type { AgentActivity, Campaign } from "@/types/product";

export default function CommandCenterPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [selectedCampaignId, setSelectedCampaignId] = useState("");
  const [agentStatuses, setAgentStatuses] = useState<AgentActivity[]>([]);
  const [loading, setLoading] = useState(true);
  const [queueDepth, setQueueDepth] = useState(0);
  const [activeCalls, setActiveCalls] = useState(0);
  const [connectRate, setConnectRate] = useState(0);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");

  const selectedCampaign = campaigns.find((item) => item.id === selectedCampaignId) ?? null;

  const loadData = useCallback(async (isSilent = false) => {
    if (!isSilent) {
      setLoading(true);
    }
    try {
      const campaignData = await listCampaigns();
      setCampaigns(prev => {
        const prevSign = JSON.stringify(prev);
        const nextSign = JSON.stringify(campaignData);
        return prevSign === nextSign ? prev : campaignData;
      });
      const currentId = selectedCampaignId || campaignData[0]?.id || "";
      setSelectedCampaignId(currentId);
      const [commandCenter, statusData, agents] = await Promise.all([
        currentId ? loadCampaignCommandCenter(currentId, { per_page: 50 }) : Promise.resolve({ entries: [], total: 0, currentPage: 1, lastPage: 1 }),
        currentId ? loadCampaignStatus(currentId) : Promise.resolve({ queue_depth: 0, active_calls: 0, connect_rate: 0, answer_rate: 0, drop_rate: 0, pacing_ratio: 1, statuses: {} }),
        listAgentActivities({ per_page: 50 }),
      ]);
      setAgentStatuses(prev => {
        const prevSign = JSON.stringify(prev);
        const nextSign = JSON.stringify(agents);
        return prevSign === nextSign ? prev : agents;
      });
      setQueueDepth(commandCenter.total || statusData.queue_depth || 0);
      setActiveCalls(statusData.active_calls || 0);
      setConnectRate(statusData.connect_rate || 0);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load command center.");
      setMessageTone("error");
    } finally {
      if (!isSilent) {
        setLoading(false);
      }
    }
  }, [selectedCampaignId]);

  useEffect(() => {
    void loadData();

    const handleVisibilityChange = () => {
      if (document.visibilityState === "visible") {
        void loadData(true);
      }
    };
    document.addEventListener("visibilitychange", handleVisibilityChange);

    const timer = window.setInterval(() => {
      if (document.hidden) return;
      void loadData(true);
    }, 5000);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", handleVisibilityChange);
    };
  }, [loadData]);

  async function handleStopCampaign() {
    if (!selectedCampaignId) return;
    try {
      await stopCampaign(selectedCampaignId);
      setMessage("Campaign stopped.");
      setMessageTone("success");
      await loadData(true);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to stop campaign.");
      setMessageTone("error");
    }
  }

  async function setAgentPause(agentSessionId: string, paused: boolean) {
    try {
      await updateAgentSession(agentSessionId, { paused, pause_reason: paused ? "manual" : undefined });
      setMessage(paused ? "Agent paused." : "Agent resumed.");
      setMessageTone("success");
      await loadData(true);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to update agent status.");
      setMessageTone("error");
    }
  }

  const summary = useMemo(() => {
    const online = agentStatuses.filter((agent) => agent.status === "online").length;
    const paused = agentStatuses.filter((agent) => agent.paused).length;
    return { online, paused };
  }, [agentStatuses]);

  return (
    <AppShell requiredPermissions={["call.view"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard title="Campaign Selection" subtitle="Monitor live queue and control agents by campaign.">
          <Box sx={{ display: "flex", flexDirection: "column", gap: 1.5, width: "100%" }}>
            <Box sx={{ display: "flex", flexDirection: { xs: "column", sm: "row" }, gap: 1.5, alignItems: { xs: "stretch", sm: "center" }, width: "100%" }}>
              <TextField
                select
                fullWidth
                size="medium"
                value={selectedCampaignId}
                onChange={(event) => setSelectedCampaignId(event.target.value)}
                sx={{ width: "100%", maxWidth: "100%" }}
              >
                {campaigns.map((campaign) => (
                  <MenuItem key={campaign.id} value={campaign.id}>
                    {campaign.name}
                  </MenuItem>
                ))}
              </TextField>
              <Box sx={{ display: "flex", gap: 1, flexWrap: "wrap", width: { xs: "100%", sm: "auto" } }}>
                <MuiButton
                  variant="outlined"
                  onClick={() => void loadData()}
                  disabled={loading}
                  sx={{ flex: { xs: 1, sm: "initial" } }}
                >
                  Refresh
                </MuiButton>
                {selectedCampaign && ["running", "paused"].includes(selectedCampaign.status) ? (
                  <MuiButton
                    variant="outlined"
                    color="error"
                    onClick={() => void handleStopCampaign()}
                    disabled={loading}
                    sx={{ flex: { xs: 1, sm: "initial" } }}
                  >
                    Stop Campaign
                  </MuiButton>
                ) : null}
              </Box>
            </Box>
            {selectedCampaign ? (
              <Box sx={{ display: "flex", alignItems: "center", gap: 1, flexWrap: "wrap", mt: 0.5 }}>
                <Typography variant="subtitle2" sx={{ fontWeight: 600, color: "#1e293b" }}>
                  {selectedCampaign.name}
                </Typography>
                <StatusBadge label={selectedCampaign.status} />
              </Box>
            ) : null}
          </Box>
        </SectionCard>

        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", sm: "repeat(2, 1fr)", lg: "repeat(4, 1fr)" } }}>
          <KpiCard label="Queue Depth" value={queueDepth} />
          <KpiCard label="Active Calls" value={activeCalls} />
          <KpiCard label="Connect Rate" value={`${Math.round(connectRate * 100)}%`} />
          <KpiCard label="Agents Online" value={summary.online} hint={`${summary.paused} paused`} />
        </Box>

        <SectionCard title="Live Agent Status" subtitle="Pause/resume controls for active agent sessions.">
          {loading ? (
            <LoadingState label="Loading agents..." />
          ) : agentStatuses.length === 0 ? (
            <EmptyPanel title="No active agents" description="Agents will appear once they have active or recent sessions." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto", width: "100%", maxWidth: "100%", minWidth: 0 }}>
              <Table size="medium" sx={{ minWidth: 550, width: "100%" }}>
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Agent</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Paused</TableCell>
                    <TableCell>Calls Handled</TableCell>
                    <TableCell>Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {agentStatuses.map((agent) => (
                    <TableRow key={agent.id}>
                      <TableCell>{agent.agent_name}</TableCell>
                      <TableCell><StatusBadge label={agent.status} /></TableCell>
                      <TableCell>{agent.paused ? "Yes" : "No"}</TableCell>
                      <TableCell>{agent.calls_handled}</TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={1}>
                          <MuiButton size="medium" variant="outlined" onClick={() => void setAgentPause(agent.id, true)} disabled={agent.paused}>
                            Pause
                          </MuiButton>
                          <MuiButton size="medium" variant="outlined" onClick={() => void setAgentPause(agent.id, false)} disabled={!agent.paused}>
                            Resume
                          </MuiButton>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          )}
        </SectionCard>
      </Box>
    </AppShell>
  );
}
