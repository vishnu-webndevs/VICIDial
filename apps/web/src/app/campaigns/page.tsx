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
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { CreateGuard } from "@/components/plans/CreateGuard";
import { EmptyPanel, KpiCard, ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";
import { listAgents, listCampaigns, listMessageTemplates, listMetaTemplates, listProviderAccounts, pauseCampaign, startCampaign, syncMetaTemplates } from "@/lib/product-api";
import type { AgentEntity, Campaign, CampaignStatusPayload, LeadList, MessageTemplate, MetaWhatsappTemplate } from "@/types/product";

type PopupState = "new-campaign" | "command-center" | null;
type CampaignTab = "all" | "active" | "paused" | "draft";

type CampaignStats = {
  totals?: {
    pending?: number;
    in_progress?: number;
    completed?: number;
    failed?: number;
    connected?: number;
    calls?: number;
  };
  [key: string]: unknown;
};

type NewCampaignForm = {
  name: string;
  type: Campaign["type"];
  retry_limit: number;
  queue_size: number;
  calls_per_minute: number;
  preferred_provider_account_id: string;
  message_template_key: string;
  message_content: string;
  message_channel: "sms" | "whatsapp";
  message_use_meta_template: boolean;
  message_meta_template_id: string;
  message_media_file: File | null;
};

const defaultCampaignForm: NewCampaignForm = {
  name: "",
  type: "outbound_call",
  retry_limit: 2,
  queue_size: 20,
  calls_per_minute: 20,
  preferred_provider_account_id: "",
  message_template_key: "",
  message_content: "",
  message_channel: "sms",
  message_use_meta_template: false,
  message_meta_template_id: "",
  message_media_file: null,
};
const ACTIVE_STATUSES: Campaign["status"][] = ["running"];

function renderMessagePreview(template: string, variables: Record<string, string>): string {
  return template.replace(/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/g, (_, key: string) => String(variables[key] ?? ""));
}

function normalizeCampaignType(type: Campaign["type"]): Campaign["type"] {
  return type === "auto" || type === "manual" ? "outbound_call" : type;
}

function campaignTypeLabel(type: Campaign["type"]): string {
  const normalized = normalizeCampaignType(type);
  if (normalized === "outbound_call") return "Outbound Call";
  if (normalized === "sms") return "SMS";
  if (normalized === "whatsapp") return "WhatsApp Message";
  if (normalized === "outreach") return "Outreach";
  return normalized;
}

export default function CampaignsPage() {
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [leadLists, setLeadLists] = useState<LeadList[]>([]);
  const [agents, setAgents] = useState<AgentEntity[]>([]);
  const [providers, setProviders] = useState<Array<{ id: string; provider_type: string; display_name: string; status: string }>>([]);
  const [templates, setTemplates] = useState<MessageTemplate[]>([]);
  const [metaTemplates, setMetaTemplates] = useState<MetaWhatsappTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");
  const [tab, setTab] = useState<CampaignTab>("all");
  const [popup, setPopup] = useState<PopupState>(null);
  const [step, setStep] = useState(1);
  const [creating, setCreating] = useState(false);
  const [editingCampaignId, setEditingCampaignId] = useState<string | null>(null);
  const [campaignForm, setCampaignForm] = useState<NewCampaignForm>(defaultCampaignForm);
  const [selectedLists, setSelectedLists] = useState<string[]>([]);
  const [selectedFromAgentId, setSelectedFromAgentId] = useState("");
  const [commandCampaign, setCommandCampaign] = useState<Campaign | null>(null);
  const [commandStats, setCommandStats] = useState<CampaignStats | null>(null);
  const [commandAgents, setCommandAgents] = useState<CampaignStatusPayload["agents"]>([]);
  const [commandLoading, setCommandLoading] = useState(false);
  const [startingCampaignId, setStartingCampaignId] = useState<string | null>(null);

  const fetchLeadLists = useCallback(async (): Promise<LeadList[]> => {
    const { token, tenantId } = getTenantContext();
    const response = await apiRequest<{ data: LeadList[] }>("/lead-lists", { token, tenantId });
    return response.data;
  }, []);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [campaignData, listResponse, providerData] = await Promise.all([
        listCampaigns(),
        fetchLeadLists(),
        listProviderAccounts().catch(() => []),
      ]);
      setCampaigns(campaignData);
      setLeadLists(listResponse);
      setProviders(providerData);
      try {
        const agentData = await listAgents();
        setAgents(agentData);
      } catch {
        setAgents([]);
      }
      try {
        const metaTemplateData = await listMetaTemplates();
        setMetaTemplates(metaTemplateData);
      } catch {
        setMetaTemplates([]);
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load campaigns.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }, [fetchLeadLists]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  const isOutboundCallCampaign = useMemo(() => {
    return campaignForm.type === "outbound_call" || campaignForm.type === "auto" || campaignForm.type === "manual";
  }, [campaignForm.type]);

  const resolvedMessageChannel = useMemo<"sms" | "whatsapp">(() => {
    if (campaignForm.type === "sms") return "sms";
    if (campaignForm.type === "whatsapp") return "whatsapp";
    return campaignForm.message_channel;
  }, [campaignForm.message_channel, campaignForm.type]);

  const activeProviders = useMemo(() => providers.filter((p) => p.status === "active"), [providers]);

  const loadTemplates = useCallback(async (channel: "sms" | "whatsapp") => {
    try {
      const items = await listMessageTemplates({ channel, active: true });
      setTemplates(items);
    } catch {
      setTemplates([]);
    }
  }, []);

  useEffect(() => {
    if (popup !== "new-campaign") return;
    void loadTemplates(resolvedMessageChannel);
  }, [loadTemplates, popup, resolvedMessageChannel]);

  const stats = useMemo(() => {
    const active = campaigns.filter((item) => ACTIVE_STATUSES.includes(item.status)).length;
    const paused = campaigns.filter((item) => item.status === "paused").length;
    const draft = campaigns.filter((item) => item.status === "draft").length;
    return {
      total: campaigns.length,
      active,
      paused,
      draft,
    };
  }, [campaigns]);

  const filteredCampaigns = useMemo(() => {
    if (tab === "all") return campaigns;
    if (tab === "active") return campaigns.filter((item) => ACTIVE_STATUSES.includes(item.status));
    return campaigns.filter((item) => item.status === tab);
  }, [campaigns, tab]);
  const startableCampaign = useMemo(
    () => campaigns.find((item) => !ACTIVE_STATUSES.includes(item.status)),
    [campaigns]
  );

  function openNewCampaignPopup() {
    setPopup("new-campaign");
    setStep(1);
    setEditingCampaignId(null);
    setCommandCampaign(null);
    setCampaignForm(defaultCampaignForm);
    setSelectedLists([]);
    setSelectedFromAgentId("");
  }

  function openEditCampaignPopup(campaign: Campaign) {
    setPopup("new-campaign");
    setStep(1);
    setEditingCampaignId(campaign.id);
    setCommandCampaign(null);
    setCampaignForm({
      name: campaign.name ?? "",
      type: normalizeCampaignType((campaign.type ?? "outbound_call") as Campaign["type"]),
      retry_limit: Number(campaign.retry_limit ?? 2),
      queue_size: Number(campaign.queue_size ?? 20),
      calls_per_minute: Number(campaign.calls_per_minute ?? 20),
      preferred_provider_account_id: String(campaign.preferred_provider_account_id ?? campaign.provider_account_id ?? ""),
      message_template_key: String(campaign.message_template_key ?? ""),
      message_content: String(campaign.message_content ?? ""),
      message_channel: (campaign.channel === "whatsapp" ? "whatsapp" : "sms") as "sms" | "whatsapp",
      message_use_meta_template: Boolean(campaign.message_use_meta_template),
      message_meta_template_id: String(campaign.message_meta_template_id ?? ""),
      message_media_file: null,
    });
    setSelectedLists(campaign.lead_list_ids ?? []);
    setSelectedFromAgentId("");
    if (campaign.type === "outbound_call" || campaign.type === "auto" || campaign.type === "manual") {
      void prefillFromAgentIdentity(campaign.id);
    }
  }

  async function prefillFromAgentIdentity(campaignId: string) {
    try {
      const { token, tenantId } = getTenantContext();
      const response = await apiRequest<{ data: Array<{ agent: { id: string } | null }> }>(`/campaigns/${campaignId}/agent-assignments`, {
        token,
        tenantId,
      });
      const firstAgentId = (response.data ?? [])
        .map((row) => row.agent?.id ?? "")
        .find((id) => id !== "");
      if (firstAgentId) {
        setSelectedFromAgentId(firstAgentId);
      }
    } catch {
      return;
    }
  }

  async function submitCampaign() {
    if (!campaignForm.name.trim()) {
      setMessage("Campaign name is required.");
      setMessageTone("error");
      return;
    }
    if (selectedLists.length === 0) {
      setMessage("Please select at least one lead list.");
      setMessageTone("error");
      return;
    }
    if (!isOutboundCallCampaign) {
      if (!campaignForm.preferred_provider_account_id) {
        setMessage("Please select a provider/connection.");
        setMessageTone("error");
        return;
      }
      if (!campaignForm.message_content.trim() && !campaignForm.message_template_key.trim()) {
        setMessage("Please enter message content or select a template.");
        setMessageTone("error");
        return;
      }
    }
    setCreating(true);
    try {
      const selectedListNames = leadLists
        .filter((list) => selectedLists.includes(list.id))
        .map((list) => list.name);
      const { token, tenantId } = getTenantContext();
      
      const formData = new FormData();
      if (editingCampaignId) {
        formData.append("_method", "PATCH");
      }
      formData.append("name", campaignForm.name.trim());
      formData.append("type", campaignForm.type);
      formData.append("status", "draft");
      formData.append("retry_limit", String(campaignForm.retry_limit));
      formData.append("queue_size", String(campaignForm.queue_size));
      formData.append("calls_per_minute", String(campaignForm.calls_per_minute));
      formData.append("lead_list_name", selectedListNames.join(", "));
      
      selectedLists.forEach((id) => formData.append("lead_list_ids[]", id));
      
      if (!isOutboundCallCampaign) {
        if (campaignForm.preferred_provider_account_id) {
          formData.append("preferred_provider_account_id", campaignForm.preferred_provider_account_id);
        }
        if (campaignForm.message_content.trim()) {
          formData.append("message_content", campaignForm.message_content.trim());
        }
        if (campaignForm.message_template_key.trim()) {
          formData.append("message_template_key", campaignForm.message_template_key.trim());
        }
        formData.append("message_channel", campaignForm.type === "outreach" ? resolvedMessageChannel : (campaignForm.type === "whatsapp" ? "whatsapp" : "sms"));
        formData.append("message_use_meta_template", campaignForm.type === "whatsapp" && campaignForm.message_use_meta_template ? "1" : "0");
        if (campaignForm.type === "whatsapp" && campaignForm.message_use_meta_template && campaignForm.message_meta_template_id) {
          formData.append("message_meta_template_id", campaignForm.message_meta_template_id);
        }
        if (campaignForm.message_media_file) {
          formData.append("message_media_file", campaignForm.message_media_file);
        }
      }

      const createOrUpdateResponse = await apiRequest<{ data: Campaign }>(editingCampaignId ? `/campaigns/${editingCampaignId}` : "/campaigns", {
        method: "POST", // POST with _method spoofing is required for FormData + PATCH in Laravel
        token,
        tenantId,
        body: formData,
      });

      if (isOutboundCallCampaign && selectedFromAgentId) {
        const selectedAgent = agents.find((agent) => agent.id === selectedFromAgentId);
        const selectedNumberId = selectedAgent?.default_number?.id;
        if (!selectedNumberId) {
          throw new Error("Selected From Agent does not have an assigned validated number.");
        }
        await apiRequest(`/campaigns/${createOrUpdateResponse.data.id}/agent-assignments`, {
          method: "PUT",
          token,
          tenantId,
          body: {
            assignments: [
              {
                agent_id: selectedFromAgentId,
                provider_phone_number_id: selectedNumberId,
              },
            ],
          },
        });
      }
      setMessage(editingCampaignId ? "Campaign updated." : "Campaign created.");
      setMessageTone("success");
      setPopup(null);
      setEditingCampaignId(null);
      await loadData();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to create campaign.");
      setMessageTone("error");
    } finally {
      setCreating(false);
    }
  }

  const loadCommandCenter = useCallback(async (campaign: Campaign) => {
    setPopup("command-center");
    setCommandCampaign(campaign);
    setCommandLoading(true);
    try {
      const { token, tenantId } = getTenantContext();
      const [statsResponse, statusResponse] = await Promise.all([
        apiRequest<{ data?: CampaignStats; totals?: CampaignStats["totals"] }>(`/campaigns/${campaign.id}/stats`, {
          token,
          tenantId,
        }),
        apiRequest<{ data: CampaignStatusPayload }>(`/campaigns/${campaign.id}/status`, {
          token,
          tenantId,
        }),
      ]);
      setCommandStats((statsResponse.data as CampaignStats | undefined) ?? { totals: statsResponse.totals ?? {} });
      setCommandAgents(statusResponse.data.agents ?? []);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load command center.");
      setMessageTone("error");
    } finally {
      setCommandLoading(false);
    }
  }, []);

  useEffect(() => {
    if (popup !== "command-center" || !commandCampaign) {
      return;
    }
    const timer = setInterval(() => {
      void loadCommandCenter(commandCampaign);
    }, 10000);
    return () => clearInterval(timer);
  }, [popup, commandCampaign, loadCommandCenter]);

  async function onPauseCampaign() {
    if (!commandCampaign) return;
    try {
      await pauseCampaign(commandCampaign.id);
      setMessage("Campaign paused.");
      setMessageTone("success");
      await loadData();
      setPopup(null);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to pause campaign.");
      setMessageTone("error");
    }
  }

  async function onStartCampaign(campaign: Campaign) {
    try {
      setStartingCampaignId(campaign.id);
      setMessage(`Starting campaign "${campaign.name}"...`);
      setMessageTone("neutral");
      await startCampaign(campaign.id);
      setMessage(`Campaign "${campaign.name}" started.`);
      setMessageTone("success");
      await loadData();
      await loadCommandCenter({ ...campaign, status: "running" });
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to start campaign.");
      setMessageTone("error");
    } finally {
      setStartingCampaignId(null);
    }
  }

  const hasRunningCampaign = campaigns.some((item) => ACTIVE_STATUSES.includes(item.status));

  return (
    <AppShell requiredPermissions={["call.view"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}

      <SectionCard title="Campaigns" subtitle="Manage campaign creation and live command center operations.">
        <Box sx={{ mb: 2, display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(4, 1fr)" } }}>
          <KpiCard label="Total" value={stats.total} />
          <KpiCard label="Active" value={stats.active} />
          <KpiCard label="Paused" value={stats.paused} />
          <KpiCard label="Draft" value={stats.draft} />
        </Box>

        <Box sx={{ mb: 2, display: "flex", flexWrap: "wrap", gap: 2, alignItems: "center", justifyContent: "space-between" }}>
          <Stack
            direction="row"
            spacing={2}
            sx={{
              flexWrap: { xs: "nowrap", sm: "wrap" },
              overflowX: { xs: "auto", sm: "visible" },
              maxWidth: "100%",
              pb: { xs: 0.5, sm: 0 },
            }}
          >
            {(["all", "active", "paused", "draft"] as CampaignTab[]).map((value) => (
              <Box
                key={value}
                component="button"
                type="button"
                onClick={() => setTab(value)}
                sx={{
                  border: 0,
                  bgcolor: "transparent",
                  pb: 0.75,
                  borderBottom: tab === value ? "2px solid" : "2px solid transparent",
                  borderColor: tab === value ? "primary.main" : "transparent",
                  color: tab === value ? "text.primary" : "text.secondary",
                  textTransform: "capitalize",
                  fontWeight: 600,
                  cursor: "pointer",
                }}
              >
                {value}
              </Box>
            ))}
          </Stack>
          <Stack
            direction="row"
            spacing={1.5}
            sx={{
              ml: "auto",
              width: { xs: "100%", sm: "auto" },
              justifyContent: { xs: "flex-end", sm: "flex-end" },
              alignItems: "center",
              flexWrap: "wrap",
            }}
          >
            <CreateGuard featureKey="max_campaigns" fallbackLabel="New Campaign">
              <MuiButton variant="contained" onClick={openNewCampaignPopup}>
                New Campaign
              </MuiButton>
            </CreateGuard>
            {startableCampaign ? (
              <MuiButton
                variant="contained"
                color="success"
                onClick={() => void onStartCampaign(startableCampaign)}
                disabled={Boolean(startingCampaignId) || hasRunningCampaign}
              >
                {startingCampaignId === startableCampaign.id ? "Starting..." : "Start Campaign"}
              </MuiButton>
            ) : null}
          </Stack>
        </Box>

        {loading ? (
          <Typography variant="body2" color="text.secondary">Loading campaigns...</Typography>
        ) : filteredCampaigns.length === 0 ? (
          <EmptyPanel title="No campaigns found" description="No campaigns are available in this filter." />
        ) : (
          <Paper variant="outlined" sx={{ overflowX: "auto" }}>
            <Table size="medium">
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell>Name</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>Lead List</TableCell>
                  <TableCell align="right">Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {filteredCampaigns.map((campaign) => {
                  const isThisCampaignActive = ACTIVE_STATUSES.includes(campaign.status);
                  return (
                    <TableRow
                      key={campaign.id}
                      hover
                      onClick={() => {
                        if (isThisCampaignActive && popup !== "new-campaign") {
                          void loadCommandCenter(campaign);
                        }
                      }}
                      sx={{ cursor: isThisCampaignActive ? "pointer" : "default" }}
                    >
                      <TableCell>{campaign.name}</TableCell>
                      <TableCell><StatusBadge label={campaign.status} /></TableCell>
                      <TableCell>{campaignTypeLabel(campaign.type)}</TableCell>
                      <TableCell>{campaign.lead_list_name || "-"}</TableCell>
                      <TableCell align="right">
                        <Stack
                          direction={{ xs: "column", sm: "row" }}
                          spacing={1}
                          justifyContent="flex-end"
                          alignItems={{ xs: "stretch", sm: "center" }}
                        >
                          <MuiButton
                            size="medium"
                            variant="outlined"
                            onClick={(event) => {
                              event.stopPropagation();
                              openEditCampaignPopup(campaign);
                            }}
                          >
                            Edit
                          </MuiButton>
                          {!isThisCampaignActive ? (
                            <MuiButton
                              size="medium"
                              variant="contained"
                              color="success"
                              onClick={(event) => {
                                event.stopPropagation();
                                void onStartCampaign(campaign);
                              }}
                              disabled={startingCampaignId === campaign.id}
                            >
                              {startingCampaignId === campaign.id ? "Starting..." : "Start"}
                            </MuiButton>
                          ) : (
                            <MuiButton
                              size="medium"
                              variant="outlined"
                              onClick={(event) => {
                                event.stopPropagation();
                                void loadCommandCenter(campaign);
                              }}
                            >
                              Monitor
                            </MuiButton>
                          )}
                        </Stack>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </Paper>
        )}
      </SectionCard>

      <Modal
        open={popup === "new-campaign"}
        onClose={() => {
          setPopup(null);
          setEditingCampaignId(null);
        }}
        title={`${editingCampaignId ? "Edit Campaign" : "New Campaign"} - Step ${step} of 3`}
      >
        <Box sx={{ display: "grid", gap: 1.25 }}>
          {step === 1 ? (
            <>
              <Typography variant="caption" color="text.secondary">Campaign Name *</Typography>
              <TextField
                required
                size="medium"
                placeholder="Campaign Name"
                value={campaignForm.name}
                onChange={(event) => setCampaignForm((prev) => ({ ...prev, name: event.target.value }))}
              />
              <Typography variant="caption" color="text.secondary">Type</Typography>
              <TextField
                select
                size="medium"
                value={campaignForm.type}
                onChange={(event) =>
                  setCampaignForm((prev) => ({
                    ...prev,
                    type: event.target.value as Campaign["type"],
                    preferred_provider_account_id: "",
                    message_template_key: "",
                    message_content: "",
                    message_channel: "sms",
                    message_use_meta_template: false,
                    message_meta_template_id: "",
                  }))
                }
              >
                <MenuItem value="outbound_call">Outbound Call</MenuItem>
                <MenuItem value="sms">SMS</MenuItem>
                <MenuItem value="whatsapp">WhatsApp Message</MenuItem>
                <MenuItem value="outreach">Outreach</MenuItem>
              </TextField>
              <Stack direction={{ xs: "column", md: "row" }} spacing={1}>
                <Box sx={{ flex: 1, display: "grid", gap: 0.5 }}>
                  <Typography variant="caption" color="text.secondary">Retry Limit</Typography>
                  <TextField
                    size="medium"
                    type="number"
                    value={campaignForm.retry_limit}
                    onChange={(event) =>
                      setCampaignForm((prev) => ({ ...prev, retry_limit: Number(event.target.value) }))
                    }
                    inputProps={{ min: 0 }}
                    fullWidth
                  />
                </Box>
                <Box sx={{ flex: 1, display: "grid", gap: 0.5 }}>
                  <Typography variant="caption" color="text.secondary">Queue Size</Typography>
                  <TextField
                    size="medium"
                    type="number"
                    value={campaignForm.queue_size}
                    onChange={(event) =>
                      setCampaignForm((prev) => ({ ...prev, queue_size: Number(event.target.value) }))
                    }
                    inputProps={{ min: 1 }}
                    fullWidth
                  />
                </Box>
                <Box sx={{ flex: 1, display: "grid", gap: 0.5 }}>
                  <Typography variant="caption" color="text.secondary">{isOutboundCallCampaign ? "Calls/Minute" : "Messages/Minute"}</Typography>
                  <TextField
                    size="medium"
                    type="number"
                    value={campaignForm.calls_per_minute}
                    onChange={(event) =>
                      setCampaignForm((prev) => ({ ...prev, calls_per_minute: Number(event.target.value) }))
                    }
                    inputProps={{ min: 1 }}
                    fullWidth
                  />
                </Box>
              </Stack>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <Typography variant="body2" color="text.secondary">
                {isOutboundCallCampaign
                  ? "Select lead lists for this campaign and choose which agent identity should be used as outbound caller."
                  : "Select lead lists, provider connection, and message/template for this campaign."}
              </Typography>
              <Box sx={{ maxHeight: 220, overflowY: "auto", border: 1, borderColor: "divider", borderRadius: 1, p: 1 }}>
                {leadLists.length === 0 ? (
                  <Typography variant="body2" color="text.secondary">No lead lists found.</Typography>
                ) : (
                  leadLists.map((list) => (
                    <FormControlLabel
                      key={list.id}
                      control={
                        <Checkbox
                          checked={selectedLists.includes(list.id)}
                          onChange={() =>
                            setSelectedLists((prev) =>
                              prev.includes(list.id)
                                ? prev.filter((value) => value !== list.id)
                                : [...prev, list.id]
                            )
                          }
                        />
                      }
                      label={`${list.name} (${list.leads_count ?? 0})`}
                    />
                  ))
                )}
              </Box>
              {isOutboundCallCampaign ? (
                <Box sx={{ borderTop: 1, borderColor: "divider", pt: 1.25, mt: 0.25 }}>
                  <Typography variant="caption" color="text.secondary">From Agent (Identity)</Typography>
                  <TextField
                    select
                    size="medium"
                    value={selectedFromAgentId}
                    onChange={(event) => setSelectedFromAgentId(event.target.value)}
                    fullWidth
                    sx={{ mt: 0.5 }}
                  >
                    <MenuItem value="">Auto-select from available agents</MenuItem>
                    {agents.map((agent) => (
                      <MenuItem key={agent.id} value={agent.id}>
                        {agent.company_number}
                        {agent.default_number?.phone_number ? ` (${agent.default_number.phone_number})` : " (no number assigned)"}
                      </MenuItem>
                    ))}
                  </TextField>
                  <Typography variant="caption" color="text.secondary" sx={{ mt: 0.5, display: "block" }}>
                    Agent is stored as identity; outbound caller ID is taken from that agent&apos;s assigned validated number.
                  </Typography>
                </Box>
              ) : (
                <Box sx={{ borderTop: 1, borderColor: "divider", pt: 1.25, mt: 0.25, display: "grid", gap: 1.25 }}>
                  {campaignForm.type === "outreach" ? (
                    <TextField
                      select
                      size="medium"
                      label="Outreach Channel"
                      value={campaignForm.message_channel}
                      onChange={(e) => setCampaignForm((p) => ({ ...p, message_channel: e.target.value as "sms" | "whatsapp", preferred_provider_account_id: "", message_template_key: "", message_content: "" }))}
                    >
                      <MenuItem value="sms">SMS</MenuItem>
                      <MenuItem value="whatsapp">WhatsApp</MenuItem>
                    </TextField>
                  ) : null}

                  <TextField
                    select
                    size="medium"
                    label="Provider / Connection"
                    value={campaignForm.preferred_provider_account_id}
                    onChange={(e) => setCampaignForm((p) => ({ ...p, preferred_provider_account_id: e.target.value }))}
                  >
                    <MenuItem value="">Select Provider</MenuItem>
                    {activeProviders
                      .filter((p) => (resolvedMessageChannel === "sms" ? p.provider_type === "twilio" : ["twilio", "meta_whatsapp"].includes(p.provider_type)))
                      .map((p) => (
                        <MenuItem key={p.id} value={p.id}>
                          {p.display_name} ({p.provider_type})
                        </MenuItem>
                      ))}
                  </TextField>

                  {!campaignForm.message_use_meta_template && (
                    <TextField
                      select
                      size="medium"
                      label="Template (optional)"
                      value={campaignForm.message_template_key}
                      onChange={(e) =>
                        setCampaignForm((p) => ({
                          ...p,
                          message_template_key: e.target.value,
                          message_content: templates.find((t) => t.key === e.target.value)?.body || "",
                        }))
                      }
                    >
                      <MenuItem value="">Custom message...</MenuItem>
                      {templates
                        .filter((t) => t.channel === resolvedMessageChannel && t.is_active)
                        .filter((t) => (campaignForm.type === "outreach" ? String(t.category ?? "").toLowerCase() === "outreach" : true))
                        .map((t) => (
                          <MenuItem key={t.key} value={t.key}>
                            {t.name} ({t.key})
                          </MenuItem>
                        ))}
                    </TextField>
                  )}

                  <TextField
                    size="medium"
                    label="Message Content (optional)"
                    value={campaignForm.message_content}
                    onChange={(e) =>
                      setCampaignForm((p) => ({
                        ...p,
                        message_content: e.target.value,
                        message_template_key: e.target.value ? "" : p.message_template_key,
                      }))
                    }
                    multiline
                    minRows={4}
                    placeholder={"Hi {{first_name}},\n\nThis is {{company_name}}."}
                    disabled={campaignForm.message_use_meta_template}
                  />

                  {campaignForm.type === "whatsapp" && (
                    <Box sx={{ border: 1, borderColor: "primary.light", borderRadius: 1, p: 1.5, bgcolor: "action.hover" }}>
                      <FormControlLabel
                        control={
                          <Checkbox
                            size="small"
                            checked={campaignForm.message_use_meta_template}
                            onChange={(e) => setCampaignForm((p) => ({ ...p, message_use_meta_template: e.target.checked }))}
                          />
                        }
                        label={
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            Use Meta-Approved Template
                          </Typography>
                        }
                      />
                      {campaignForm.message_use_meta_template && (
                        <Box sx={{ mt: 1, display: "grid", gap: 1.25 }}>
                          <Box sx={{ display: "flex", gap: 1, alignItems: "center" }}>
                            <TextField
                              select
                              fullWidth
                              size="small"
                              label="Select Meta Template"
                              value={campaignForm.message_meta_template_id}
                              onChange={(e) => setCampaignForm((p) => ({ ...p, message_meta_template_id: e.target.value }))}
                              sx={{ flexGrow: 1 }}
                            >
                              <MenuItem value="">Choose a template...</MenuItem>
                              {metaTemplates.map((t) => (
                                <MenuItem key={t.id} value={t.id}>
                                  {t.template_name} ({t.language})
                                </MenuItem>
                              ))}
                            </TextField>
                            <MuiButton
                              variant="outlined"
                              size="small"
                              onClick={async () => {
                                try {
                                  setMessage("Syncing templates from Meta...");
                                  setMessageTone("neutral");
                                  const res = await syncMetaTemplates(campaignForm.preferred_provider_account_id || undefined);
                                  setMessage(`Success! Synced ${res.count} templates.`);
                                  setMessageTone("success");
                                  const data = await listMetaTemplates();
                                  setMetaTemplates(data);
                                } catch (err) {
                                  setMessage("Sync failed. Check credentials.");
                                  setMessageTone("error");
                                }
                              }}
                              sx={{ height: 40, whiteSpace: "nowrap" }}
                            >
                              Sync from Meta
                            </MuiButton>
                          </Box>
                          
                          <Box sx={{ mt: 0.5 }}>
                            <Typography variant="caption" color="text.secondary" sx={{ mb: 0.5, display: "block" }}>
                              Media Header (Optional - JPG, PNG)
                            </Typography>
                            <input
                              type="file"
                              accept="image/*"
                              onChange={(e) => {
                                const file = e.target.files?.[0] || null;
                                setCampaignForm((p) => ({ ...p, message_media_file: file }));
                              }}
                              style={{ 
                                width: '100%', 
                                padding: '8px', 
                                border: '1px solid #ccc', 
                                borderRadius: '4px',
                                fontSize: '14px'
                              }}
                            />
                            {campaignForm.message_media_file && (
                              <Typography variant="caption" color="primary" sx={{ mt: 0.5, display: "block" }}>
                                Selected: {campaignForm.message_media_file.name}
                              </Typography>
                            )}
                          </Box>
                        </Box>
                      )}
                    </Box>
                  )}

                  <Paper variant="outlined" sx={{ p: 1.5 }}>
                    <Typography variant="caption" color="text.secondary">Preview</Typography>
                    <Typography variant="body2" sx={{ mt: 0.75, whiteSpace: "pre-wrap" }}>
                      {(() => {
                        const sample = {
                          first_name: "John",
                          last_name: "Doe",
                          company_name: "Acme Inc",
                          phone: "+15551234567",
                          email: "john.doe@example.com",
                          campaign_name: campaignForm.name || "Campaign",
                          agent_name: "Agent",
                        };
                        const selectedTemplate = campaignForm.message_template_key
                          ? templates.find((t) => t.channel === resolvedMessageChannel && t.key === campaignForm.message_template_key)
                          : null;
                        const body = selectedTemplate ? selectedTemplate.body : campaignForm.message_content;
                        return body ? renderMessagePreview(body, sample) : "-";
                      })()}
                    </Typography>
                  </Paper>
                </Box>
              )}
            </>
          ) : null}

          {step === 3 ? (
            <Box sx={{ display: "grid", gap: 1 }}>
              <Typography variant="body2"><strong>Name:</strong> {campaignForm.name || "-"}</Typography>
              <Typography variant="body2"><strong>Type:</strong> {campaignForm.type}</Typography>
              <Typography variant="body2">
                <strong>Lead Lists:</strong>{" "}
                {leadLists
                  .filter((list) => selectedLists.includes(list.id))
                  .map((list) => list.name)
                  .join(", ") || "-"}
              </Typography>
              {isOutboundCallCampaign ? (
                <Typography variant="body2">
                  <strong>From Agent:</strong>{" "}
                  {selectedFromAgentId
                    ? (agents.find((agent) => agent.id === selectedFromAgentId)?.company_number ?? "-")
                    : "Auto-select"}
                </Typography>
              ) : (
                <>
                  <Typography variant="body2">
                    <strong>Channel:</strong> {resolvedMessageChannel}
                  </Typography>
                  <Typography variant="body2">
                    <strong>Provider:</strong>{" "}
                    {campaignForm.preferred_provider_account_id
                      ? (activeProviders.find((p) => p.id === campaignForm.preferred_provider_account_id)?.display_name ?? "-")
                      : "-"}
                  </Typography>
                    <Typography variant="body2" color="text.secondary">
                      Template:{" "}
                      <span style={{ color: "var(--text-primary)", fontWeight: 500 }}>
                        {campaignForm.message_use_meta_template
                          ? metaTemplates.find((t) => t.id === campaignForm.message_meta_template_id)?.template_name || "Meta Template Selected"
                          : templates.find((t) => t.key === campaignForm.message_template_key)?.name || "Custom / None"}
                      </span>
                    </Typography>
                </>
              )}
            </Box>
          ) : null}

          <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
            <MuiButton variant="outlined" disabled={step === 1} onClick={() => setStep((prev) => Math.max(1, prev - 1))}>
              Back
            </MuiButton>
            {step < 3 ? (
              <MuiButton
                variant="contained"
                onClick={() => setStep((prev) => Math.min(3, prev + 1))}
                disabled={
                  step === 2
                    ? selectedLists.length === 0 ||
                      (!isOutboundCallCampaign &&
                        (!campaignForm.preferred_provider_account_id ||
                          (!campaignForm.message_content.trim() && !campaignForm.message_template_key.trim())))
                    : false
                }
              >
                Next
              </MuiButton>
            ) : (
              <MuiButton variant="contained" disabled={creating} onClick={() => void submitCampaign()}>
                {creating ? "Submitting..." : "Submit"}
              </MuiButton>
            )}
          </Stack>
        </Box>
      </Modal>

      <Modal
        open={popup === "command-center"}
        onClose={() => setPopup(null)}
        title={commandCampaign ? `${commandCampaign.name} Command Center` : "Command Center"}
      >
        {!commandCampaign ? (
          <EmptyPanel title="No campaign selected" description="Select an active campaign row to monitor." />
        ) : commandLoading ? (
          <Typography variant="body2" color="text.secondary">Loading command center...</Typography>
        ) : (
          <Box sx={{ display: "grid", gap: 1.25 }}>
            <Box sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "repeat(2, minmax(0, 1fr))", md: "repeat(4, minmax(0, 1fr))" } }}>
              <Paper variant="outlined" sx={{ p: 1 }}>
                <Typography variant="caption">Pending</Typography>
                <Typography variant="h6">{commandStats?.totals?.pending ?? 0}</Typography>
              </Paper>
              <Paper variant="outlined" sx={{ p: 1 }}>
                <Typography variant="caption">In Progress</Typography>
                <Typography variant="h6">{commandStats?.totals?.in_progress ?? 0}</Typography>
              </Paper>
              <Paper variant="outlined" sx={{ p: 1 }}>
                <Typography variant="caption">Completed</Typography>
                <Typography variant="h6">{commandStats?.totals?.completed ?? 0}</Typography>
              </Paper>
              <Paper variant="outlined" sx={{ p: 1 }}>
                <Typography variant="caption">Failed</Typography>
                <Typography variant="h6">{commandStats?.totals?.failed ?? 0}</Typography>
              </Paper>
            </Box>
            {(commandStats?.totals?.failed ?? 0) > 0 ? (
              <Typography variant="caption" color="text.secondary">
                Failed message details are saved in Conversations and lead timeline.
              </Typography>
            ) : null}
            <Paper variant="outlined" sx={{ p: 1.25 }}>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>Agents</Typography>
              {commandAgents.length === 0 ? (
                <Typography variant="body2" color="text.secondary">No active agents.</Typography>
              ) : (
                <Stack spacing={0.75}>
                  {commandAgents.map((agent) => (
                    <Box key={agent.id} sx={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                      <Typography variant="body2">{agent.name || agent.agent_id}</Typography>
                      <StatusBadge label={agent.status} />
                    </Box>
                  ))}
                </Stack>
              )}
            </Paper>
            <MuiButton
              variant="outlined"
              color="warning"
              onClick={() => void onPauseCampaign()}
              disabled={commandCampaign.status === "paused"}
            >
              Pause Campaign
            </MuiButton>
          </Box>
        )}
      </Modal>
    </AppShell>
  );
}
