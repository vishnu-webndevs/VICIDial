"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Accordion, AccordionDetails, AccordionSummary, IconButton, InputAdornment } from "@mui/material";
import { Box, Checkbox, FormControlLabel, MuiButton, Paper, Stack, TextField, Typography } from "@/ui";
import { AppShell, EmptyState, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import { getWhatsAppIntegration, saveWhatsAppIntegration, testWhatsAppIntegration } from "@/lib/product-api";

type FormState = {
  enabled: boolean;
  display_name: string;
  meta_app_id: string;
  meta_app_secret: string;
  meta_access_token: string;
  whatsapp_business_account_id: string;
  phone_number_id: string;
  webhook_verify_token: string;
};

const defaultForm: FormState = {
  enabled: false,
  display_name: "Meta WhatsApp",
  meta_app_id: "",
  meta_app_secret: "",
  meta_access_token: "",
  whatsapp_business_account_id: "",
  phone_number_id: "",
  webhook_verify_token: "",
};

export default function WhatsAppIntegrationSettingsPage() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [toast, setToast] = useState<{ tone: "success" | "error"; message: string } | null>(null);
  const [advancedOpen, setAdvancedOpen] = useState(false);
  const [reveal, setReveal] = useState({ token: false, phoneNumberId: false, verifyToken: false });
  const [storedSecrets, setStoredSecrets] = useState({
    meta_access_token: false,
    webhook_verify_token: false,
    meta_app_secret: false,
  });
  const [storedSecretSuffix, setStoredSecretSuffix] = useState({
    meta_access_token: null as string | null,
    webhook_verify_token: null as string | null,
    meta_app_secret: null as string | null,
  });

  const [providerStatus, setProviderStatus] = useState<{ status: string; last_tested_at?: string | null; last_error_message?: string | null } | null>(null);
  const [form, setForm] = useState<FormState>(defaultForm);

  const webhookUrl = useMemo(() => {
    if (typeof window === "undefined") return "";
    return `${window.location.origin}/api/v1/webhooks/meta/whatsapp`;
  }, []);

  const missingRequired = useMemo(() => {
    const missing: string[] = [];
    if (!form.meta_access_token.trim() && !storedSecrets.meta_access_token) missing.push("Meta Access Token");
    if (!form.phone_number_id.trim()) missing.push("Phone Number ID");
    if (!form.webhook_verify_token.trim() && !storedSecrets.webhook_verify_token) missing.push("Webhook Verify Token");
    return missing;
  }, [form.meta_access_token, form.phone_number_id, form.webhook_verify_token, storedSecrets.meta_access_token, storedSecrets.webhook_verify_token]);

  const canTest = useMemo(() => form.enabled && missingRequired.length === 0, [form.enabled, missingRequired.length]);

  const statusBadgeLabel = useMemo(() => {
    if (!form.enabled) return "disabled";
    if (missingRequired.length > 0) return "incomplete";
    return providerStatus?.status ?? "configured";
  }, [form.enabled, missingRequired.length, providerStatus?.status]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const provider = await getWhatsAppIntegration();
      if (!provider) {
        setForm(defaultForm);
        setProviderStatus(null);
        setStoredSecrets({ meta_access_token: false, webhook_verify_token: false, meta_app_secret: false });
        setStoredSecretSuffix({ meta_access_token: null, webhook_verify_token: null, meta_app_secret: null });
        return;
      }
      setForm({
        enabled: Boolean(provider.settings?.enabled),
        display_name: provider.display_name ?? "Meta WhatsApp",
        meta_app_id: String(provider.settings?.meta_app_id ?? ""),
        meta_app_secret: String(provider.settings?.meta_app_secret ?? ""),
        meta_access_token: String(provider.settings?.meta_access_token ?? ""),
        whatsapp_business_account_id: String(provider.settings?.whatsapp_business_account_id ?? ""),
        phone_number_id: String(provider.settings?.phone_number_id ?? ""),
        webhook_verify_token: String(provider.settings?.webhook_verify_token ?? ""),
      });
      setStoredSecrets({
        meta_access_token: Boolean(provider.secrets?.meta_access_token_configured),
        webhook_verify_token: Boolean(provider.secrets?.webhook_verify_token_configured),
        meta_app_secret: Boolean(provider.secrets?.meta_app_secret_configured),
      });
      setStoredSecretSuffix({
        meta_access_token: provider.secrets?.meta_access_token_suffix ?? null,
        webhook_verify_token: provider.secrets?.webhook_verify_token_suffix ?? null,
        meta_app_secret: provider.secrets?.meta_app_secret_suffix ?? null,
      });
      setProviderStatus({
        status: provider.status,
        last_tested_at: provider.last_tested_at ?? null,
        last_error_message: provider.last_error_message ?? null,
      });
    } catch (error) {
      setForm(defaultForm);
      setProviderStatus(null);
      setStoredSecrets({ meta_access_token: false, webhook_verify_token: false, meta_app_secret: false });
      setStoredSecretSuffix({ meta_access_token: null, webhook_verify_token: null, meta_app_secret: null });
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to load WhatsApp integration." });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function onSave() {
    setSaving(true);
    try {
      const saved = await saveWhatsAppIntegration({
        enabled: form.enabled,
        display_name: form.display_name || null,
        meta_app_id: form.meta_app_id || null,
        meta_app_secret: form.meta_app_secret.trim() ? form.meta_app_secret : null,
        meta_access_token: form.meta_access_token.trim() ? form.meta_access_token : null,
        whatsapp_business_account_id: form.whatsapp_business_account_id || null,
        phone_number_id: form.phone_number_id || null,
        webhook_verify_token: form.webhook_verify_token.trim() ? form.webhook_verify_token : null,
      });
      setProviderStatus({
        status: saved.status,
        last_tested_at: saved.last_tested_at ?? null,
        last_error_message: saved.last_error_message ?? null,
      });
      setStoredSecrets({
        meta_access_token: Boolean(saved.secrets?.meta_access_token_configured),
        webhook_verify_token: Boolean(saved.secrets?.webhook_verify_token_configured),
        meta_app_secret: Boolean(saved.secrets?.meta_app_secret_configured),
      });
      setStoredSecretSuffix({
        meta_access_token: saved.secrets?.meta_access_token_suffix ?? null,
        webhook_verify_token: saved.secrets?.webhook_verify_token_suffix ?? null,
        meta_app_secret: saved.secrets?.meta_app_secret_suffix ?? null,
      });
      setToast({ tone: "success", message: "WhatsApp integration saved." });
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to save WhatsApp integration." });
    } finally {
      setSaving(false);
    }
  }

  async function onTest() {
    setTesting(true);
    try {
      const result = await testWhatsAppIntegration();
      setProviderStatus({
        status: result.provider.status,
        last_tested_at: result.provider.last_tested_at ?? null,
        last_error_message: result.provider.last_error_message ?? null,
      });
      setToast({ tone: result.ok ? "success" : "error", message: result.ok ? "Meta WhatsApp connection OK." : (result.error || "Meta WhatsApp test failed.") });
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to test WhatsApp integration." });
    } finally {
      setTesting(false);
    }
  }

  return (
    <AppShell requiredPermissions={["tenant.update"]}>
      {toast ? <ToastMessage tone={toast.tone} message={toast.message} /> : null}

      <SectionCard title="WhatsApp Integration" subtitle="Configure Meta WhatsApp Cloud API (tenant-level).">
        {loading ? (
          <LoadingState label="Loading WhatsApp integration..." />
        ) : (
          <Box sx={{ display: "grid", gap: 2 }}>
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Stack direction={{ xs: "column", md: "row" }} spacing={2} alignItems={{ xs: "stretch", md: "center" }} justifyContent="space-between">
                <Box>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>Connection</Typography>
                  <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 0.75, flexWrap: "wrap" }}>
                    <StatusBadge label={statusBadgeLabel} />
                    <Typography variant="caption" color="text.secondary">
                      Last tested: {providerStatus?.last_tested_at ? new Date(providerStatus.last_tested_at).toLocaleString() : "-"}
                    </Typography>
                    {providerStatus?.last_error_message ? (
                      <Typography variant="caption" color="error">
                        {providerStatus.last_error_message}
                      </Typography>
                    ) : null}
                  </Stack>
                  {form.enabled && missingRequired.length > 0 ? (
                    <Typography variant="caption" color="error" sx={{ display: "block", mt: 1 }}>
                      Missing: {missingRequired.join(", ")}
                    </Typography>
                  ) : null}
                </Box>
                <Stack direction="row" spacing={1}>
                  <MuiButton variant="outlined" onClick={() => void load()} disabled={saving || testing}>
                    Refresh
                  </MuiButton>
                  <MuiButton variant="outlined" onClick={() => void onTest()} disabled={saving || testing || !canTest}>
                    {testing ? "Testing..." : "Test Connection"}
                  </MuiButton>
                  <MuiButton variant="contained" onClick={() => void onSave()} disabled={saving}>
                    {saving ? "Saving..." : "Save"}
                  </MuiButton>
                </Stack>
              </Stack>
            </Paper>

            <FormControlLabel
              control={<Checkbox checked={form.enabled} onChange={(e) => setForm((p) => ({ ...p, enabled: e.target.checked }))} />}
              label="Enable Meta WhatsApp Integration"
              sx={{ m: 0 }}
            />

            <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" } }}>
              <TextField
                size="medium"
                label="Phone Number ID"
                type={reveal.phoneNumberId ? "text" : "password"}
                value={form.phone_number_id}
                onChange={(e) => setForm((p) => ({ ...p, phone_number_id: e.target.value }))}
                error={form.enabled && !form.phone_number_id.trim()}
                helperText="Required"
                autoComplete="off"
                InputProps={{
                  endAdornment: (
                    <InputAdornment position="end">
                      <IconButton size="small" onClick={() => setReveal((p) => ({ ...p, phoneNumberId: !p.phoneNumberId }))} edge="end">
                        {reveal.phoneNumberId ? "Hide" : "Show"}
                      </IconButton>
                    </InputAdornment>
                  ),
                }}
              />
              <TextField
                size="medium"
                label="Webhook Verify Token"
                type={reveal.verifyToken ? "text" : "password"}
                value={form.webhook_verify_token}
                onChange={(e) => setForm((p) => ({ ...p, webhook_verify_token: e.target.value }))}
                error={form.enabled && !form.webhook_verify_token.trim() && !storedSecrets.webhook_verify_token}
                helperText={
                  storedSecrets.webhook_verify_token && !form.webhook_verify_token.trim()
                    ? `Stored securely${storedSecretSuffix.webhook_verify_token ? ` (ends with ${storedSecretSuffix.webhook_verify_token})` : ""}. Enter a new value to rotate.`
                    : "Required"
                }
                autoComplete="new-password"
                placeholder={
                  storedSecrets.webhook_verify_token && !form.webhook_verify_token.trim()
                    ? `Stored securely${storedSecretSuffix.webhook_verify_token ? ` (ends with ${storedSecretSuffix.webhook_verify_token})` : ""}`
                    : ""
                }
                InputProps={{
                  endAdornment: (
                    <InputAdornment position="end">
                      <IconButton size="small" onClick={() => setReveal((p) => ({ ...p, verifyToken: !p.verifyToken }))} edge="end">
                        {reveal.verifyToken ? "Hide" : "Show"}
                      </IconButton>
                    </InputAdornment>
                  ),
                }}
              />
            </Box>
            <TextField
              size="medium"
              label="Meta Access Token"
              type={reveal.token ? "text" : "password"}
              value={form.meta_access_token}
              onChange={(e) => setForm((p) => ({ ...p, meta_access_token: e.target.value }))}
              error={form.enabled && !form.meta_access_token.trim() && !storedSecrets.meta_access_token}
              helperText={
                storedSecrets.meta_access_token && !form.meta_access_token.trim()
                  ? `Stored securely${storedSecretSuffix.meta_access_token ? ` (ends with ${storedSecretSuffix.meta_access_token})` : ""}. Enter a new value to rotate.`
                  : "Required"
              }
              autoComplete="new-password"
              placeholder={
                storedSecrets.meta_access_token && !form.meta_access_token.trim()
                  ? `Stored securely${storedSecretSuffix.meta_access_token ? ` (ends with ${storedSecretSuffix.meta_access_token})` : ""}`
                  : ""
              }
              InputProps={{
                endAdornment: (
                  <InputAdornment position="end">
                    <IconButton size="small" onClick={() => setReveal((p) => ({ ...p, token: !p.token }))} edge="end">
                      {reveal.token ? "Hide" : "Show"}
                    </IconButton>
                  </InputAdornment>
                ),
              }}
            />

            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>Webhook URL</Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75 }}>
                {webhookUrl || "-"}
              </Typography>
              <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 1 }}>
                Configure this URL in Meta Webhooks for WhatsApp. Verify token must match.
              </Typography>
            </Paper>

            <Accordion expanded={advancedOpen} onChange={() => setAdvancedOpen((prev) => !prev)} disableGutters>
              <AccordionSummary sx={{ px: 2 }}>
                <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>Advanced Settings</Typography>
              </AccordionSummary>
              <AccordionDetails sx={{ px: 2, pb: 2 }}>
                <Box sx={{ display: "grid", gap: 1.5 }}>
                  <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" } }}>
                    <TextField size="medium" label="Display Name" value={form.display_name} onChange={(e) => setForm((p) => ({ ...p, display_name: e.target.value }))} />
                    <TextField size="medium" label="WhatsApp Business Account ID" value={form.whatsapp_business_account_id} onChange={(e) => setForm((p) => ({ ...p, whatsapp_business_account_id: e.target.value }))} autoComplete="off" />
                    <TextField size="medium" label="Meta App ID" value={form.meta_app_id} onChange={(e) => setForm((p) => ({ ...p, meta_app_id: e.target.value }))} autoComplete="off" />
                    <TextField
                      type="password"
                      size="medium"
                      label="Meta App Secret"
                      value={form.meta_app_secret}
                      onChange={(e) => setForm((p) => ({ ...p, meta_app_secret: e.target.value }))}
                      autoComplete="new-password"
                      helperText={
                        storedSecrets.meta_app_secret && !form.meta_app_secret.trim()
                          ? `Stored securely${storedSecretSuffix.meta_app_secret ? ` (ends with ${storedSecretSuffix.meta_app_secret})` : ""}. Enter a new value to rotate.`
                          : ""
                      }
                      placeholder={
                        storedSecrets.meta_app_secret && !form.meta_app_secret.trim()
                          ? `Stored securely${storedSecretSuffix.meta_app_secret ? ` (ends with ${storedSecretSuffix.meta_app_secret})` : ""}`
                          : ""
                      }
                    />
                  </Box>
                  <Paper variant="outlined" sx={{ p: 1.5 }}>
                    <Typography variant="caption" color="text.secondary">Debug</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
                      Provider status: {providerStatus?.status ?? "-"}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      Webhook: {webhookUrl || "-"}
                    </Typography>
                  </Paper>
                </Box>
              </AccordionDetails>
            </Accordion>
          </Box>
        )}
      </SectionCard>
    </AppShell>
  );
}
