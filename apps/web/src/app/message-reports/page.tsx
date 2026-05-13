"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, LoadingState, SectionCard } from "@/components/app-shell";
import { EmptyPanel, ToastMessage } from "@/components/ui-primitives";
import { listCampaigns, loadCampaignMessageReport } from "@/lib/product-api";
import type { Campaign, CampaignMessageReport } from "@/types/product";

function truncate(text: string, maxLength: number): string {
  const normalized = String(text ?? "");
  if (normalized.length <= maxLength) return normalized;
  return `${normalized.slice(0, Math.max(0, maxLength - 1))}…`;
}

export default function MessageReportsPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [selectedCampaignId, setSelectedCampaignId] = useState("");
  const [report, setReport] = useState<CampaignMessageReport | null>(null);
  const [loadingCampaigns, setLoadingCampaigns] = useState(true);
  const [loadingReport, setLoadingReport] = useState(false);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");

  const messageCampaigns = useMemo(() => {
    return campaigns.filter((campaign) => ["sms", "whatsapp", "outreach"].includes(campaign.type));
  }, [campaigns]);

  const selectedCampaign = useMemo(() => {
    return messageCampaigns.find((campaign) => campaign.id === selectedCampaignId) ?? null;
  }, [messageCampaigns, selectedCampaignId]);

  const loadCampaignList = useCallback(async () => {
    setLoadingCampaigns(true);
    try {
      const data = await listCampaigns();
      setCampaigns(data);
      const first = data.find((campaign) => ["sms", "whatsapp", "outreach"].includes(campaign.type));
      setSelectedCampaignId((prev) => prev || first?.id || "");
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load campaigns.");
      setMessageTone("error");
    } finally {
      setLoadingCampaigns(false);
    }
  }, []);

  const loadReport = useCallback(async (campaignId: string) => {
    setLoadingReport(true);
    try {
      const result = await loadCampaignMessageReport(campaignId);
      setReport(result);
    } catch (err) {
      setReport(null);
      setMessage(err instanceof Error ? err.message : "Failed to load message report.");
      setMessageTone("error");
    } finally {
      setLoadingReport(false);
    }
  }, []);

  useEffect(() => {
    void loadCampaignList();
  }, [loadCampaignList]);

  useEffect(() => {
    if (!selectedCampaignId) return;
    void loadReport(selectedCampaignId);
  }, [selectedCampaignId, loadReport]);

  return (
    <AppShell requiredPermissions={["call.view"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}

      <SectionCard
        title="Message Reports"
        subtitle="See which leads received which messages from a lead-list campaign and what replies were received."
      >
        <Stack direction={{ xs: "column", md: "row" }} spacing={1} alignItems={{ xs: "stretch", md: "center" }} sx={{ mb: 2 }}>
          <TextField
            select
            size="medium"
            label="Campaign"
            value={selectedCampaignId}
            onChange={(e) => setSelectedCampaignId(e.target.value)}
            sx={{ minWidth: { xs: "100%", md: 380 } }}
            disabled={loadingCampaigns}
          >
            {messageCampaigns.map((campaign) => (
              <MenuItem key={campaign.id} value={campaign.id}>
                {campaign.name}
              </MenuItem>
            ))}
          </TextField>
          <MuiButton variant="outlined" onClick={() => void loadCampaignList()} disabled={loadingCampaigns}>
            Refresh Campaigns
          </MuiButton>
          <MuiButton
            variant="contained"
            onClick={() => (selectedCampaignId ? void loadReport(selectedCampaignId) : undefined)}
            disabled={!selectedCampaignId || loadingReport}
          >
            {loadingReport ? "Loading..." : "Refresh Report"}
          </MuiButton>
        </Stack>

        {loadingCampaigns ? (
          <LoadingState label="Loading campaigns..." />
        ) : messageCampaigns.length === 0 ? (
          <EmptyPanel title="No message campaigns" description="Create an SMS/WhatsApp/Outreach campaign first." />
        ) : !selectedCampaign ? (
          <EmptyPanel title="Select a campaign" description="Pick a message campaign to view delivery and replies." />
        ) : loadingReport ? (
          <LoadingState label="Loading report..." />
        ) : !report || report.entries.length === 0 ? (
          <EmptyPanel title="No messages found" description="No outbound messages were recorded for the latest run yet." />
        ) : (
          <Box sx={{ display: "grid", gap: 1.25 }}>
            <Typography variant="caption" color="text.secondary">
              Campaign: {selectedCampaign.name} • Run: {report.campaign_run_id} • Channel: {report.channel}
            </Typography>
            {report.summary ? (
              <Typography variant="caption" color="text.secondary">
                Leads: {report.summary.leads} • Unique Numbers: {report.summary.unique_numbers}
                {report.summary.duplicate_numbers > 0 ? ` • Duplicate Numbers: ${report.summary.duplicate_numbers}` : ""}
              </Typography>
            ) : null}
            <Paper variant="outlined" sx={{ overflowX: "auto" }}>
              <Table size="medium">
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Lead</TableCell>
                    <TableCell>Phone</TableCell>
                    <TableCell>Last Message</TableCell>
                    <TableCell>Last Reply</TableCell>
                    <TableCell align="right">Counts</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {report.entries.map((entry) => {
                    const lastOutbound = entry.outbound[entry.outbound.length - 1];
                    const lastInbound = entry.inbound[entry.inbound.length - 1];
                    return (
                      <TableRow key={entry.thread_id} hover>
                        <TableCell>{entry.lead?.full_name || "-"}</TableCell>
                        <TableCell>{entry.lead?.phone || entry.counterparty_number || "-"}</TableCell>
                        <TableCell>
                          <Typography variant="body2">{lastOutbound ? truncate(lastOutbound.body, 100) : "-"}</Typography>
                          {lastOutbound ? (
                            <Typography variant="caption" color="text.secondary">
                              {lastOutbound.status} {lastOutbound.sent_at ? `• ${lastOutbound.sent_at}` : ""}
                            </Typography>
                          ) : null}
                        </TableCell>
                        <TableCell>
                          <Typography variant="body2">{lastInbound ? truncate(lastInbound.body, 100) : "-"}</Typography>
                          {lastInbound?.sent_at ? <Typography variant="caption" color="text.secondary">{lastInbound.sent_at}</Typography> : null}
                        </TableCell>
                        <TableCell align="right">
                          <Typography variant="body2">{entry.counts.outbound} sent</Typography>
                          <Typography variant="caption" color="text.secondary">{entry.counts.inbound} replies</Typography>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </Paper>
          </Box>
        )}
      </SectionCard>
    </AppShell>
  );
}
