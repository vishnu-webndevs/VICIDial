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
  IconButton,
} from "@/ui";
import { Tooltip } from "@mui/material";
import { AppShell, EmptyState, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import { 
  createMessageTemplate, deleteMessageTemplate, listMessageTemplates, updateMessageTemplate,
  createMetaTemplate, listMetaTemplates, syncMetaTemplates, updateMetaTemplate, deleteMetaTemplate
} from "@/lib/product-api";
import type { MessageTemplate, MetaWhatsappTemplate } from "@/types/product";

type PopupState = "create_sms" | "edit_sms" | "create_whatsapp" | "edit_whatsapp" | null;

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

function renderMetaPreview(body: string): string {
  return body.replace(/\{\{(\d+)\}\}/g, (_, num: string) => `[Var ${num}]`);
}

export default function TemplatesPage() {
  const [smsTemplates, setSmsTemplates] = useState<MessageTemplate[]>([]);
  const [metaTemplates, setMetaTemplates] = useState<MetaWhatsappTemplate[]>([]);
  
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<{ tone: "success" | "error"; message: string } | null>(null);

  const [channel, setChannel] = useState<"sms" | "whatsapp">("sms");
  const [category, setCategory] = useState("");
  const [query, setQuery] = useState("");
  const [activeOnly, setActiveOnly] = useState(true);

  const [popup, setPopup] = useState<PopupState>(null);
  const [editingSms, setEditingSms] = useState<MessageTemplate | null>(null);
  const [editingMeta, setEditingMeta] = useState<MetaWhatsappTemplate | null>(null);
  
  const [smsForm, setSmsForm] = useState({ category: "", key: "", name: "", body: "", is_active: true });
  
  const [metaForm, setMetaForm] = useState({
    name: "",
    category: "MARKETING",
    language: "en",
    header_type: "NONE" as "NONE" | "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT",
    header_content: "",
    header_file: null as File | null,
    body: "",
    footer: "",
    buttons: [] as any[],
  });

  const smsPreview = useMemo(() => renderPreview(smsForm.body, SAMPLE_VARS), [smsForm.body]);
  const metaPreview = useMemo(() => renderMetaPreview(metaForm.body), [metaForm.body]);

  const loadSms = useCallback(async () => {
    const items = await listMessageTemplates({
      channel: "sms",
      category: category.trim() || undefined,
      q: query.trim() || undefined,
      active: activeOnly ? true : undefined,
    });
    setSmsTemplates(items);
  }, [activeOnly, category, query]);

  const loadMeta = useCallback(async () => {
    const items = await listMetaTemplates();
    setMetaTemplates(items);
  }, []);

  const loadAll = useCallback(async () => {
    setLoading(true);
    try {
      if (channel === "sms") {
        await loadSms();
      } else {
        await loadMeta();
      }
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to load templates." });
    } finally {
      setLoading(false);
    }
  }, [channel, loadSms, loadMeta]);

  useEffect(() => {
    void loadAll();
  }, [loadAll]);

  function openCreate() {
    if (channel === "sms") {
      setEditingSms(null);
      setSmsForm({ category, key: "", name: "", body: "", is_active: true });
      setPopup("create_sms");
    } else {
      setMetaForm({
        name: "", category: "MARKETING", language: "en", header_type: "NONE", header_content: "", header_file: null, body: "", footer: "", buttons: []
      });
      setPopup("create_whatsapp");
    }
  }

  function openEditSms(item: MessageTemplate) {
    setEditingSms(item);
    setSmsForm({
      category: item.category ?? "",
      key: item.key,
      name: item.name,
      body: item.body,
      is_active: item.is_active,
    });
    setPopup("edit_sms");
  }

  async function onSaveSms() {
    if (!smsForm.key.trim() && popup === "create_sms") {
      setToast({ tone: "error", message: "Template key is required." });
      return;
    }
    if (!smsForm.name.trim() || !smsForm.body.trim()) {
      setToast({ tone: "error", message: "Name and body are required." });
      return;
    }

    setSaving(true);
    try {
      if (popup === "create_sms") {
        await createMessageTemplate({
          channel: "sms",
          category: smsForm.category.trim() || null,
          key: smsForm.key.trim(),
          name: smsForm.name.trim(),
          body: smsForm.body,
          is_active: smsForm.is_active,
        });
        setToast({ tone: "success", message: "SMS Template created." });
      } else if (popup === "edit_sms" && editingSms) {
        await updateMessageTemplate(editingSms.id, {
          name: smsForm.name.trim(),
          body: smsForm.body,
          is_active: smsForm.is_active,
          category: smsForm.category.trim() || null,
        });
        setToast({ tone: "success", message: "SMS Template updated." });
      }
      setPopup(null);
      setEditingSms(null);
      await loadSms();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to save template." });
    } finally {
      setSaving(false);
    }
  }

  function openEditMeta(item: MetaWhatsappTemplate) {
    setEditingMeta(item);
    
    // Parse buttons from component
    let parsedButtons: any[] = [];
    if (item.components && Array.isArray(item.components)) {
      const buttonComponent = item.components.find((c: any) => c.type === "BUTTONS");
      if (buttonComponent && Array.isArray(buttonComponent.buttons)) {
        parsedButtons = buttonComponent.buttons.map((b: any) => {
          const btn: any = { type: b.type, text: b.text };
          if (b.type === "URL") btn.url = b.url;
          if (b.type === "PHONE_NUMBER") btn.phone_number = b.phone_number;
          return btn;
        });
      }
    }

    setMetaForm({
      name: item.template_name,
      category: item.category ?? "MARKETING",
      language: item.language ?? "en",
      header_type: (item.header_type as "NONE" | "TEXT" | "IMAGE" | "VIDEO" | "DOCUMENT") ?? "NONE",
      header_content: item.header_content ?? "",
      header_file: null,
      body: item.body ?? "",
      footer: item.footer ?? "",
      buttons: parsedButtons,
    });
    setPopup("edit_whatsapp");
  }

  async function onSaveMeta() {
    if (!metaForm.name.trim() || !metaForm.body.trim()) {
      setToast({ tone: "error", message: "Name and body are required." });
      return;
    }
    if (metaForm.header_type !== "NONE" && !metaForm.header_content.trim() && !metaForm.header_file) {
      setToast({ tone: "error", message: "Header content or file is required for " + metaForm.header_type });
      return;
    }

    setSaving(true);
    try {
      const payload = {
        name: metaForm.name.toLowerCase().replace(/[^a-z0-9_]/g, "_"),
        category: metaForm.category,
        language: metaForm.language,
        header_type: metaForm.header_type,
        header_content: metaForm.header_content.trim() || null,
        header_file: metaForm.header_file,
        body: metaForm.body,
        footer: metaForm.footer.trim() || null,
        buttons: metaForm.buttons.length > 0 ? metaForm.buttons : null,
      };

      if (popup === "edit_whatsapp" && editingMeta) {
        await updateMetaTemplate(editingMeta.id, payload);
        setToast({ tone: "success", message: "Meta Template updated successfully." });
      } else {
        await createMetaTemplate(payload);
        setToast({ tone: "success", message: "Meta Template created successfully." });
      }
      
      setPopup(null);
      setEditingMeta(null);
      await loadMeta();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to save Meta template." });
    } finally {
      setSaving(false);
    }
  }

  async function onDeleteMeta(item: MetaWhatsappTemplate) {
    if (!window.confirm(`Are you sure you want to delete the Meta template "${item.template_name}"?`)) return;
    
    setSaving(true);
    try {
      await deleteMetaTemplate(item.id);
      setToast({ tone: "success", message: "Meta Template deleted." });
      await loadMeta();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to delete Meta template." });
    } finally {
      setSaving(false);
    }
  }

  async function onDeleteSms(item: MessageTemplate) {
    setSaving(true);
    try {
      await deleteMessageTemplate(item.id);
      setToast({ tone: "success", message: "Template deleted." });
      await loadSms();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to delete template." });
    } finally {
      setSaving(false);
    }
  }

  async function handleSyncMeta() {
    setSaving(true);
    try {
      await syncMetaTemplates();
      setToast({ tone: "success", message: "Synced templates with Meta." });
      await loadMeta();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to sync." });
    } finally {
      setSaving(false);
    }
  }

  function addMetaButton() {
    setMetaForm((prev) => ({
      ...prev,
      buttons: [...prev.buttons, { type: "QUICK_REPLY", text: "" }],
    }));
  }

  function updateMetaButton(index: number, updates: any) {
    setMetaForm((prev) => {
      const newBtns = [...prev.buttons];
      newBtns[index] = { ...newBtns[index], ...updates };
      return { ...prev, buttons: newBtns };
    });
  }

  function removeMetaButton(index: number) {
    setMetaForm((prev) => {
      const newBtns = [...prev.buttons];
      newBtns.splice(index, 1);
      return { ...prev, buttons: newBtns };
    });
  }

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      {toast ? <ToastMessage tone={toast.tone} message={toast.message} /> : null}

      <SectionCard title="Templates" subtitle="Manage reusable message templates for your campaigns.">
        <Box sx={{ mt: 1, mb: 4, display: "flex", gap: 1.5, flexWrap: "wrap" }}>
          <MuiButton
            variant="outlined"
            size="small"
            component="a"
            href="/settings/whatsapp-integration"
            sx={{ textTransform: "none" }}
          >
            Configure WhatsApp Settings
          </MuiButton>
          <MuiButton
            variant="outlined"
            size="small"
            component="a"
            href="/campaigns"
            sx={{ textTransform: "none" }}
          >
            Go to Campaigns
          </MuiButton>
        </Box>
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(4, 1fr)" }, alignItems: "end" }}>
          <TextField select size="medium" label="Channel" value={channel} onChange={(e) => setChannel(e.target.value as "sms" | "whatsapp")}>
            <MenuItem value="sms">SMS</MenuItem>
            <MenuItem value="whatsapp">Meta WhatsApp</MenuItem>
          </TextField>
          
          {channel === "sms" && (
            <>
              <TextField size="medium" label="Category (optional)" value={category} onChange={(e) => setCategory(e.target.value)} placeholder="outreach" />
              <TextField size="medium" label="Search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="key, name, body" />
              <FormControlLabel
                control={<Checkbox checked={activeOnly} onChange={(e) => setActiveOnly(e.target.checked)} />}
                label="Active only"
                sx={{ m: 0 }}
              />
            </>
          )}
        </Box>

        <Stack direction="row" spacing={1} sx={{ mt: 2, mb: 3 }}>
          <MuiButton variant="outlined" onClick={() => void loadAll()} disabled={loading || saving}>
            Refresh
          </MuiButton>
          <MuiButton variant="contained" onClick={openCreate} disabled={saving}>
            New Template
          </MuiButton>
          {channel === "whatsapp" && (
            <MuiButton variant="outlined" onClick={handleSyncMeta} disabled={saving}>
              Sync from Meta
            </MuiButton>
          )}
        </Stack>

        {loading ? (
          <LoadingState label="Loading templates..." />
        ) : channel === "sms" ? (
          smsTemplates.length === 0 ? (
            <EmptyState title="No SMS templates" description="Create an SMS template to reuse across campaigns." />
          ) : (
            <Paper variant="outlined" sx={{ mt: 2, overflowX: "auto" }}>
              <Table size="medium">
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Key</TableCell>
                    <TableCell>Name</TableCell>
                    <TableCell>Category</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell align="right">Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {smsTemplates.map((item) => (
                    <TableRow hover key={item.id}>
                      <TableCell>{item.key}</TableCell>
                      <TableCell sx={{ maxWidth: 340, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{item.name}</TableCell>
                      <TableCell>{item.category || "-"}</TableCell>
                      <TableCell><StatusBadge label={item.is_active ? "active" : "inactive"} /></TableCell>
                      <TableCell align="right">
                        <Stack direction="row" spacing={1} justifyContent="flex-end">
                          <MuiButton variant="outlined" size="medium" onClick={() => openEditSms(item)} disabled={saving}>
                            Edit
                          </MuiButton>
                          <MuiButton variant="outlined" color="error" size="medium" onClick={() => void onDeleteSms(item)} disabled={saving}>
                            Delete
                          </MuiButton>
                        </Stack>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          )
        ) : metaTemplates.length === 0 ? (
          <EmptyState title="No Meta Templates" description="Create a Meta WhatsApp Template to send broadcasts." />
        ) : (
          <Paper variant="outlined" sx={{ mt: 2, overflowX: "auto" }}>
            <Table size="medium">
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell>Name</TableCell>
                  <TableCell>Category</TableCell>
                  <TableCell>Language</TableCell>
                  <TableCell>Header</TableCell>
                  <TableCell>Meta Status</TableCell>
                  <TableCell align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {metaTemplates.map((item) => (
                  <TableRow hover key={item.id}>
                    <TableCell>{item.template_name}</TableCell>
                    <TableCell>{item.category}</TableCell>
                    <TableCell>{item.language}</TableCell>
                    <TableCell>{item.header_type}</TableCell>
                    <TableCell>
                      <Tooltip title={item.rejection_reason || ""} placement="top">
                        <Box sx={{ display: "inline-block" }}>
                          <StatusBadge 
                            label={item.status} 
                            color={item.status === 'APPROVED' ? 'success' : item.status === 'REJECTED' ? 'error' : 'warning'} 
                          />
                        </Box>
                      </Tooltip>
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={1} justifyContent="flex-end">
                        <MuiButton variant="outlined" size="medium" onClick={() => openEditMeta(item)} disabled={saving}>
                          Edit
                        </MuiButton>
                        <MuiButton variant="outlined" color="error" size="medium" onClick={() => void onDeleteMeta(item)} disabled={saving}>
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

      {/* SMS Modal */}
      <Modal
        open={popup === "create_sms" || popup === "edit_sms"}
        onClose={() => { setPopup(null); setEditingSms(null); }}
        title={popup === "edit_sms" ? "Edit SMS Template" : "New SMS Template"}
      >
        <Box sx={{ display: "grid", gap: 1.25 }}>
          <TextField size="medium" label="Category (optional)" value={smsForm.category} onChange={(e) => setSmsForm((p) => ({ ...p, category: e.target.value }))} fullWidth />
          <TextField size="medium" label="Key" value={smsForm.key} onChange={(e) => setSmsForm((p) => ({ ...p, key: e.target.value }))} placeholder="welcome_01" disabled={popup === "edit_sms"} />
          <TextField size="medium" label="Name" value={smsForm.name} onChange={(e) => setSmsForm((p) => ({ ...p, name: e.target.value }))} placeholder="Welcome Message" />
          <TextField
            size="medium"
            label="Body"
            value={smsForm.body}
            onChange={(e) => setSmsForm((p) => ({ ...p, body: e.target.value }))}
            multiline
            minRows={6}
            placeholder={"Hi {{first_name}},\n\nThis is {{company_name}}. Reply YES to confirm."}
          />
          <FormControlLabel
            control={<Checkbox checked={smsForm.is_active} onChange={(e) => setSmsForm((p) => ({ ...p, is_active: e.target.checked }))} />}
            label="Active"
            sx={{ m: 0 }}
          />

          <Paper variant="outlined" sx={{ p: 1.5 }}>
            <Typography variant="caption" color="text.secondary">Live Preview</Typography>
            <Typography variant="body2" sx={{ mt: 0.75, whiteSpace: "pre-wrap" }}>{smsPreview || "-"}</Typography>
          </Paper>

          <Stack direction="row" spacing={1} justifyContent="flex-end">
            <MuiButton variant="outlined" onClick={() => setPopup(null)} disabled={saving}>Cancel</MuiButton>
            <MuiButton variant="contained" onClick={() => void onSaveSms()} disabled={saving}>
              {saving ? "Saving..." : popup === "edit_sms" ? "Save" : "Create"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>

      {/* WhatsApp Modal */}
      <Modal
        open={popup === "create_whatsapp" || popup === "edit_whatsapp"}
        onClose={() => { setPopup(null); setEditingMeta(null); }}
        title={popup === "edit_whatsapp" ? "Edit Meta WhatsApp Template" : "New Meta WhatsApp Template"}
      >
        <Box sx={{ display: "grid", gap: 1.5 }}>
          <Stack direction={{ xs: "column", md: "row" }} spacing={1}>
            <TextField size="medium" label="Name (e.g. seasonal_promo)" value={metaForm.name} onChange={(e) => setMetaForm((p) => ({ ...p, name: e.target.value }))} fullWidth disabled={popup === "edit_whatsapp"} />
            <TextField select size="medium" label="Category" value={metaForm.category} onChange={(e) => setMetaForm((p) => ({ ...p, category: e.target.value }))} fullWidth>
              <MenuItem value="MARKETING">Marketing</MenuItem>
              <MenuItem value="UTILITY">Utility</MenuItem>
              <MenuItem value="AUTHENTICATION">Authentication</MenuItem>
            </TextField>
          </Stack>

          <Stack direction={{ xs: "column", md: "row" }} spacing={1}>
            <TextField select size="medium" label="Language" value={metaForm.language} onChange={(e) => setMetaForm((p) => ({ ...p, language: e.target.value }))} fullWidth disabled={popup === "edit_whatsapp"}>
              <MenuItem value="en">English (en)</MenuItem>
              <MenuItem value="en_US">English (US)</MenuItem>
              <MenuItem value="es">Spanish (es)</MenuItem>
            </TextField>
            <TextField select size="medium" label="Header Type" value={metaForm.header_type} onChange={(e) => setMetaForm((p) => ({ ...p, header_type: e.target.value as any }))} fullWidth>
              <MenuItem value="NONE">None</MenuItem>
              <MenuItem value="TEXT">Text</MenuItem>
              <MenuItem value="IMAGE">Image</MenuItem>
              <MenuItem value="VIDEO">Video</MenuItem>
              <MenuItem value="DOCUMENT">Document</MenuItem>
            </TextField>
          </Stack>

          {metaForm.header_type !== "NONE" && (
            <Box>
              <TextField 
                size="medium" 
                label={metaForm.header_type === "TEXT" ? "Header Text" : "Media URL"} 
                value={metaForm.header_content} 
                onChange={(e) => setMetaForm((p) => ({ ...p, header_content: e.target.value, header_file: null }))} 
                placeholder={metaForm.header_type === "TEXT" ? "Hello" : "https://example.com/image.png"}
                fullWidth
                disabled={!!metaForm.header_file}
              />
              {metaForm.header_type !== "TEXT" && (
                <>
                  <Typography variant="caption" color="text.secondary" sx={{ display: "block", textAlign: "center", my: 1 }}>
                    - OR -
                  </Typography>
                  <MuiButton variant="outlined" component="label" fullWidth sx={{ justifyContent: "flex-start", textTransform: "none" }}>
                    <input
                      type="file"
                      hidden
                      accept={metaForm.header_type === "IMAGE" ? "image/jpeg,image/png" : metaForm.header_type === "VIDEO" ? "video/mp4" : "application/pdf"}
                      onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) {
                          setMetaForm((p) => ({ ...p, header_file: file, header_content: "" }));
                        }
                      }}
                    />
                    Choose File: {metaForm.header_file ? metaForm.header_file.name : "No file chosen"}
                  </MuiButton>
                  {metaForm.header_file && (
                    <MuiButton variant="text" size="small" color="error" onClick={() => setMetaForm((p) => ({ ...p, header_file: null }))}>
                      Remove File
                    </MuiButton>
                  )}
                </>
              )}
            </Box>
          )}

          <TextField
            size="medium"
            label="Body"
            value={metaForm.body}
            onChange={(e) => setMetaForm((p) => ({ ...p, body: e.target.value }))}
            multiline
            minRows={4}
            placeholder={"Hi {{1}}, this is your order update: {{2}}"}
            helperText="Use {{1}}, {{2}} for variables."
          />

          <TextField
            size="medium"
            label="Footer (optional)"
            value={metaForm.footer}
            onChange={(e) => setMetaForm((p) => ({ ...p, footer: e.target.value }))}
          />

          <Box>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1 }}>
              <Typography variant="subtitle2">Buttons</Typography>
              <MuiButton variant="text" size="small" onClick={addMetaButton} disabled={metaForm.buttons.length >= 3}>
                + Add Button
              </MuiButton>
            </Stack>
            {metaForm.buttons.map((btn, index) => (
              <Stack key={index} direction="row" spacing={1} sx={{ mb: 1 }} alignItems="center">
                <TextField select size="small" value={btn.type} onChange={(e) => updateMetaButton(index, { type: e.target.value })} sx={{ width: 150 }}>
                  <MenuItem value="QUICK_REPLY">Quick Reply</MenuItem>
                  <MenuItem value="URL">Visit Website</MenuItem>
                  <MenuItem value="PHONE_NUMBER">Call Number</MenuItem>
                </TextField>
                <TextField size="small" placeholder="Button Text" value={btn.text} onChange={(e) => updateMetaButton(index, { text: e.target.value })} fullWidth />
                {btn.type === "URL" && (
                  <TextField size="small" placeholder="https://..." value={btn.url || ""} onChange={(e) => updateMetaButton(index, { url: e.target.value })} fullWidth />
                )}
                {btn.type === "PHONE_NUMBER" && (
                  <TextField size="small" placeholder="+1234567890" value={btn.phone_number || ""} onChange={(e) => updateMetaButton(index, { phone_number: e.target.value })} fullWidth />
                )}
                <MuiButton variant="text" color="error" size="small" onClick={() => removeMetaButton(index)}>X</MuiButton>
              </Stack>
            ))}
          </Box>

          <Paper variant="outlined" sx={{ p: 1.5, bgcolor: "action.hover" }}>
            <Typography variant="caption" color="text.secondary">Body Preview</Typography>
            <Typography variant="body2" sx={{ mt: 0.75, whiteSpace: "pre-wrap" }}>{metaPreview || "-"}</Typography>
          </Paper>

          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 1 }}>
            <MuiButton variant="outlined" onClick={() => setPopup(null)} disabled={saving}>Cancel</MuiButton>
            <MuiButton variant="contained" onClick={() => void onSaveMeta()} disabled={saving}>
              {saving ? "Submitting to Meta..." : popup === "edit_whatsapp" ? "Update Template" : "Create Template"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>

    </AppShell>
  );
}
