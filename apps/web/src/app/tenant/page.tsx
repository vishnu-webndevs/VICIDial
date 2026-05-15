"use client";

import { FormEvent, useEffect, useState } from "react";
import { Box, FormControlLabel, MenuItem, MuiButton, Stack, Switch, TextField, Typography, Alert, Chip } from "@/ui";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { useDetectTimezone } from "@/hooks/useDetectTimezone";

const leadCountryOptions = [
  { code: "US", label: "United States (+1)" },
  { code: "CA", label: "Canada (+1)" },
  { code: "GB", label: "United Kingdom (+44)" },
  { code: "AU", label: "Australia (+61)" },
  { code: "IN", label: "India (+91)" },
  { code: "PH", label: "Philippines (+63)" },
  { code: "AE", label: "United Arab Emirates (+971)" },
  { code: "SA", label: "Saudi Arabia (+966)" },
  { code: "DE", label: "Germany (+49)" },
  { code: "FR", label: "France (+33)" },
  { code: "ES", label: "Spain (+34)" },
  { code: "BR", label: "Brazil (+55)" },
] as const;

type TenantResponse = {
  data: {
    id: string;
    name: string;
    slug: string;
    status: string;
    settings?: {
      timezone?: string;
      locale?: string;
      date_format?: string;
      branding_company_name?: string | null;
      branding_logo_url?: string | null;
      default_webhook_url?: string | null;
      alert_email?: string | null;
      default_caller_id?: string | null;
      voice_locale?: string | null;
      metadata?: {
        default_lead_country?: string | null;
        integration_mode?: "sandbox" | "production" | null;
      } | null;
    } | null;
  };
};

export default function TenantPage() {
  const [tenant, setTenant] = useState<TenantResponse["data"] | null>(null);
  const [name, setName] = useState("");
  const [timezone, setTimezone] = useState("UTC");
  const [locale, setLocale] = useState("en");
  const [dateFormat, setDateFormat] = useState("Y-m-d");
  const [companyName, setCompanyName] = useState("");
  const [logoUrl, setLogoUrl] = useState("");
  const [webhookUrl, setWebhookUrl] = useState("");
  const [alertEmail, setAlertEmail] = useState("");
  const [defaultCallerId, setDefaultCallerId] = useState("");
  const [voiceLocale, setVoiceLocale] = useState("en-US");
  const [defaultLeadCountry, setDefaultLeadCountry] = useState("US");
  const [integrationMode, setIntegrationMode] = useState<"sandbox" | "production">("sandbox");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [showTimezoneLoaded, setShowTimezoneLoaded] = useState(false);
  
  // Auto-detect user's timezone
  const { detectedTimezone, abbreviation, name: tzName, isLoading: tzLoading } = useDetectTimezone();

  async function loadTenant() {
    setLoading(true);
    setMessage("");

    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<TenantResponse>("/tenant", {
        token,
        tenantId,
      });
      setTenant(response.data);
      setName(response.data.name ?? "");
      setTimezone(response.data.settings?.timezone ?? "UTC");
      setLocale(response.data.settings?.locale ?? "en");
      setDateFormat(response.data.settings?.date_format ?? "Y-m-d");
      setCompanyName(response.data.settings?.branding_company_name ?? "");
      setLogoUrl(response.data.settings?.branding_logo_url ?? "");
      setWebhookUrl(response.data.settings?.default_webhook_url ?? "");
      setAlertEmail(response.data.settings?.alert_email ?? "");
      setDefaultCallerId(response.data.settings?.default_caller_id ?? "");
      setVoiceLocale(response.data.settings?.voice_locale ?? "en-US");
      setDefaultLeadCountry(response.data.settings?.metadata?.default_lead_country ?? "US");
      setIntegrationMode(response.data.settings?.metadata?.integration_mode ?? "sandbox");
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : "Failed to fetch tenant.";
      setMessage(errorMessage);
    } finally {
      setLoading(false);
    }
  }

  async function saveTenant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest("/tenant", {
        method: "PATCH",
        token,
        tenantId,
        body: {
          name,
          settings: {
            timezone,
            locale,
            date_format: dateFormat,
            branding_company_name: companyName || null,
            branding_logo_url: logoUrl || null,
            default_webhook_url: webhookUrl || null,
            alert_email: alertEmail || null,
            default_caller_id: defaultCallerId || null,
            voice_locale: voiceLocale,
            metadata: {
              default_lead_country: defaultLeadCountry,
              integration_mode: integrationMode,
            },
          },
        },
      });
      await apiRequest("/tenant/voice-profile", {
        method: "PATCH",
        token,
        tenantId,
        body: {
          default_caller_id: defaultCallerId || null,
          voice_locale: voiceLocale,
        },
      });
      setMessage("Tenant settings saved.");
      await loadTenant();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to save tenant settings.");
    }
  }

  useEffect(() => {
    void loadTenant();
  }, []);

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard title="Tenant Profile" subtitle="Configure tenant identity, branding, and locale settings.">
          <Box
            component="form"
            sx={{ display: "grid", gap: 1.25, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" } }}
            onSubmit={saveTenant}
          >
            <TextField
              size="medium"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Tenant name"
              required
            />
            <TextField
              size="medium"
              value={companyName}
              onChange={(event) => setCompanyName(event.target.value)}
              placeholder="Company name"
            />
            <Box sx={{ gridColumn: { md: "span 2" } }}>
              <Stack spacing={1}>
                <TextField
                  size="medium"
                  value={timezone}
                  onChange={(event) => setTimezone(event.target.value)}
                  placeholder="Timezone (e.g. UTC)"
                  label="Timezone"
                />
                {detectedTimezone && detectedTimezone !== timezone && (
                  <Alert severity="info">
                    <Typography variant="body2" sx={{ mb: 1 }}>
                      Detected timezone: <strong>{detectedTimezone}</strong> ({abbreviation})
                    </Typography>
                    <Typography variant="caption" sx={{ display: "block", mb: 1 }}>
                      {tzName}
                    </Typography>
                    <MuiButton
                      size="small"
                      variant="outlined"
                      onClick={() => {
                        setTimezone(detectedTimezone);
                        setShowTimezoneLoaded(true);
                      }}
                    >
                      Use Detected Timezone
                    </MuiButton>
                  </Alert>
                )}
                {showTimezoneLoaded && timezone === detectedTimezone && (
                  <Alert severity="success">
                    <Typography variant="body2">✓ Using detected timezone: {detectedTimezone}</Typography>
                  </Alert>
                )}
              </Stack>
            </Box>
            <TextField
              size="medium"
              value={locale}
              onChange={(event) => setLocale(event.target.value)}
              placeholder="Locale (e.g. en)"
            />
            <TextField
              size="medium"
              value={dateFormat}
              onChange={(event) => setDateFormat(event.target.value)}
              placeholder="Date format (e.g. Y-m-d)"
            />
            <TextField
              size="medium"
              value={alertEmail}
              onChange={(event) => setAlertEmail(event.target.value)}
              placeholder="Alert email"
            />
            <TextField
              size="medium"
              value={logoUrl}
              onChange={(event) => setLogoUrl(event.target.value)}
              placeholder="Branding logo URL"
            />
            <TextField
              size="medium"
              value={webhookUrl}
              onChange={(event) => setWebhookUrl(event.target.value)}
              placeholder="Default webhook URL"
            />
            <TextField
              size="medium"
              value={defaultCallerId}
              onChange={(event) => setDefaultCallerId(event.target.value)}
              placeholder="Default caller ID (+15551234567)"
            />
            <TextField
              select
              size="medium"
              value={defaultLeadCountry}
              onChange={(event) => setDefaultLeadCountry(event.target.value)}
            >
              {leadCountryOptions.map((option) => (
                <MenuItem key={option.code} value={option.code}>
                  {option.label}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              size="medium"
              value={voiceLocale}
              onChange={(event) => setVoiceLocale(event.target.value)}
              placeholder="Voice locale (e.g. en-US)"
            />
            <Box sx={{ display: "grid", gap: 0.5, alignContent: "center" }}>
              <FormControlLabel
                control={
                  <Switch
                    checked={integrationMode === "production"}
                    onChange={(event) => setIntegrationMode(event.target.checked ? "production" : "sandbox")}
                  />
                }
                label="Enable Production (Live) Mode"
              />
              <Typography variant="caption" color="text.secondary">
                Current mode: {integrationMode === "production" ? "Production/Live" : "Local/Sandbox"}
              </Typography>
            </Box>
            <MuiButton type="submit" variant="contained" sx={{ gridColumn: { md: "span 2" } }}>
              Save Tenant Settings
            </MuiButton>
          </Box>
          <Stack spacing={0.75} sx={{ mt: 1.5 }}>
            {loading ? <LoadingState label="Loading tenant profile..." /> : null}
            {!tenant && !loading ? (
              <EmptyState title="No tenant profile" description="No tenant profile data is available." />
            ) : null}
            {tenant ? <Typography variant="body2" color="text.secondary">Tenant Slug: {tenant.slug}</Typography> : null}
            {tenant ? <Typography variant="body2" color="text.secondary">Status: {tenant.status}</Typography> : null}
          </Stack>
          {message ? <ErrorState message={message} /> : null}
          <MuiButton sx={{ mt: 1.5 }} variant="outlined" onClick={loadTenant} disabled={loading}>
            {loading ? "Refreshing..." : "Refresh"}
          </MuiButton>
        </SectionCard>
      </Box>
    </AppShell>
  );
}
