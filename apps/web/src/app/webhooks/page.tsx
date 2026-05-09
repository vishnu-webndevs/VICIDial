"use client";

import { useEffect, useState } from "react";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { fetchWebhookOverview, listWebhookLogs, replayWebhookEvent, type WebhookLog, type WebhookOverview } from "@/lib/product-api";
import {
  Box,
  MuiButton,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from "@/ui";

export default function WebhooksPage() {
  const [logs, setLogs] = useState<WebhookLog[]>([]);
  const [overview, setOverview] = useState<WebhookOverview | null>(null);
  const [loading, setLoading] = useState(false);
  const [replayingId, setReplayingId] = useState<string | null>(null);
  const [message, setMessage] = useState("");

  async function loadLogs() {
    setLoading(true);
    setMessage("");
    try {
      const [overviewData, logsData] = await Promise.all([
        fetchWebhookOverview(),
        listWebhookLogs(50),
      ]);
      setOverview(overviewData);
      setLogs(logsData);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load webhook logs.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadLogs();
  }, []);

  async function replay(source: "provider" | "stripe", id: string) {
    setReplayingId(id);
    setMessage("");
    try {
      await replayWebhookEvent(source, id);
      setMessage("Webhook replay queued.");
      await loadLogs();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to replay webhook.");
    } finally {
      setReplayingId(null);
    }
  }

  return (
    <AppShell requiredPermissions={["webhook.view"]}>
      <SectionCard title="Webhook Health Overview" subtitle="Provider and Stripe webhook reliability metrics for the last 24 hours.">
        {overview ? (
          <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)", xl: "repeat(4, 1fr)" } }}>
            <Paper variant="outlined" sx={{ p: 1.5 }}>
              <Typography variant="caption" color="text.secondary">Stripe Callback</Typography>
              <Typography variant="body2">{overview.callback_urls.stripe}</Typography>
              <Typography variant="body2" color="text.secondary">
                {overview.metrics.stripe_failed}/{overview.metrics.stripe_total} failed ({overview.metrics.stripe_failure_rate}%)
              </Typography>
            </Paper>
            <Paper variant="outlined" sx={{ p: 1.5 }}>
              <Typography variant="caption" color="text.secondary">Twilio Callback</Typography>
              <Typography variant="body2">{overview.callback_urls.twilio}</Typography>
              <Typography variant="body2" color="text.secondary">
                Active accounts: {overview.active_provider_accounts.twilio}
              </Typography>
            </Paper>
            <Paper variant="outlined" sx={{ p: 1.5 }}>
              <Typography variant="caption" color="text.secondary">Vonage Callback</Typography>
              <Typography variant="body2">{overview.callback_urls.vonage}</Typography>
              <Typography variant="body2" color="text.secondary">
                Active accounts: {overview.active_provider_accounts.vonage}
              </Typography>
            </Paper>
            <Paper variant="outlined" sx={{ p: 1.5 }}>
              <Typography variant="caption" color="text.secondary">Provider Failure</Typography>
              <Typography variant="body2">
                {overview.metrics.provider_failed}/{overview.metrics.provider_total} ({overview.metrics.provider_failure_rate}%)
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Window: last {overview.window_hours} hours
              </Typography>
            </Paper>
          </Box>
        ) : (
          <LoadingState label="Loading webhook overview..." />
        )}
      </SectionCard>
      <SectionCard title="Webhook Delivery Logs" subtitle="Observe inbound provider and billing webhook events.">
        <MuiButton type="button" variant="outlined" disabled={loading} onClick={() => void loadLogs()}>
          {loading ? "Refreshing..." : "Refresh"}
        </MuiButton>
        <TableContainer component={Paper} variant="outlined" sx={{ mt: 3 }}>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Source</TableCell>
                <TableCell>Event</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Processed</TableCell>
                <TableCell>Error</TableCell>
                <TableCell align="right">Action</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={6}>
                      <LoadingState label="Loading webhook logs..." />
                    </TableCell>
                  </TableRow>
                ) : null}
              {logs.map((log) => (
                <TableRow hover key={log.id}>
                  <TableCell>{log.source}</TableCell>
                  <TableCell>{log.provider_event_type || log.event_type}</TableCell>
                  <TableCell>{log.status ?? "-"}</TableCell>
                  <TableCell>{log.processed_at ? new Date(log.processed_at).toLocaleString() : "-"}</TableCell>
                  <TableCell>{log.error_message ?? "-"}</TableCell>
                  <TableCell align="right">
                    <MuiButton
                      type="button"
                      size="medium"
                      variant="outlined"
                      disabled={replayingId === log.id}
                      onClick={() => void replay(log.source, log.id)}
                    >
                      {replayingId === log.id ? "Replaying..." : "Replay"}
                    </MuiButton>
                  </TableCell>
                </TableRow>
              ))}
              {logs.length === 0 ? (
                <TableRow>
                    <TableCell colSpan={6}>
                      <EmptyState title="No webhook logs" description="No webhook delivery logs are available yet." />
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </TableContainer>
        {message ? <Box sx={{ mt: 2 }}><ErrorState message={message} /></Box> : null}
      </SectionCard>
    </AppShell>
  );
}
