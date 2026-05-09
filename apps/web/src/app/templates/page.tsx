"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  Box,
  Checkbox,
  FormControlLabel,
  MenuItem,
  Modal,
  MuiButton,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@/ui";
import { AppShell, EmptyState, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import { createMessageTemplate, deleteMessageTemplate, listMessageTemplates, updateMessageTemplate } from "@/lib/product-api";
import type { MessageTemplate } from "@/types/product";

type PopupState = "create" | "edit" | null;

const SAMPLE_VARS: Record<string, string> = {
  first_name: "John",
  last_name: "Doe",
  company_name: "Acme Inc",
  phone: "+15551234567",
  email: "john.doe@example.com",
  campaign_name: "Spring Campaign",
  agent_name: "Agent 01",
};

function renderPreview(template: string, variables: Record<string, string>): string {
  return template.replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g, (_, key: string) => String(variables[key] ?? ""));
}

export default function TemplatesPage() {
  const [templates, setTemplates] = useState<MessageTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ tone: "success" | "error"; message: string } | null>(null);

  const [channel, setChannel] = useState<"sms" | "whatsapp">("sms");
  const [category, setCategory] = useState("");
  const [query, setQuery] = useState("");
  const [activeOnly, setActiveOnly] = useState(true);

  const [popup, setPopup] = useState<PopupState>(null);
  const [editing, setEditing] = useState<MessageTemplate | null>(null);
  const [form, setForm] = useState<{ channel: "sms" | "whatsapp"; category: string; key: string; name: string; body: string; is_active: boolean }>({
    channel: "sms",
    category: "",
    key: "",
    name: "",
    body: "",
    is_active: true,
  });

  const preview = useMemo(() => renderPreview(form.body, SAMPLE_VARS), [form.body]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const items = await listMessageTemplates({
        channel,
        category: category.trim() || undefined,
        q: query.trim() || undefined,
        active: activeOnly ? true : undefined,
      });
      setTemplates(items);
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to load templates." });
    } finally {
      setLoading(false);
    }
  }, [activeOnly, category, channel, query]);

  useEffect(() => {
    void load();
  }, [load]);

  function openCreate() {
    setEditing(null);
    setForm({ channel, category, key: "", name: "", body: "", is_active: true });
    setPopup("create");
  }

  function openEdit(item: MessageTemplate) {
    setEditing(item);
    setForm({
      channel: item.channel,
      category: item.category ?? "",
      key: item.key,
      name: item.name,
      body: item.body,
      is_active: item.is_active,
    });
    setPopup("edit");
  }

  async function onSave() {
    if (!form.key.trim() && popup === "create") {
      setToast({ tone: "error", message: "Template key is required." });
      return;
    }
    if (!form.name.trim()) {
      setToast({ tone: "error", message: "Template name is required." });
      return;
    }
    if (!form.body.trim()) {
      setToast({ tone: "error", message: "Template body is required." });
      return;
    }

    setSaving(true);
    try {
      if (popup === "create") {
        await createMessageTemplate({
          channel: form.channel,
          category: form.category.trim() || null,
          key: form.key.trim(),
          name: form.name.trim(),
          body: form.body,
          is_active: form.is_active,
        });
        setToast({ tone: "success", message: "Template created." });
      } else if (popup === "edit" && editing) {
        await updateMessageTemplate(editing.id, {
          name: form.name.trim(),
          body: form.body,
          is_active: form.is_active,
          category: form.category.trim() || null,
        });
        setToast({ tone: "success", message: "Template updated." });
      }
      setPopup(null);
      setEditing(null);
      await load();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to save template." });
    } finally {
      setSaving(false);
    }
  }

  async function onDelete(item: MessageTemplate) {
    setSaving(true);
    try {
      await deleteMessageTemplate(item.id);
      setToast({ tone: "success", message: "Template deleted." });
      await load();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to delete template." });
    } finally {
      setSaving(false);
    }
  }

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      {toast ? <ToastMessage tone={toast.tone} message={toast.message} /> : null}

      <SectionCard title="Templates" subtitle="Reusable message templates for SMS, WhatsApp, and Outreach workflows.">
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(4, 1fr)" }, alignItems: "end" }}>
          <TextField select size="medium" label="Channel" value={channel} onChange={(e) => setChannel(e.target.value as "sms" | "whatsapp")}>
            <MenuItem value="sms">SMS</MenuItem>
            <MenuItem value="whatsapp">WhatsApp</MenuItem>
          </TextField>
          <TextField size="medium" label="Category (optional)" value={category} onChange={(e) => setCategory(e.target.value)} placeholder="outreach" />
          <TextField size="medium" label="Search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="key, name, body" />
          <FormControlLabel
            control={<Checkbox checked={activeOnly} onChange={(e) => setActiveOnly(e.target.checked)} />}
            label="Active only"
            sx={{ m: 0 }}
          />
        </Box>

        <Stack direction="row" spacing={1} sx={{ mt: 2 }}>
          <MuiButton variant="outlined" onClick={() => void load()} disabled={loading || saving}>
            Refresh
          </MuiButton>
          <MuiButton variant="contained" onClick={openCreate} disabled={saving}>
            New Template
          </MuiButton>
        </Stack>

        {loading ? (
          <LoadingState label="Loading templates..." />
        ) : templates.length === 0 ? (
          <EmptyState title="No templates" description="Create a template to reuse across campaigns." />
        ) : (
          <Paper variant="outlined" sx={{ mt: 2, overflowX: "auto" }}>
            <Table size="medium">
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell>Key</TableCell>
                  <TableCell>Name</TableCell>
                  <TableCell>Channel</TableCell>
                  <TableCell>Category</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {templates.map((item) => (
                  <TableRow hover key={item.id}>
                    <TableCell>{item.key}</TableCell>
                    <TableCell sx={{ maxWidth: 340, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{item.name}</TableCell>
                    <TableCell>{item.channel}</TableCell>
                    <TableCell>{item.category || "-"}</TableCell>
                    <TableCell><StatusBadge label={item.is_active ? "active" : "inactive"} /></TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={1} justifyContent="flex-end">
                        <MuiButton variant="outlined" size="medium" onClick={() => openEdit(item)} disabled={saving}>
                          Edit
                        </MuiButton>
                        <MuiButton variant="outlined" color="error" size="medium" onClick={() => void onDelete(item)} disabled={saving}>
                          Delete
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

      <Modal
        open={popup !== null}
        onClose={() => {
          setPopup(null);
          setEditing(null);
        }}
        title={popup === "edit" ? "Edit Template" : "New Template"}
      >
        <Box sx={{ display: "grid", gap: 1.25 }}>
          <Stack direction={{ xs: "column", md: "row" }} spacing={1}>
            <TextField select size="medium" label="Channel" value={form.channel} onChange={(e) => setForm((p) => ({ ...p, channel: e.target.value as "sms" | "whatsapp" }))} fullWidth disabled={popup === "edit"}>
              <MenuItem value="sms">SMS</MenuItem>
              <MenuItem value="whatsapp">WhatsApp</MenuItem>
            </TextField>
            <TextField size="medium" label="Category (optional)" value={form.category} onChange={(e) => setForm((p) => ({ ...p, category: e.target.value }))} fullWidth />
          </Stack>

          <TextField size="medium" label="Key" value={form.key} onChange={(e) => setForm((p) => ({ ...p, key: e.target.value }))} placeholder="welcome_01" disabled={popup === "edit"} />
          <TextField size="medium" label="Name" value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))} placeholder="Welcome Message" />

          <TextField
            size="medium"
            label="Body"
            value={form.body}
            onChange={(e) => setForm((p) => ({ ...p, body: e.target.value }))}
            multiline
            minRows={6}
            placeholder={"Hi {{first_name}},\n\nThis is {{company_name}}. Reply YES to confirm."}
          />

          <FormControlLabel
            control={<Checkbox checked={form.is_active} onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))} />}
            label="Active"
            sx={{ m: 0 }}
          />

          <Paper variant="outlined" sx={{ p: 1.5 }}>
            <Typography variant="caption" color="text.secondary">Live Preview</Typography>
            <Typography variant="body2" sx={{ mt: 0.75, whiteSpace: "pre-wrap" }}>{preview || "-"}</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: "block" }}>
              Supported variables: {"{{first_name}}"} {"{{last_name}}"} {"{{company_name}}"} {"{{phone}}"} {"{{email}}"} {"{{campaign_name}}"} {"{{agent_name}}"}
            </Typography>
          </Paper>

          <Stack direction="row" spacing={1} justifyContent="flex-end">
            <MuiButton variant="outlined" onClick={() => setPopup(null)} disabled={saving}>
              Cancel
            </MuiButton>
            <MuiButton variant="contained" onClick={() => void onSave()} disabled={saving}>
              {saving ? "Saving..." : popup === "edit" ? "Save" : "Create"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>
    </AppShell>
  );
}

