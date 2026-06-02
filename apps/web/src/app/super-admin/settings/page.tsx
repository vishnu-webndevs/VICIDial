"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { Box, Checkbox, FormControlLabel, MenuItem, MuiButton, Stack, TextField, Typography, Alert } from "@/ui";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import { getTenantContext, getTenantScopedStorageKey } from "@/lib/tenant-context";
import { useDetectTimezone } from "@/hooks/useDetectTimezone";

const leadCountryOptions = [
  { code: "US", label: "United States (+1)" },
  { code: "CA", label: "Canada (+1)" },
  { code: "GB", label: "United Kingdom (+44)" },
  { code: "AU", label: "Australia (+61)" },
  { code: "IN", label: "India (+91)" },
] as const;

const timezoneOptions = [
  "UTC",
  "America/New_York",
  "America/Chicago",
  "America/Denver",
  "America/Los_Angeles",
  "Europe/London",
  "Europe/Berlin",
  "Asia/Dubai",
  "Asia/Kolkata",
  "Asia/Manila",
];

const weekDays = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as const;

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
        calling_window?: CallingWindowResponse | null;
      } | null;
    } | null;
  };
};

type CallingWindowResponse = {
  days?: string[];
  start_time?: string;
  end_time?: string;
  timezone?: string;
};

export default function SuperAdminSettingsPage() {
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
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState<{ tone: "success" | "error"; message: string } | null>(null);
  const [showTimezoneLoaded, setShowTimezoneLoaded] = useState(false);

  const [callingDays, setCallingDays] = useState<string[]>(["Mon", "Tue", "Wed", "Thu", "Fri"]);
  const [callingStart, setCallingStart] = useState("09:00");
  const [callingEnd, setCallingEnd] = useState("18:00");
  const [callingTimezone, setCallingTimezone] = useState("UTC");
  const [savingWindow, setSavingWindow] = useState(false);
  const [showCallingTimezoneLoaded, setShowCallingTimezoneLoaded] = useState(false);

  // Auto-detect user's timezone
  const { detectedTimezone, abbreviation, name: tzName, isLoading: tzLoading } = useDetectTimezone();

  function readCallingWindowFromStorage(tenantId: string | null): CallingWindowResponse | null {
    if (typeof window === "undefined") {
      return null;
    }
    const scopedKey = getTenantScopedStorageKey("calling-window", tenantId);
    const raw = localStorage.getItem(scopedKey);
    if (!raw) {
      return null;
    }
    try {
      return JSON.parse(raw) as CallingWindowResponse;
    } catch {
      return null;
    }
  }

  function writeCallingWindowToStorage(tenantId: string | null, payload: CallingWindowResponse) {
    if (typeof window === "undefined") {
      return;
    }
    const scopedKey = getTenantScopedStorageKey("calling-window", tenantId);
    localStorage.setItem(scopedKey, JSON.stringify(payload));
  }

  const loadTenant = useCallback(async () => {
    setLoading(true);
    try {
      const { token, tenantId } = getTenantContext();
      const tenantResponse = await apiRequest<TenantResponse>("/tenant", { token, tenantId });
      setTenant(tenantResponse.data);
      setName(tenantResponse.data.name ?? "");
      setTimezone(tenantResponse.data.settings?.timezone ?? "UTC");
      setLocale(tenantResponse.data.settings?.locale ?? "en");
      setDateFormat(tenantResponse.data.settings?.date_format ?? "Y-m-d");
      setCompanyName(tenantResponse.data.settings?.branding_company_name ?? "");
      setLogoUrl(tenantResponse.data.settings?.branding_logo_url ?? "");
      setWebhookUrl(tenantResponse.data.settings?.default_webhook_url ?? "");
      setAlertEmail(tenantResponse.data.settings?.alert_email ?? "");
      setDefaultCallerId(tenantResponse.data.settings?.default_caller_id ?? "");
      setVoiceLocale(tenantResponse.data.settings?.voice_locale ?? "en-US");
      setDefaultLeadCountry(tenantResponse.data.settings?.metadata?.default_lead_country ?? "US");

      const callingWindow = tenantResponse.data.settings?.metadata?.calling_window;
      if (callingWindow) {
        setCallingDays(callingWindow.days ?? ["Mon", "Tue", "Wed", "Thu", "Fri"]);
        setCallingStart(callingWindow.start_time ?? "09:00");
        setCallingEnd(callingWindow.end_time ?? "18:00");
        setCallingTimezone(callingWindow.timezone ?? "UTC");
      } else {
        const persistedWindow = readCallingWindowFromStorage(tenantId);
        if (persistedWindow) {
          setCallingDays(persistedWindow.days ?? ["Mon", "Tue", "Wed", "Thu", "Fri"]);
          setCallingStart(persistedWindow.start_time ?? "09:00");
          setCallingEnd(persistedWindow.end_time ?? "18:00");
          setCallingTimezone(persistedWindow.timezone ?? "UTC");
        }
      }
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to load settings." });
    } finally {
      setLoading(false);
    }
  }, []);

  async function saveTenant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      const { token, tenantId } = getTenantContext();
      const existingMetadata = tenant?.settings?.metadata ?? {};
      const updatedMetadata = {
        ...existingMetadata,
        default_lead_country: defaultLeadCountry,
      };
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
            metadata: updatedMetadata,
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
      setToast({ tone: "success", message: "Tenant settings saved." });
      await loadTenant();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to save tenant settings." });
    }
  }

  async function saveCallingWindow() {
    setSavingWindow(true);
    try {
      const { token, tenantId } = getTenantContext();
      const existingMetadata = tenant?.settings?.metadata ?? {};
      const updatedMetadata = {
        ...existingMetadata,
        calling_window: {
          days: callingDays,
          start_time: callingStart,
          end_time: callingEnd,
          timezone: callingTimezone,
        },
      };

      await apiRequest("/tenant", {
        method: "PATCH",
        token,
        tenantId,
        body: {
          settings: {
            metadata: updatedMetadata,
          },
        },
      });

      writeCallingWindowToStorage(tenantId, {
        days: callingDays,
        start_time: callingStart,
        end_time: callingEnd,
        timezone: callingTimezone,
      });
      setToast({ tone: "success", message: "Calling window saved successfully to the server." });
      await loadTenant();
    } catch (error) {
      setToast({ tone: "error", message: error instanceof Error ? error.message : "Failed to save calling window." });
    } finally {
      setSavingWindow(false);
    }
  }

  useEffect(() => {
    void loadTenant();
  }, [loadTenant]);

  return (
    <AppShell
      requiredPermissions={["tenant.view"]}
      requiredRoles={["platform_super_admin", "super_admin"]}
    >
      {toast ? <ToastMessage tone={toast.tone} message={toast.message} /> : null}
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard title="Tenant Profile" subtitle="Configure tenant identity, branding, and locale settings.">
          <Box
            component="form"
            sx={{ display: "grid", gap: 1.25, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" } }}
            onSubmit={saveTenant}
          >
            <TextField size="medium" value={name} onChange={(event) => setName(event.target.value)} placeholder="Tenant name" required />
            <TextField size="medium" value={companyName} onChange={(event) => setCompanyName(event.target.value)} placeholder="Company name" />
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
            <TextField size="medium" value={locale} onChange={(event) => setLocale(event.target.value)} placeholder="Locale (e.g. en)" />
            <TextField size="medium" value={dateFormat} onChange={(event) => setDateFormat(event.target.value)} placeholder="Date format (e.g. Y-m-d)" />
            <TextField size="medium" value={alertEmail} onChange={(event) => setAlertEmail(event.target.value)} placeholder="Alert email" />
            <TextField size="medium" value={logoUrl} onChange={(event) => setLogoUrl(event.target.value)} placeholder="Branding logo URL" />
            <TextField size="medium" value={webhookUrl} onChange={(event) => setWebhookUrl(event.target.value)} placeholder="Default webhook URL" />
            <TextField size="medium" value={defaultCallerId} onChange={(event) => setDefaultCallerId(event.target.value)} placeholder="Default caller ID (+15551234567)" />
            <TextField select size="medium" value={defaultLeadCountry} onChange={(event) => setDefaultLeadCountry(event.target.value)}>
              {leadCountryOptions.map((option) => (
                <MenuItem key={option.code} value={option.code}>{option.label}</MenuItem>
              ))}
            </TextField>
            <TextField size="medium" value={voiceLocale} onChange={(event) => setVoiceLocale(event.target.value)} placeholder="Voice locale (e.g. en-US)" />
            <MuiButton type="submit" variant="contained" sx={{ gridColumn: { md: "span 2" } }}>
              Save Tenant Settings
            </MuiButton>
          </Box>
          <Stack spacing={0.75} sx={{ mt: 1.5 }}>
            {loading ? <LoadingState label="Loading tenant profile..." /> : null}
            {!tenant && !loading ? <EmptyState title="No tenant profile" description="No tenant profile data is available." /> : null}
            {tenant ? <Typography variant="body2" color="text.secondary">Tenant Slug: {tenant.slug}</Typography> : null}
            {tenant ? <Typography variant="body2" color="text.secondary">Status: {tenant.status}</Typography> : null}
          </Stack>
          {toast?.tone === "error" ? <ErrorState message={toast.message} /> : null}
          <MuiButton sx={{ mt: 1.5 }} variant="outlined" onClick={() => void loadTenant()} disabled={loading}>
            {loading ? "Refreshing..." : "Refresh"}
          </MuiButton>
        </SectionCard>

        <SectionCard title="Calling Window" subtitle="Configure allowed calling days and hours.">
          <Stack spacing={1}>
            <Typography variant="body2" color="text.secondary">Day toggles</Typography>
            <Box sx={{ display: "grid", gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" } }}>
              {weekDays.map((day) => (
                <FormControlLabel
                  key={day}
                  control={
                    <Checkbox
                      checked={callingDays.includes(day)}
                      onChange={() =>
                        setCallingDays((prev) =>
                          prev.includes(day) ? prev.filter((value) => value !== day) : [...prev, day]
                        )
                      }
                    />
                  }
                  label={day}
                />
              ))}
            </Box>

            <Stack direction={{ xs: "column", md: "row" }} spacing={1}>
              <TextField
                type="time"
                size="medium"
                label="Start Time"
                value={callingStart}
                onChange={(event) => setCallingStart(event.target.value)}
                inputProps={{ step: 60 }}
                fullWidth
              />
              <TextField
                type="time"
                size="medium"
                label="End Time"
                value={callingEnd}
                onChange={(event) => setCallingEnd(event.target.value)}
                inputProps={{ step: 60 }}
                fullWidth
              />
              <Box sx={{ width: "100%" }}>
                <Stack spacing={1}>
                  <TextField
                    select
                    size="medium"
                    label="Timezone"
                    value={callingTimezone}
                    onChange={(event) => setCallingTimezone(event.target.value)}
                    fullWidth
                  >
                    {timezoneOptions.map((value) => (
                      <MenuItem key={value} value={value}>{value}</MenuItem>
                    ))}
                  </TextField>
                  {detectedTimezone && detectedTimezone !== callingTimezone && (
                    <Alert severity="info">
                      <Typography variant="body2" sx={{ mb: 1 }}>
                        Your timezone: <strong>{detectedTimezone}</strong> ({abbreviation})
                      </Typography>
                      <MuiButton
                        size="small"
                        variant="outlined"
                        onClick={() => {
                          setCallingTimezone(detectedTimezone);
                          setShowCallingTimezoneLoaded(true);
                        }}
                      >
                        Use My Timezone
                      </MuiButton>
                    </Alert>
                  )}
                  {showCallingTimezoneLoaded && callingTimezone === detectedTimezone && (
                    <Alert severity="success">
                      <Typography variant="body2">✓ Using your timezone: {callingTimezone}</Typography>
                    </Alert>
                  )}
                </Stack>
              </Box>
            </Stack>

            <MuiButton variant="contained" onClick={() => void saveCallingWindow()} disabled={savingWindow}>
              {savingWindow ? "Saving..." : "Save Calling Window"}
            </MuiButton>
          </Stack>
        </SectionCard>
      </Box>
    </AppShell>
  );
}
