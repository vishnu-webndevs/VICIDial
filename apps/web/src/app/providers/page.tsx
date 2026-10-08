"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";
import { listCampaigns } from "@/lib/product-api";
import {
  Alert,
  Box,
  Button,
  Checkbox,
  FormControlLabel,
  FormSelect,
  FormTextField,
  MuiButton,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from "@/ui";

type ProviderNumber = {
  id: string;
  provider_account_id: string;
  phone_number: string;
  friendly_name?: string | null;
  status: string;
  is_validated: boolean;
  last_error_message?: string | null;
};

type Provider = {
  id: string;
  provider_type: string;
  display_name: string;
  status: string;
  failover_priority?: number;
  is_fallback?: boolean;
  credentials?: Record<string, string>;
  numbers?: ProviderNumber[];
};

type AvailableNumber = {
  sid?: string | null;
  phone_number: string;
  friendly_name?: string | null;
  capabilities?: Record<string, boolean>;
};

type Agent = {
  id: string;
  company_number: string;
  status: string;
};

type Campaign = {
  id: string;
  name: string;
};

type AgentAssignment = {
  id: string;
  agent: { id: string; company_number: string; status: string } | null;
  number: { id: string; phone_number: string; status: string } | null;
  status: string;
};

export default function ProvidersPage() {
  const [providers, setProviders] = useState<Provider[]>([]);
  const [editingProviderId, setEditingProviderId] = useState<string | null>(null);
  const [displayName, setDisplayName] = useState("");
  const [providerType, setProviderType] = useState("twilio");
  const [accountSid, setAccountSid] = useState("");
  const [authToken, setAuthToken] = useState("");
  const [authId, setAuthId] = useState("");
  const [appId, setAppId] = useState("");
  const [endpointUsername, setEndpointUsername] = useState("");
  const [endpointPassword, setEndpointPassword] = useState("");
  const [fromNumber, setFromNumber] = useState("");
  const [whatsappFrom, setWhatsappFrom] = useState("");
  const [twimlAppSid, setTwimlAppSid] = useState("");
  const [apiKeySid, setApiKeySid] = useState("");
  const [apiKeySecret, setApiKeySecret] = useState("");
  const [messageState, setMessageState] = useState<{ text: string; severity: "error" | "info" | "success" } | null>(null);

  const setMessage = useCallback((text: string, severity: "error" | "info" | "success" = "error") => {
    if (!text) {
      setMessageState(null);
    } else {
      setMessageState({ text, severity });
    }
  }, []);

  const [loading, setLoading] = useState(false);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [agentAssignments, setAgentAssignments] = useState<AgentAssignment[]>([]);
  const [selectedProviderForFetch, setSelectedProviderForFetch] = useState("");
  const [availableNumbers, setAvailableNumbers] = useState<AvailableNumber[]>([]);
  const [pickedNumbers, setPickedNumbers] = useState<string[]>([]);
  const [manualPhoneNumber, setManualPhoneNumber] = useState("");
  const [testingProviderId, setTestingProviderId] = useState("");
  const [testingNumberId, setTestingNumberId] = useState("");
  const [agentId, setAgentId] = useState("");
  const [agentNumberId, setAgentNumberId] = useState("");
  const [campaignId, setCampaignId] = useState("");
  const [campaignAgentId, setCampaignAgentId] = useState("");

  const validatedNumbers = useMemo(
    () =>
      providers
        .flatMap((provider) => provider.numbers ?? [])
        .filter((number) => number.status === "active" && number.is_validated),
    [providers]
  );

  const loadProviders = useCallback(async () => {
    setLoading(true);
    try {
      const { token, tenantId } = getTenantContext();
      const [providerResponse, teamResponse, assignmentsResponse, campaignData] = await Promise.all([
        apiRequest<{ data: { providers: Provider[] } }>("/admin/settings/communication", { token, tenantId }),
        apiRequest<{ data: Agent[] }>("/agents", { token, tenantId }),
        apiRequest<{ data: AgentAssignment[] }>("/admin/settings/communication/agents/number-assignments", { token, tenantId }),
        listCampaigns(),
      ]);
      const nextProviders = providerResponse.data?.providers ?? [];
      setProviders(nextProviders);
      const activeAgents = (teamResponse.data ?? []).filter((agent) => agent.status === "active");
      setAgents(activeAgents);
      setAgentAssignments(assignmentsResponse.data ?? []);
      setCampaigns(campaignData);
      if (nextProviders.length > 0) {
        setSelectedProviderForFetch((prev) => prev || nextProviders[0].id);
        setTestingProviderId((prev) => prev || nextProviders[0].id);
      }
      if (activeAgents.length > 0) {
        setAgentId((prev) => prev || activeAgents[0].id);
        setCampaignAgentId((prev) => prev || activeAgents[0].id);
      }
      if (campaignData.length > 0) {
        setCampaignId((prev) => prev || campaignData[0].id);
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load providers.");
    } finally {
      setLoading(false);
    }
  }, [setMessage]);

  function resetProviderForm() {
    setEditingProviderId(null);
    setDisplayName("");
    setProviderType("twilio");
    setAccountSid("");
    setAuthToken("");
    setAuthId("");
    setAppId("");
    setEndpointUsername("");
    setEndpointPassword("");
    setFromNumber("");
    setWhatsappFrom("");
    setTwimlAppSid("");
    setApiKeySid("");
    setApiKeySecret("");
  }

  async function saveProvider(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    try {
      const { token, tenantId } = getTenantContext();
      const credentialUpdateRequested = Boolean(accountSid || authToken || authId || appId || endpointUsername || endpointPassword || fromNumber || whatsappFrom || twimlAppSid || apiKeySid || apiKeySecret);
      const credentialsPayload: Record<string, string> = {
        account_sid: accountSid || authId,
        auth_id: authId || accountSid,
        auth_token: authToken,
        from_number: fromNumber,
        whatsapp_from: whatsappFrom,
        twilio_twiml_app_sid: twimlAppSid,
        twilio_api_key_sid: apiKeySid,
        twilio_api_key_secret: apiKeySecret,
        app_id: appId,
        endpoint_username: endpointUsername,
        endpoint_password: endpointPassword,
      };

      if (editingProviderId) {
        await apiRequest(`/providers/${editingProviderId}`, {
          method: "PATCH",
          token,
          tenantId,
          body: {
            display_name: displayName,
            ...(credentialUpdateRequested
              ? { credentials: credentialsPayload }
              : {}),
          },
        });
        setMessage("Provider updated.", "success");
      } else {
        await apiRequest("/providers", {
          method: "POST",
          token,
          tenantId,
          body: {
            provider_type: providerType,
            display_name: displayName,
            credentials: credentialsPayload,
          },
        });
        setMessage("Provider created.", "success");
      }
      resetProviderForm();
      await loadProviders();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to save provider.", "error");
    }
  }

  function startEditProvider(provider: Provider) {
    setEditingProviderId(provider.id);
    setDisplayName(provider.display_name);
    setProviderType(provider.provider_type);
    setAccountSid(provider.credentials?.account_sid ?? provider.credentials?.auth_id ?? "");
    setAuthId(provider.credentials?.auth_id ?? provider.credentials?.account_sid ?? "");
    setAuthToken(provider.credentials?.auth_token ?? "");
    setAppId(provider.credentials?.app_id ?? "");
    setEndpointUsername(provider.credentials?.endpoint_username ?? "");
    setEndpointPassword(provider.credentials?.endpoint_password ?? "");
    setFromNumber(provider.credentials?.from_number ?? "");
    setWhatsappFrom(provider.credentials?.whatsapp_from ?? "");
    setTwimlAppSid(provider.credentials?.twilio_twiml_app_sid ?? "");
    setApiKeySid(provider.credentials?.twilio_api_key_sid ?? "");
    setApiKeySecret(provider.credentials?.twilio_api_key_secret ?? "");
    setMessage("Editing provider.", "info");
  }

  async function deleteProvider(providerId: string) {
    setMessage("");
    try {
      const provider = providers.find((item) => item.id === providerId);
      const confirmation = window.prompt(
        `Type DELETE to confirm removing provider "${provider?.display_name ?? providerId}".`
      );
      if (confirmation !== "DELETE") {
        setMessage("Delete cancelled. Type DELETE exactly to confirm.", "info");
        return;
      }

      const { token, tenantId } = getTenantContext();
      await apiRequest(`/providers/${providerId}`, {
        method: "DELETE",
        token,
        tenantId,
      });
      if (editingProviderId === providerId) {
        resetProviderForm();
      }
      setMessage("Provider deleted.", "success");
      await loadProviders();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to delete provider.", "error");
    }
  }

  async function testProvider(providerId: string) {
    setMessage("");
    try {
      const { token, tenantId } = getTenantContext();
      const response = await apiRequest<{
        data: {
          provider?: { id: string; status: string; last_tested_at?: string | null; last_error_message?: string | null };
          provider_test_result?: { ok?: boolean; code?: string | null; message?: string | null; mode?: string | null };
          number_test_result?: { ok?: boolean; code?: string | null; message?: string | null } | null;
        };
      }>(`/admin/settings/communication/providers/${providerId}/test`, {
        method: "POST",
        token,
        tenantId,
        body: testingNumberId ? { provider_phone_number_id: testingNumberId } : {},
      });
      const providerStatus = response.data?.provider?.status ?? "unknown";
      const mode = response.data?.provider_test_result?.mode ?? "live";
      const isOk = response.data?.provider_test_result?.ok === true;
      const ok = isOk ? "ok" : "failed";
      const detail = response.data?.provider_test_result?.message ?? response.data?.provider?.last_error_message ?? "";
      await loadProviders();
      setMessage(`Provider test: ${ok} (mode=${mode}, status=${providerStatus})${detail ? ` — ${detail}` : ""}`, isOk ? "success" : "error");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Provider test failed.", "error");
    }
  }

  async function saveFailover() {
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest("/providers/failover-policy", {
        method: "PATCH",
        token,
        tenantId,
        body: {
          providers: providers.map((provider, index) => ({
            id: provider.id,
            failover_priority: provider.failover_priority ?? index + 1,
            is_fallback: Boolean(provider.is_fallback),
          })),
        },
      });
      setMessage("Failover policy updated.", "success");
      await loadProviders();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to update failover policy.", "error");
    }
  }

  async function fetchAvailableProviderNumbers() {
    if (!selectedProviderForFetch) {
      setMessage("Select a provider first.", "error");
      return;
    }
    setMessage("");
    try {
      const { token, tenantId } = getTenantContext();
      const selectedProvider = providers.find((p) => p.id === selectedProviderForFetch);
      const providerName = selectedProvider ? selectedProvider.display_name : "provider";
      const response = await apiRequest<{ data: { numbers: AvailableNumber[] } }>(
        `/admin/settings/communication/providers/${selectedProviderForFetch}/numbers`,
        { token, tenantId }
      );
      const fetched = response.data?.numbers ?? [];
      setAvailableNumbers(fetched);
      setPickedNumbers([]);
      if (fetched.length === 0) {
        setMessage(`No rented numbers found on ${providerName} account.`, "info");
      } else {
        setMessage(`Numbers fetched from ${providerName}.`, "success");
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to fetch numbers.", "error");
    }
  }

  async function syncSelectedNumbers() {
    if (!selectedProviderForFetch) {
      setMessage("Select a provider first.", "error");
      return;
    }
    const trimmedManual = manualPhoneNumber.trim();
    const selectedItems = availableNumbers.filter((item) => pickedNumbers.includes(item.phone_number));

    if (selectedItems.length === 0 && !trimmedManual) {
      setMessage("Select at least one fetched number or enter a manual phone number.", "error");
      return;
    }

    setMessage("");
    try {
      const { token, tenantId } = getTenantContext();
      const payload = selectedItems.map((item) => ({
        sid: item.sid ?? null,
        phone_number: item.phone_number,
        friendly_name: item.friendly_name ?? null,
        capabilities: item.capabilities ?? {},
      }));

      if (trimmedManual) {
        payload.push({
          sid: null,
          phone_number: trimmedManual.startsWith("+") ? trimmedManual : `+${trimmedManual}`,
          friendly_name: "Manual Test Number",
          capabilities: { voice: true, sms: true },
        });
      }

      await apiRequest(`/admin/settings/communication/providers/${selectedProviderForFetch}/numbers/sync`, {
        method: "POST",
        token,
        tenantId,
        body: { numbers: payload },
      });
      setManualPhoneNumber("");
      setMessage("Selected / Manual numbers synced.", "success");
      await loadProviders();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Number sync failed.", "error");
    }
  }

  async function assignAgentNumber() {
    if (!agentId || !agentNumberId) {
      setMessage("Choose both an agent and a validated number.", "error");
      return;
    }
    try {
      const { token, tenantId } = getTenantContext();
      const selectedNumber = validatedNumbers.find((entry) => entry.id === agentNumberId);
      if (!selectedNumber?.provider_account_id) {
        setMessage("Selected number is missing provider mapping. Re-sync provider numbers.", "error");
        return;
      }
      await apiRequest("/admin/settings/communication/agents/number-assignments", {
        method: "POST",
        token,
        tenantId,
        body: {
          agent_id: agentId,
          provider_account_id: selectedNumber.provider_account_id,
          provider_phone_number_id: agentNumberId,
          status: "active",
        },
      });
      setMessage("Agent number assigned.", "success");
      await loadProviders();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to assign number.", "error");
    }
  }

  async function mapCampaignAgent() {
    if (!campaignId || !campaignAgentId) {
      setMessage("Choose both a campaign and agent.");
      return;
    }
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/admin/settings/communication/campaigns/${campaignId}/agents`, {
        method: "PUT",
        token,
        tenantId,
        body: {
          assignments: [{ agent_id: campaignAgentId }],
        },
      });
      setMessage("Campaign-agent mapping saved.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to map campaign agent.");
    }
  }

  useEffect(() => {
    void loadProviders();
  }, [loadProviders]);

  return (
    <AppShell requiredPermissions={["provider.view"]}>
      <Stack spacing={4}>
        <SectionCard title="Provider Accounts" subtitle="Admin-only communication settings with tenant-isolated provider credentials.">
          <Box
            component="form"
            onSubmit={saveProvider}
            sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr auto" } }}
          >
            <FormTextField
              value={displayName}
              onChange={(event) => setDisplayName(event.target.value)}
              label="Provider Name"
              placeholder="Provider display name"
              required
            />
            <FormSelect
              value={providerType}
              onChange={(event) => setProviderType(event.target.value)}
              label="Provider Type"
              options={[
                { label: "Twilio", value: "twilio" },
                { label: "Vonage", value: "vonage" },
                { label: "Plivo", value: "plivo" },
              ]}
            />
            <Button type="submit" sx={{ minHeight: 40 }}>
              {editingProviderId ? "Save Provider" : "Add Provider"}
            </Button>

            {providerType === "twilio" && (
              <>
                <FormTextField
                  value={accountSid}
                  onChange={(event) => setAccountSid(event.target.value)}
                  label="Twilio Account SID"
                  placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                />
                <FormTextField
                  value={authToken}
                  onChange={(event) => setAuthToken(event.target.value)}
                  label="Twilio Auth Token"
                  type="password"
                  placeholder="Twilio auth token"
                />
                <FormTextField
                  value={fromNumber}
                  onChange={(event) => setFromNumber(event.target.value)}
                  label="Default From Number"
                  placeholder="+15551234567"
                />
                <FormTextField
                  value={whatsappFrom}
                  onChange={(event) => setWhatsappFrom(event.target.value)}
                  label="WhatsApp From"
                  placeholder="whatsapp:+14155238886"
                />
                <FormTextField
                  value={twimlAppSid}
                  onChange={(event) => setTwimlAppSid(event.target.value)}
                  label="Twilio TwiML App SID"
                  placeholder="APxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                />
                <FormTextField
                  value={apiKeySid}
                  onChange={(event) => setApiKeySid(event.target.value)}
                  label="Twilio API Key SID"
                  placeholder="SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                />
                <FormTextField
                  value={apiKeySecret}
                  onChange={(event) => setApiKeySecret(event.target.value)}
                  label="Twilio API Key Secret"
                  type="password"
                  placeholder="Twilio API key secret"
                />
              </>
            )}

            {providerType === "plivo" && (
              <>
                <FormTextField
                  value={authId}
                  onChange={(event) => setAuthId(event.target.value)}
                  label="Plivo Auth ID"
                  placeholder="MAMUY0XXXXXXXXXXXXXXXX"
                />
                <FormTextField
                  value={authToken}
                  onChange={(event) => setAuthToken(event.target.value)}
                  label="Plivo Auth Token"
                  type="password"
                  placeholder="Plivo auth token"
                />
                <FormTextField
                  value={fromNumber}
                  onChange={(event) => setFromNumber(event.target.value)}
                  label="Default From Number"
                  placeholder="+15551234567"
                />
                <FormTextField
                  value={appId}
                  onChange={(event) => setAppId(event.target.value)}
                  label="Plivo Application ID (Optional)"
                  placeholder="App ID for XML voice routing"
                />
                <FormTextField
                  value={endpointUsername}
                  onChange={(event) => setEndpointUsername(event.target.value)}
                  label="Plivo WebRTC Endpoint Username"
                  placeholder="agent_endpoint_username"
                />
                <FormTextField
                  value={endpointPassword}
                  onChange={(event) => setEndpointPassword(event.target.value)}
                  label="Plivo WebRTC Endpoint Password"
                  type="password"
                  placeholder="Endpoint password"
                />
              </>
            )}

            {providerType === "vonage" && (
              <>
                <FormTextField
                  value={accountSid}
                  onChange={(event) => setAccountSid(event.target.value)}
                  label="Vonage API Key"
                  placeholder="API key"
                />
                <FormTextField
                  value={authToken}
                  onChange={(event) => setAuthToken(event.target.value)}
                  label="Vonage API Secret"
                  type="password"
                  placeholder="API secret"
                />
                <FormTextField
                  value={fromNumber}
                  onChange={(event) => setFromNumber(event.target.value)}
                  label="Default From Number"
                  placeholder="+15551234567"
                />
              </>
            )}
            {editingProviderId ? (
              <MuiButton type="button" variant="text" onClick={resetProviderForm}>
                Cancel Edit
              </MuiButton>
            ) : null}
          </Box>
          <TableContainer component={Paper} variant="outlined" sx={{ mt: 4 }}>
            <Table>
              <TableHead>
                <TableRow>
                  <TableCell>Name</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Failover Priority</TableCell>
                  <TableCell>Fallback</TableCell>
                  <TableCell align="right">Action</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={6}>
                      <LoadingState label="Loading providers..." />
                    </TableCell>
                  </TableRow>
                ) : null}
                {providers.map((provider, index) => (
                  <TableRow hover key={provider.id}>
                    <TableCell>{provider.display_name}</TableCell>
                    <TableCell sx={{ textTransform: "capitalize" }}>{provider.provider_type}</TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">
                        {provider.status}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <FormTextField
                        type="number"
                        value={provider.failover_priority ?? index + 1}
                        inputProps={{ min: 1 }}
                        onChange={(event) =>
                          setProviders((prev) =>
                            prev.map((item) =>
                              item.id === provider.id
                                ? { ...item, failover_priority: Number(event.target.value) }
                                : item
                            )
                          )
                        }
                        sx={{ maxWidth: 120 }}
                      />
                    </TableCell>
                    <TableCell>
                      <FormControlLabel
                        label=""
                        control={
                          <Checkbox
                            size="medium"
                            checked={Boolean(provider.is_fallback)}
                            onChange={(event) =>
                              setProviders((prev) =>
                                prev.map((item) =>
                                  item.id === provider.id ? { ...item, is_fallback: event.target.checked } : item
                                )
                              )
                            }
                          />
                        }
                      />
                    </TableCell>
                    <TableCell align="right">
                      <Stack direction="row" spacing={1} justifyContent="flex-end">
                        <MuiButton
                          type="button"
                          variant="outlined"
                          size="medium"
                          onClick={() => startEditProvider(provider)}
                        >
                          Edit
                        </MuiButton>
                        <MuiButton
                          type="button"
                          variant="outlined"
                          color="error"
                          size="medium"
                          onClick={() => void deleteProvider(provider.id)}
                        >
                          Delete
                        </MuiButton>
                        <MuiButton type="button" variant="outlined" size="medium" onClick={() => void testProvider(provider.id)}>
                          Test Connection
                        </MuiButton>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
                {providers.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6}>
                      <EmptyState title="No providers" description="No providers configured for this tenant yet." />
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableBody>
            </Table>
          </TableContainer>
          <Stack direction="row" spacing={2} sx={{ mt: 3 }}>
            <Button
              type="button"
              onClick={() => void saveFailover()}
            >
              Save Failover Policy
            </Button>
            <MuiButton
              type="button"
              variant="outlined"
              onClick={() => void loadProviders()}
              disabled={loading}
            >
              {loading ? "Loading..." : "Refresh"}
            </MuiButton>
          </Stack>
          {messageState ? <Box sx={{ mt: 2 }}><Alert severity={messageState.severity}>{messageState.text}</Alert></Box> : null}
        </SectionCard>

        <SectionCard title="Number Provisioning" subtitle="1) Select provider, 2) fetch available numbers or enter a manual number, 3) sync selected numbers into tenant settings.">
          <Stack spacing={2}>
            <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr auto auto" }, alignItems: "center" }}>
              <FormSelect
                label="Provider"
                value={selectedProviderForFetch}
                onChange={(event) => setSelectedProviderForFetch(event.target.value)}
                options={[
                  { value: "", label: "-- Select Provider --" },
                  ...providers.map((provider) => ({ label: provider.display_name, value: provider.id })),
                ]}
              />
              <FormTextField
                label="Manual Phone Number (e.g. +919876543210)"
                value={manualPhoneNumber}
                onChange={(event) => setManualPhoneNumber(event.target.value)}
                placeholder="+919876543210"
              />
              <Button type="button" onClick={() => void fetchAvailableProviderNumbers()} sx={{ whiteSpace: "nowrap", height: 48, my: 0.75 }}>
                Fetch Numbers
              </Button>
              <Button type="button" onClick={() => void syncSelectedNumbers()} sx={{ whiteSpace: "nowrap", height: 48, my: 0.75 }}>
                Assign / Sync Number
              </Button>
            </Box>
            <Paper variant="outlined" sx={{ p: 2 }}>
              {availableNumbers.length === 0 ? (
                <Typography variant="body2" color="text.secondary">No fetched numbers yet. Enter a manual phone number above and click &quot;Assign Selected / Manual Number&quot;.</Typography>
              ) : (
                <Stack spacing={1}>
                  {availableNumbers.map((number) => (
                    <FormControlLabel
                      key={number.phone_number}
                      control={
                        <Checkbox
                          checked={pickedNumbers.includes(number.phone_number)}
                          onChange={(event) =>
                            setPickedNumbers((prev) =>
                              event.target.checked ? [...prev, number.phone_number] : prev.filter((item) => item !== number.phone_number)
                            )
                          }
                        />
                      }
                      label={`${number.phone_number}${number.friendly_name ? ` (${number.friendly_name})` : ""}`}
                    />
                  ))}
                </Stack>
              )}
            </Paper>
          </Stack>
        </SectionCard>

        <SectionCard title="Validation & Status" subtitle="Test provider credentials and verify specific number ownership.">
          <Stack spacing={2}>
            <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr auto" }, alignItems: "center" }}>
              <FormSelect
                label="Provider"
                value={testingProviderId}
                onChange={(event) => setTestingProviderId(event.target.value)}
                options={[
                  { value: "", label: "-- Select Provider --" },
                  ...providers.map((provider) => ({ label: provider.display_name, value: provider.id })),
                ]}
              />
              <FormSelect
                label="Provider Number (optional)"
                value={testingNumberId}
                onChange={(event) => setTestingNumberId(event.target.value)}
                options={[
                  { label: "None (provider only)", value: "" },
                  ...providers
                    .find((provider) => provider.id === testingProviderId)
                    ?.numbers?.map((number) => ({ label: number.phone_number, value: number.id })) ?? [],
                ]}
              />
              <Button type="button" onClick={() => void testProvider(testingProviderId)} disabled={!testingProviderId} sx={{ whiteSpace: "nowrap", height: 48, my: 0.75 }}>
                Test Connection
              </Button>
            </Box>

            <TableContainer component={Paper} variant="outlined">
              <Table size="medium">
                <TableHead>
                  <TableRow>
                    <TableCell>Provider</TableCell>
                    <TableCell>Phone Number</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Validated</TableCell>
                    <TableCell>Last Error</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {providers.flatMap((provider) =>
                    (provider.numbers ?? []).map((number) => (
                      <TableRow key={`${provider.id}-${number.id}`}>
                        <TableCell>{provider.display_name}</TableCell>
                        <TableCell>{number.phone_number}</TableCell>
                        <TableCell><StatusBadge label={number.status} /></TableCell>
                        <TableCell>{number.is_validated ? "Yes" : "No"}</TableCell>
                        <TableCell>{number.last_error_message || "-"}</TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </TableContainer>
          </Stack>
        </SectionCard>

        <SectionCard title="Agent Number Assignment" subtitle="Assign one active, validated number per agent.">
          <Stack spacing={2}>
            <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr auto" }, alignItems: "center" }}>
              <FormSelect
                label="Agent"
                value={agentId}
                onChange={(event) => setAgentId(event.target.value)}
                options={[
                  { value: "", label: "-- Select Agent --" },
                  ...agents.map((member) => ({
                    value: member.id,
                    label: member.company_number,
                  })),
                ]}
              />
              <FormSelect
                label="Validated Number"
                value={agentNumberId}
                onChange={(event) => setAgentNumberId(event.target.value)}
                options={[
                  { value: "", label: "-- Select Validated Number --" },
                  ...validatedNumbers.map((number) => ({
                    value: number.id,
                    label: `${number.phone_number} (${number.status})`,
                  })),
                ]}
              />
              <Button type="button" onClick={() => void assignAgentNumber()} sx={{ whiteSpace: "nowrap", height: 48, my: 0.75, minWidth: 120 }}>
                Assign
              </Button>
            </Box>

            <TableContainer component={Paper} variant="outlined">
              <Table size="medium">
                <TableHead>
                  <TableRow>
                    <TableCell>Agent</TableCell>
                    <TableCell>Number</TableCell>
                    <TableCell>Status</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {agentAssignments.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={3}>No assignments yet.</TableCell>
                    </TableRow>
                  ) : (
                    agentAssignments.map((assignment) => (
                      <TableRow key={assignment.id}>
                        <TableCell>{assignment.agent?.company_number || "-"}</TableCell>
                        <TableCell>{assignment.number?.phone_number || "-"}</TableCell>
                        <TableCell><StatusBadge label={assignment.status} /></TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </TableContainer>
          </Stack>
        </SectionCard>

        <SectionCard title="Campaign-Agent Mapping" subtitle="Map campaign agents to numbers; dialer uses each agent's number as the From value.">
          <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr auto" }, alignItems: "center" }}>
            <FormSelect
              label="Campaign"
              value={campaignId}
              onChange={(event) => setCampaignId(event.target.value)}
              options={[
                { value: "", label: "-- Select Campaign --" },
                ...campaigns.map((campaign) => ({ value: campaign.id, label: campaign.name })),
              ]}
            />
            <FormSelect
              label="Agent"
              value={campaignAgentId}
              onChange={(event) => setCampaignAgentId(event.target.value)}
              options={[
                { value: "", label: "-- Select Agent --" },
                ...agents.map((member) => ({
                  value: member.id,
                  label: member.company_number,
                })),
              ]}
            />
            <Button type="button" onClick={() => void mapCampaignAgent()} sx={{ whiteSpace: "nowrap", height: 48, my: 0.75 }}>Save Mapping</Button>
          </Box>
        </SectionCard>
      </Stack>
    </AppShell>
  );
}
