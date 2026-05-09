"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, ToastMessage } from "@/components/ui-primitives";
import { listInboxThreads, listTeamMembers, sendInboxThreadMessage, updateInboxThread } from "@/lib/product-api";
import type { MessageThread, TeamMember } from "@/types/product";

export default function ConversationsPage() {
  const [channel, setChannel] = useState<"sms" | "whatsapp">("sms");
  const [threads, setThreads] = useState<MessageThread[]>([]);
  const [teamMembers, setTeamMembers] = useState<TeamMember[]>([]);
  const [selectedThreadId, setSelectedThreadId] = useState("");
  const [outboundBody, setOutboundBody] = useState("");
  const [loading, setLoading] = useState(true);
  const [loadingMembers, setLoadingMembers] = useState(true);
  const [sending, setSending] = useState(false);
  const [savingThread, setSavingThread] = useState(false);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");

  const loadThreads = useCallback(async () => {
    setLoading(true);
    try {
      const response = await listInboxThreads(channel, { per_page: 100 });
      setThreads(response.data);
      setSelectedThreadId((prev) => prev || response.data[0]?.id || "");
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load inbox threads.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }, [channel]);

  useEffect(() => {
    setSelectedThreadId("");
    setOutboundBody("");
    void loadThreads();
  }, [channel]);

  useEffect(() => {
    let mounted = true;
    void (async () => {
      setLoadingMembers(true);
      try {
        const result = await listTeamMembers({ per_page: 200 });
        if (mounted) {
          setTeamMembers(result.data ?? []);
        }
      } catch (err) {
        if (mounted) {
          setMessage(err instanceof Error ? err.message : "Failed to load team members.");
          setMessageTone("error");
        }
      } finally {
        if (mounted) {
          setLoadingMembers(false);
        }
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  async function onSend(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedThreadId || !outboundBody.trim()) return;
    setSending(true);
    setMessage("");
    try {
      await sendInboxThreadMessage(selectedThreadId, outboundBody.trim());
      setOutboundBody("");
      setMessage("Message sent.");
      setMessageTone("success");
      await loadThreads();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to send message.");
      setMessageTone("error");
    } finally {
      setSending(false);
    }
  }

  const selectedThread = useMemo(
    () => threads.find((item) => item.id === selectedThreadId) ?? null,
    [threads, selectedThreadId]
  );

  const assigneeOptions = useMemo(
    () =>
      teamMembers
        .map((member) => member.user)
        .filter((user): user is NonNullable<TeamMember["user"]> => Boolean(user))
        .map((user) => ({
          id: user.id,
          label: `${user.first_name} ${user.last_name}`.trim() || user.email,
        })),
    [teamMembers]
  );

  async function onUpdateThread(payload: { assigned_user_id?: string | null; status?: string; priority?: string }) {
    if (!selectedThread) return;
    setSavingThread(true);
    setMessage("");
    try {
      await updateInboxThread(selectedThread.id, payload);
      await loadThreads();
      setMessage("Thread updated.");
      setMessageTone("success");
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to update thread.");
      setMessageTone("error");
    } finally {
      setSavingThread(false);
    }
  }

  return (
    <AppShell requiredPermissions={["call.view"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "1.5fr 1fr" } }}>
        <SectionCard title="Unified Inbox" subtitle="View all SMS and WhatsApp conversation threads across leads.">
          <Box sx={{ mb: 1.5, display: "flex", gap: 1 }}>
            <TextField
              select
              size="medium"
              value={channel}
              onChange={(event) => setChannel(event.target.value as "sms" | "whatsapp")}
              sx={{ minWidth: 200 }}
            >
              <MenuItem value="sms">SMS Threads</MenuItem>
              <MenuItem value="whatsapp">WhatsApp Threads</MenuItem>
            </TextField>
            <MuiButton variant="outlined" onClick={() => void loadThreads()} disabled={loading}>
              Refresh
            </MuiButton>
          </Box>
          {loading ? (
            <LoadingState label="Loading inbox threads..." />
          ) : threads.length === 0 ? (
            <EmptyPanel title="No threads" description="No conversation threads are available for this channel." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto" }}>
              <Table size="medium">
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Counterparty</TableCell>
                    <TableCell>Channel</TableCell>
                    <TableCell>Lead</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Priority</TableCell>
                    <TableCell>Assignee</TableCell>
                    <TableCell>Last Message</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {threads.map((thread) => (
                    <TableRow
                      key={thread.id}
                      onClick={() => setSelectedThreadId(thread.id)}
                      selected={selectedThreadId === thread.id}
                      hover
                      sx={{ cursor: "pointer" }}
                    >
                      <TableCell>{thread.counterparty_number}</TableCell>
                      <TableCell sx={{ textTransform: "uppercase" }}>{thread.channel}</TableCell>
                      <TableCell>{thread.lead_id ?? "-"}</TableCell>
                      <TableCell>{thread.status ? <StatusBadge label={thread.status} /> : "-"}</TableCell>
                      <TableCell>{thread.priority ? <StatusBadge label={thread.priority} /> : "-"}</TableCell>
                      <TableCell>
                        {thread.assigned_user_id
                          ? assigneeOptions.find((item) => item.id === thread.assigned_user_id)?.label ?? thread.assigned_user_id
                          : "-"}
                      </TableCell>
                      <TableCell>{thread.last_message_at ? new Date(thread.last_message_at).toLocaleString() : "-"}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          )}
        </SectionCard>

        <SectionCard title="Thread Composer" subtitle="Send outbound reply from selected thread.">
          {selectedThread ? (
            <Stack spacing={1}>
              <Typography variant="body2">
                <Typography component="span" color="text.secondary">Thread:</Typography> {selectedThread.id}
              </Typography>
              <Typography variant="body2">
                <Typography component="span" color="text.secondary">To:</Typography> {selectedThread.counterparty_number}
              </Typography>
              <Typography variant="body2">
                <Typography component="span" color="text.secondary">Lead:</Typography> {selectedThread.lead_id ?? "Unknown"}
              </Typography>
              <Box sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}>
                <TextField
                  select
                  size="medium"
                  label="Status"
                  value={selectedThread.status ?? "open"}
                  onChange={(event) => void onUpdateThread({ status: event.target.value })}
                  disabled={savingThread}
                >
                  <MenuItem value="open">Open</MenuItem>
                  <MenuItem value="pending">Pending</MenuItem>
                  <MenuItem value="resolved">Resolved</MenuItem>
                  <MenuItem value="closed">Closed</MenuItem>
                </TextField>
                <TextField
                  select
                  size="medium"
                  label="Priority"
                  value={selectedThread.priority ?? "normal"}
                  onChange={(event) => void onUpdateThread({ priority: event.target.value })}
                  disabled={savingThread}
                >
                  <MenuItem value="low">Low</MenuItem>
                  <MenuItem value="normal">Normal</MenuItem>
                  <MenuItem value="high">High</MenuItem>
                  <MenuItem value="urgent">Urgent</MenuItem>
                </TextField>
                <TextField
                  select
                  size="medium"
                  label="Assignee"
                  value={selectedThread.assigned_user_id ?? ""}
                  onChange={(event) => void onUpdateThread({ assigned_user_id: event.target.value || null })}
                  disabled={savingThread || loadingMembers}
                >
                  <MenuItem value="">Unassigned</MenuItem>
                  {assigneeOptions.map((item) => (
                    <MenuItem key={item.id} value={item.id}>
                      {item.label}
                    </MenuItem>
                  ))}
                </TextField>
              </Box>
              <Box sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" } }}>
                <Box sx={{ border: 1, borderColor: "divider", borderRadius: 2, px: 1.5, py: 1 }}>
                  <Typography variant="caption" color="text.secondary">First response due</Typography>
                  <Typography variant="body2">
                    {selectedThread.first_response_due_at ? new Date(selectedThread.first_response_due_at).toLocaleString() : "-"}
                  </Typography>
                </Box>
                <Box sx={{ border: 1, borderColor: "divider", borderRadius: 2, px: 1.5, py: 1 }}>
                  <Typography variant="caption" color="text.secondary">Resolution due</Typography>
                  <Typography variant="body2">
                    {selectedThread.resolution_due_at ? new Date(selectedThread.resolution_due_at).toLocaleString() : "-"}
                  </Typography>
                </Box>
              </Box>
              <Box component="form" onSubmit={onSend} sx={{ mt: 1, display: "grid", gap: 1 }}>
                <TextField
                  multiline
                  minRows={5}
                  size="medium"
                  value={outboundBody}
                  onChange={(event) => setOutboundBody(event.target.value)}
                  placeholder={`Send ${selectedThread.channel.toUpperCase()} message...`}
                />
                <MuiButton type="submit" variant="contained" disabled={sending || !outboundBody.trim()}>
                  {sending ? "Sending..." : "Send Message"}
                </MuiButton>
              </Box>
            </Stack>
          ) : (
            <EmptyPanel title="No thread selected" description="Select a thread from the table to compose a reply." />
          )}
        </SectionCard>
      </Box>
    </AppShell>
  );
}
