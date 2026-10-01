"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  Box,
  CircularProgress,
  Divider,
  Drawer,
  IconButton,
  MuiButton,
  Paper,
  Stack,
  TextField,
  Typography,
  Chip,
} from "@/ui";
import { apiRequest } from "@/lib/api";
import type { Lead } from "@/types/product";

interface LeadActivityDrawerProps {
  open: boolean;
  onClose: () => void;
  lead: Lead | null;
  onLeadUpdated?: () => void;
}

export interface TimelineItem {
  id: string;
  type: "call" | "whatsapp" | "sms" | "note" | "callback" | "disposition";
  direction?: "inbound" | "outbound";
  content?: string;
  duration?: number;
  disposition?: string;
  agent?: string;
  recording_url?: string;
  scheduled_at?: string;
  at: string;
}

type TimelineResponse = {
  data: TimelineItem[] | { timeline?: TimelineItem[] };
};

export function LeadActivityDrawer({
  open,
  onClose,
  lead,
  onLeadUpdated,
}: LeadActivityDrawerProps) {
  const [items, setItems] = useState<TimelineItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [noteText, setNoteText] = useState("");
  const [disposition, setDisposition] = useState("note");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open || !lead?.id) {
      setItems([]);
      return;
    }

    setLoading(true);
    setError("");

    const token = typeof window !== "undefined" ? localStorage.getItem("wnd_token") : null;
    const tenantId = typeof window !== "undefined" ? localStorage.getItem("wnd_tenant_id") : null;

    apiRequest<TimelineResponse>(`/leads/${lead.id}/timeline`, {
      token: token || undefined,
      tenantId: tenantId || undefined,
    })
      .then((res) => {
        let list: TimelineItem[] = [];
        if (Array.isArray(res.data)) {
          list = res.data;
        } else if (res.data && typeof res.data === "object" && Array.isArray((res.data as Record<string, unknown>).timeline)) {
          list = (res.data as Record<string, unknown>).timeline as TimelineItem[];
        }
        setItems(list);
      })
      .catch((err) => {
        setError(err instanceof Error ? err.message : "Failed to load timeline history.");
      })
      .finally(() => {
        setLoading(false);
      });
  }, [open, lead?.id]);

  async function handleAddNote(e: React.FormEvent) {
    e.preventDefault();
    if (!noteText.trim() || !lead?.id) return;

    setSubmitting(true);
    try {
      const token = typeof window !== "undefined" ? localStorage.getItem("wnd_token") : null;
      const tenantId = typeof window !== "undefined" ? localStorage.getItem("wnd_tenant_id") : null;

      await apiRequest("/leads/dispositions", {
        method: "POST",
        token: token || undefined,
        tenantId: tenantId || undefined,
        body: {
          lead_id: lead.id,
          disposition: disposition === "note" ? "interested" : disposition,
          notes: noteText.trim(),
        },
      });

      const newItem: TimelineItem = {
        id: "temp-" + Date.now(),
        type: "note",
        content: noteText.trim(),
        disposition: disposition !== "note" ? disposition : undefined,
        at: new Date().toISOString(),
        agent: "You",
      };

      setItems((prev) => [newItem, ...prev]);
      setNoteText("");
      onLeadUpdated?.();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to add note.");
    } finally {
      setSubmitting(false);
    }
  }

  function getEventIcon(type: TimelineItem["type"]) {
    switch (type) {
      case "call":
        return "📞";
      case "whatsapp":
        return "💬";
      case "sms":
        return "✉️";
      case "callback":
        return "📅";
      case "note":
      case "disposition":
      default:
        return "📝";
    }
  }

  function getEventBadgeColor(type: TimelineItem["type"]) {
    switch (type) {
      case "call":
        return "#696cff";
      case "whatsapp":
        return "#71dd37";
      case "sms":
        return "#03c3ec";
      case "callback":
        return "#ffab00";
      case "note":
      default:
        return "#8592a3";
    }
  }

  return (
    <Drawer
      anchor="right"
      open={open}
      onClose={onClose}
      PaperProps={{
        sx: {
          width: { xs: "100%", sm: 460 },
          display: "flex",
          flexDirection: "column",
        },
      }}
    >
      {/* Header */}
      <Box
        sx={{
          p: 3,
          bgcolor: "#f5f5f9",
          borderBottom: "1px solid #e7e7e8",
          display: "flex",
          justifyContent: "space-between",
          alignItems: "flex-start",
        }}
      >
        <Box>
          <Typography variant="h6" sx={{ fontWeight: 600, color: "#566a7f" }}>
            {lead?.full_name || "Lead Activity & History"}
          </Typography>
          <Typography variant="body2" sx={{ color: "#697a8d", mt: 0.25 }}>
            {lead?.company ? `${lead.company} • ` : ""}
            {lead?.phone || ""}
          </Typography>
          <Stack direction="row" spacing={1} sx={{ mt: 1.5 }} alignItems="center">
            {lead?.status && (
              <Chip
                label={lead.status.toUpperCase()}
                size="small"
                sx={{
                  bgcolor: "#e7e7ff",
                  color: "#696cff",
                  fontWeight: 600,
                  fontSize: "0.75rem",
                }}
              />
            )}
            {lead?.owner_agent && (
              <Typography variant="caption" sx={{ color: "#8592a3" }}>
                Assigned: <strong>{lead.owner_agent}</strong>
              </Typography>
            )}
          </Stack>
        </Box>
        <IconButton onClick={onClose} size="small" sx={{ color: "#8592a3" }}>
          ✕
        </IconButton>
      </Box>

      {/* Quick Actions Bar */}
      {lead?.phone && (
        <Box sx={{ p: 2, bgcolor: "#fff", borderBottom: "1px solid #e7e7e8" }}>
          <Stack direction="row" spacing={1.5}>
            <MuiButton
              component={Link}
              href={`/dialer?phone=${encodeURIComponent(lead.phone)}`}
              variant="contained"
              size="small"
              sx={{ flex: 1, bgcolor: "#696cff" }}
            >
              📞 Call Lead
            </MuiButton>
            <MuiButton
              component={Link}
              href={`/conversations?phone=${encodeURIComponent(lead.phone)}`}
              variant="outlined"
              size="small"
              sx={{ flex: 1, borderColor: "#71dd37", color: "#71dd37" }}
            >
              💬 WhatsApp
            </MuiButton>
          </Stack>
        </Box>
      )}

      {/* Timeline Stream */}
      <Box sx={{ flex: 1, overflowY: "auto", p: 3 }}>
        {loading ? (
          <Box sx={{ textAlign: "center", py: 6 }}>
            <CircularProgress size={28} sx={{ color: "#696cff", mb: 1.5 }} />
            <Typography variant="body2" sx={{ color: "#697a8d" }}>
              Loading activity timeline...
            </Typography>
          </Box>
        ) : error ? (
          <Typography variant="body2" sx={{ color: "#ff3e1d", p: 2, bgcolor: "#ffe7e3", borderRadius: 1 }}>
            {error}
          </Typography>
        ) : (!Array.isArray(items) || items.length === 0) ? (
          <Box sx={{ textAlign: "center", py: 6 }}>
            <Typography variant="body2" sx={{ color: "#8592a3", mb: 1 }}>
              📋 No previous activity logs recorded yet.
            </Typography>
            <Typography variant="caption" sx={{ color: "#a1acb8" }}>
              Notes, calls, and WhatsApp messages will appear here chronologically.
            </Typography>
          </Box>
        ) : (
          <Stack spacing={2}>
            {(Array.isArray(items) ? items : []).map((item) => (
              <Paper
                key={item.id}
                variant="outlined"
                sx={{
                  p: 2,
                  borderRadius: "0.375rem",
                  borderColor: "#e7e7e8",
                  position: "relative",
                  borderLeft: `4px solid ${getEventBadgeColor(item.type)}`,
                }}
              >
                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1 }}>
                  <Stack direction="row" spacing={1} alignItems="center">
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {getEventIcon(item.type)} {item.type.toUpperCase()}
                    </Typography>
                    {item.direction && (
                      <Chip
                        label={item.direction}
                        size="small"
                        sx={{
                          height: 18,
                          fontSize: "0.65rem",
                          bgcolor: item.direction === "inbound" ? "#e8fdf8" : "#e7e7ff",
                          color: item.direction === "inbound" ? "#71dd37" : "#696cff",
                        }}
                      />
                    )}
                    {item.disposition && (
                      <Chip
                        label={item.disposition}
                        size="small"
                        sx={{ height: 18, fontSize: "0.65rem" }}
                      />
                    )}
                  </Stack>
                  <Typography variant="caption" sx={{ color: "#a1acb8" }}>
                    {new Date(item.at).toLocaleString()}
                  </Typography>
                </Stack>

                {item.content && (
                  <Typography variant="body2" sx={{ color: "#566a7f", whitespace: "pre-wrap", mb: 0.5 }}>
                    {item.content}
                  </Typography>
                )}

                {item.duration !== undefined && item.duration > 0 && (
                  <Typography variant="caption" sx={{ color: "#697a8d", display: "block" }}>
                    Duration: {Math.floor(item.duration / 60)}m {item.duration % 60}s
                  </Typography>
                )}

                {item.recording_url && (
                  <Box sx={{ mt: 1 }}>
                    <audio controls src={item.recording_url} style={{ width: "100%", height: 32 }} />
                  </Box>
                )}

                {item.agent && (
                  <Typography variant="caption" sx={{ color: "#8592a3", mt: 1, display: "block" }}>
                    By: {item.agent}
                  </Typography>
                )}
              </Paper>
            ))}
          </Stack>
        )}
      </Box>

      <Divider />

      {/* Add Note Footer Form */}
      <Box component="form" onSubmit={handleAddNote} sx={{ p: 2.5, bgcolor: "#fff" }}>
        <Typography variant="subtitle2" sx={{ mb: 1, fontWeight: 600, color: "#566a7f" }}>
          📝 Add Note or Disposition
        </Typography>
        <Stack spacing={1.5}>
          <TextField
            multiline
            rows={2}
            size="small"
            placeholder="Type your call summary or note here..."
            value={noteText}
            onChange={(e) => setNoteText(e.target.value)}
            disabled={submitting}
            fullWidth
          />
          <Stack direction="row" spacing={1.5} justifyContent="flex-end">
            <MuiButton
              type="submit"
              variant="contained"
              size="small"
              disabled={submitting || !noteText.trim()}
              startIcon={submitting ? <CircularProgress size={14} color="inherit" /> : null}
              sx={{ bgcolor: "#696cff" }}
            >
              {submitting ? "Saving..." : "Save Note"}
            </MuiButton>
          </Stack>
        </Stack>
      </Box>
    </Drawer>
  );
}
