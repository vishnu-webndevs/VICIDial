"use client";

import { useParams } from "next/navigation";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";
import { fetchLeadTimeline, listCallbacks, sendLeadSms, sendLeadWhatsapp } from "@/lib/product-api";
import type { Lead, LeadTimelineEntry } from "@/types/product";

export default function LeadDetailPage() {
  const params = useParams<{ id: string }>();
  const leadId = params?.id ?? "";
  const [lead, setLead] = useState<Lead | null>(null);
  const [timeline, setTimeline] = useState<LeadTimelineEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");
  const [timelineFilter, setTimelineFilter] = useState<string>("");
  const [sending, setSending] = useState(false);
  const [callbacksCount, setCallbacksCount] = useState(0);
  const [actionTab, setActionTab] = useState<"note" | "callback" | "sms" | "whatsapp">("note");
  const [noteText, setNoteText] = useState("");
  const [callbackDate, setCallbackDate] = useState("");
  const [callbackTime, setCallbackTime] = useState("");
  const [callbackNotes, setCallbackNotes] = useState("");
  const [smsText, setSmsText] = useState("");
  const [whatsappText, setWhatsappText] = useState("");

  const loadLeadTimeline = useCallback(async () => {
    if (!leadId) return;
    setLoading(true);
    try {
      const [timelineData, callbacksData] = await Promise.all([
        fetchLeadTimeline(leadId, { per_page: 100 }),
        listCallbacks({ state: "due", per_page: 200 }),
      ]);
      setLead(timelineData.payload.lead);
      setTimeline(timelineData.payload.timeline);
      const dueForLead = callbacksData.data.filter((item) => String((item as { lead_id?: string }).lead_id ?? "") === leadId);
      setCallbacksCount(dueForLead.length);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load lead timeline.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }, [leadId]);

  useEffect(() => {
    void loadLeadTimeline();
  }, [loadLeadTimeline]);

  function appendTimelineEntry(entry: LeadTimelineEntry) {
    setTimeline((prev) => [entry, ...prev]);
  }

  async function onSendMessage(event: FormEvent<HTMLFormElement>, channel: "sms" | "whatsapp") {
    event.preventDefault();
    const outboundText = channel === "sms" ? smsText : whatsappText;
    if (!outboundText.trim()) return;
    setSending(true);
    setMessage("");
    try {
      if (channel === "sms") {
        await sendLeadSms(leadId, outboundText.trim());
      } else {
        await sendLeadWhatsapp(leadId, outboundText.trim());
      }
      if (channel === "sms") {
        setSmsText("");
      } else {
        setWhatsappText("");
      }
      setMessage(`${channel.toUpperCase()} sent successfully.`);
      setMessageTone("success");
      appendTimelineEntry({
        type: channel,
        id: `${channel}-${Date.now()}`,
        at: new Date().toISOString(),
        content: outboundText.trim(),
        direction: "outbound",
        agent: "You",
        status: "sent",
      });
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to send outbound message.");
      setMessageTone("error");
    } finally {
      setSending(false);
    }
  }

  async function onAddNote(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!noteText.trim()) return;
    if (noteText.trim().length > 1000) {
      setMessage("Note exceeds 1000 characters.");
      setMessageTone("error");
      return;
    }
    setSending(true);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/leads/${leadId}/notes`, {
        method: "POST",
        token,
        tenantId,
        body: { note: noteText.trim() },
      });
      appendTimelineEntry({
        type: "note",
        id: `note-${Date.now()}`,
        at: new Date().toISOString(),
        content: noteText.trim(),
        agent: "You",
      });
      setNoteText("");
      setMessage("Note added.");
      setMessageTone("success");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to save note.");
      setMessageTone("error");
    } finally {
      setSending(false);
    }
  }

  async function onScheduleCallback(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const scheduledAt = `${callbackDate}T${callbackTime}`;
    if (!callbackDate || !callbackTime) {
      setMessage("Callback date and time are required.");
      setMessageTone("error");
      return;
    }
    const target = new Date(scheduledAt);
    if (Number.isNaN(target.getTime()) || target <= new Date()) {
      setMessage("Callback must be scheduled in the future.");
      setMessageTone("error");
      return;
    }
    setSending(true);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/leads/${leadId}/callbacks`, {
        method: "POST",
        token,
        tenantId,
        body: {
          scheduled_at: target.toISOString(),
          notes: callbackNotes.trim() || null,
        },
      });
      appendTimelineEntry({
        type: "callback",
        id: `callback-${Date.now()}`,
        at: new Date().toISOString(),
        scheduled_at: target.toISOString(),
        content: callbackNotes.trim(),
        status: "scheduled",
        agent: "You",
      });
      setCallbackDate("");
      setCallbackTime("");
      setCallbackNotes("");
      setCallbacksCount((prev) => prev + 1);
      setMessage("Callback scheduled.");
      setMessageTone("success");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to schedule callback.");
      setMessageTone("error");
    } finally {
      setSending(false);
    }
  }

  const filteredTimeline = timeline.filter((entry) => !timelineFilter || entry.type === timelineFilter);

  return (
    <AppShell requiredPermissions={["call.view"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "1.7fr 1fr" } }}>
        <SectionCard title="Conversation Timeline" subtitle="Calls, SMS, WhatsApp, notes, and callbacks in a single chronological thread.">
          <Paper variant="outlined" sx={{ mb: 1.5, p: 1.25 }}>
            <Stack direction="row" spacing={2} sx={{ mb: 1.25 }}>
              <Box component="button" type="button" onClick={() => setActionTab("note")} sx={{ border: 0, bgcolor: "transparent", borderBottom: actionTab === "note" ? "2px solid" : "2px solid transparent", borderColor: "primary.main", cursor: "pointer", pb: 0.5 }}>Add Note</Box>
              <Box component="button" type="button" onClick={() => setActionTab("callback")} sx={{ border: 0, bgcolor: "transparent", borderBottom: actionTab === "callback" ? "2px solid" : "2px solid transparent", borderColor: "primary.main", cursor: "pointer", pb: 0.5 }}>Schedule Callback</Box>
              <Box component="button" type="button" onClick={() => setActionTab("sms")} sx={{ border: 0, bgcolor: "transparent", borderBottom: actionTab === "sms" ? "2px solid" : "2px solid transparent", borderColor: "primary.main", cursor: "pointer", pb: 0.5 }}>Send SMS</Box>
              <Box component="button" type="button" onClick={() => setActionTab("whatsapp")} sx={{ border: 0, bgcolor: "transparent", borderBottom: actionTab === "whatsapp" ? "2px solid" : "2px solid transparent", borderColor: "primary.main", cursor: "pointer", pb: 0.5 }}>Send WhatsApp</Box>
            </Stack>

            {actionTab === "note" ? (
              <Box component="form" onSubmit={onAddNote} sx={{ display: "grid", gap: 1 }}>
                <TextField
                  multiline
                  minRows={3}
                  size="medium"
                  placeholder="Add note (max 1000 chars)"
                  value={noteText}
                  onChange={(event) => setNoteText(event.target.value)}
                  inputProps={{ maxLength: 1000 }}
                />
                <Typography variant="caption" color="text.secondary">{noteText.length}/1000</Typography>
                <MuiButton type="submit" variant="contained" disabled={sending || !noteText.trim()}>
                  {sending ? "Saving..." : "Add Note"}
                </MuiButton>
              </Box>
            ) : null}

            {actionTab === "callback" ? (
              <Box component="form" onSubmit={onScheduleCallback} sx={{ display: "grid", gap: 1 }}>
                <Stack direction={{ xs: "column", md: "row" }} spacing={1}>
                  <TextField size="medium" type="date" value={callbackDate} onChange={(event) => setCallbackDate(event.target.value)} fullWidth />
                  <TextField size="medium" type="time" value={callbackTime} onChange={(event) => setCallbackTime(event.target.value)} fullWidth />
                </Stack>
                <TextField
                  size="medium"
                  multiline
                  minRows={2}
                  placeholder="Optional notes"
                  value={callbackNotes}
                  onChange={(event) => setCallbackNotes(event.target.value)}
                />
                <MuiButton type="submit" variant="contained" disabled={sending}>
                  {sending ? "Scheduling..." : "Schedule Callback"}
                </MuiButton>
              </Box>
            ) : null}

            {actionTab === "sms" ? (
              <Box component="form" onSubmit={(event) => void onSendMessage(event, "sms")} sx={{ display: "grid", gap: 1 }}>
                <TextField
                  multiline
                  minRows={3}
                  size="medium"
                  value={smsText}
                  onChange={(event) => setSmsText(event.target.value)}
                  placeholder="Send SMS to this lead..."
                />
                <MuiButton type="submit" variant="contained" disabled={sending || !smsText.trim()}>
                  {sending ? "Sending..." : "Send SMS"}
                </MuiButton>
              </Box>
            ) : null}

            {actionTab === "whatsapp" ? (
              <Box component="form" onSubmit={(event) => void onSendMessage(event, "whatsapp")} sx={{ display: "grid", gap: 1 }}>
                <TextField
                  multiline
                  minRows={3}
                  size="medium"
                  value={whatsappText}
                  onChange={(event) => setWhatsappText(event.target.value)}
                  placeholder="Send WhatsApp message to this lead..."
                />
                <MuiButton type="submit" variant="contained" disabled={sending || !whatsappText.trim()}>
                  {sending ? "Sending..." : "Send WhatsApp"}
                </MuiButton>
              </Box>
            ) : null}
          </Paper>

          <Box sx={{ mb: 1.5, display: "flex", flexWrap: "wrap", gap: 1 }}>
            <TextField
              select
              size="medium"
              value={timelineFilter}
              onChange={(event) => setTimelineFilter(event.target.value)}
              sx={{ minWidth: 180 }}
            >
              <MenuItem value="">All events</MenuItem>
              <MenuItem value="call">Calls</MenuItem>
              <MenuItem value="sms">SMS</MenuItem>
              <MenuItem value="whatsapp">WhatsApp</MenuItem>
              <MenuItem value="note">Notes</MenuItem>
              <MenuItem value="callback">Callbacks</MenuItem>
            </TextField>
            <MuiButton variant="outlined" onClick={() => void loadLeadTimeline()} disabled={loading}>
              Refresh
            </MuiButton>
          </Box>
          {loading ? (
            <LoadingState label="Loading timeline..." />
          ) : filteredTimeline.length === 0 ? (
            <EmptyPanel title="No timeline events" description="No interactions are recorded for this lead yet." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto" }}>
              <Table size="medium" sx={{ minWidth: 900 }}>
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Type</TableCell>
                    <TableCell>Direction</TableCell>
                    <TableCell>Content / Disposition</TableCell>
                    <TableCell>Agent</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>At</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {filteredTimeline.map((entry) => (
                    <TableRow key={`${entry.type}-${entry.id}`}>
                      <TableCell sx={{ textTransform: "capitalize" }}>{entry.type}</TableCell>
                      <TableCell>{entry.direction ?? "-"}</TableCell>
                      <TableCell>
                        {entry.content || entry.disposition || "-"}
                        {entry.recording_url ? (
                          <Typography variant="caption" display="block" color="text.secondary">
                            Recording available
                          </Typography>
                        ) : null}
                      </TableCell>
                      <TableCell>{entry.agent ?? "System"}</TableCell>
                      <TableCell>{entry.status ? <StatusBadge label={entry.status} /> : "-"}</TableCell>
                      <TableCell>{entry.at ? new Date(entry.at).toLocaleString() : "-"}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          )}
        </SectionCard>

        <SectionCard title="Lead Context" subtitle="Profile, follow-up, and outbound actions.">
          {lead ? (
            <Stack spacing={1.2}>
              <Typography variant="body2"><Typography component="span" color="text.secondary">Name:</Typography> {lead.full_name}</Typography>
              <Typography variant="body2"><Typography component="span" color="text.secondary">Phone:</Typography> {lead.phone}</Typography>
              <Typography variant="body2"><Typography component="span" color="text.secondary">Status:</Typography> <StatusBadge label={lead.status} /></Typography>
              <Typography variant="body2"><Typography component="span" color="text.secondary">Owner:</Typography> {lead.owner_agent}</Typography>
              <Typography variant="body2"><Typography component="span" color="text.secondary">Due callbacks:</Typography> {callbacksCount}</Typography>
              <Typography variant="body2"><Typography component="span" color="text.secondary">Tags:</Typography> {lead.tags.join(", ") || "None"}</Typography>
            </Stack>
          ) : (
            <LoadingState label="Loading lead profile..." />
          )}
        </SectionCard>
      </Box>
    </AppShell>
  );
}
