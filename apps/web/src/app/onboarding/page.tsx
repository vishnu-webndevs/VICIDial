"use client";

import { ChangeEvent, FormEvent, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { AppShell } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { setOnboardingComplete } from "@/lib/onboarding";
import {
  attachLeadsToList,
  createAgent,
  getLeadImportJob,
  importLeadsFromFile,
  listAgents,
  listCampaigns,
  listLeadLists,
  createLeadList,
  listLeads,
  listProviderAccounts,
  fetchProviderNumbersFromTwilio,
  syncProviderNumbers,
  assignAgentNumber,
  saveCampaign,
  saveLead,
} from "@/lib/product-api";
import type { ProviderAccount as ApiProviderAccount } from "@/lib/product-api";
import { getTenantContext, getTenantScopedStorageKey } from "@/lib/tenant-context";
import type { Campaign, Lead, LeadList } from "@/types/product";
import { Alert, Box, Button, Card, Divider, LinearProgress, MenuItem, Stack, TextField, Typography } from "@/ui";

type StepId = "add_provider" | "create_agent" | "add_lead" | "add_campaign";

const STEPS: Array<{ id: StepId; label: string; subtitle: string }> = [
  { id: "add_provider", label: "1. Connect Calling Provider", subtitle: "Connect your Twilio or Vonage account to generate calls." },
  { id: "create_agent", label: "2. Create Agent & Assign Caller ID", subtitle: "Create an agent profile and map it to a validated caller ID." },
  { id: "add_lead", label: "3. Create List & Add Leads", subtitle: "Create lead lists and upload contacts manually or via CSV." },
  { id: "add_campaign", label: "4. Create & Launch Campaign", subtitle: "Set dialing speed, schedule windows, and launch your campaign." },
];

const E164_REGEX = /^\+[1-9]\d{7,14}$/;
const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const ONBOARDING_DRAFT_KEY = "onboarding_workflow_v2";

type OnboardingDraft = {
  list: {
    name: string;
    description: string;
    sourceHeaders: string;
    mapFullName: string;
    mapPhone: string;
    mapEmail: string;
    mapCompany: string;
    createdListId: string;
    completed: boolean;
  };
  lead: {
    mode: "manual" | "import";
    fullName: string;
    phone: string;
    email: string;
    company: string;
    listId: string;
    importedFileName: string;
    completed: boolean;
  };
  provider: {
    providerType: "twilio" | "vonage";
    displayName: string;
    accountSid: string;
    authToken: string;
    fromNumber: string;
    whatsappFrom: string;
    createdProviderId: string;
    tested: boolean;
    completed: boolean;
  };
  agent: {
    identity: string;
    status: "active" | "inactive";
    role: "admin" | "supervisor" | "agent";
    permissions: string[];
    twilioNumberId: string;
    contactEmail: string;
    contactPhone: string;
    availabilityDays: string[];
    availabilityStart: string;
    availabilityEnd: string;
    createdAgentId: string;
    completed: boolean;
  };
  campaign: {
    template: "standard" | "high_volume" | "quality_first";
    name: string;
    scheduleWindow: string;
    retryLimit: number;
    queueSize: number;
    callsPerMinute: number;
    listId: string;
    status: Campaign["status"];
    completed: boolean;
    createdCampaignId: string;
  };
};

const defaultDraft: OnboardingDraft = {
  list: {
    name: "",
    description: "",
    sourceHeaders: "full_name,phone,email,company",
    mapFullName: "full_name",
    mapPhone: "phone",
    mapEmail: "email",
    mapCompany: "company",
    createdListId: "",
    completed: false,
  },
  lead: {
    mode: "manual",
    fullName: "",
    phone: "",
    email: "",
    company: "",
    listId: "",
    importedFileName: "",
    completed: false,
  },
  provider: {
    providerType: "twilio",
    displayName: "",
    accountSid: "",
    authToken: "",
    fromNumber: "",
    whatsappFrom: "",
    createdProviderId: "",
    tested: false,
    completed: false,
  },
  agent: {
    identity: "",
    status: "active",
    role: "agent",
    permissions: ["call.view", "call.initiate"],
    twilioNumberId: "",
    contactEmail: "",
    contactPhone: "",
    availabilityDays: ["Mon", "Tue", "Wed", "Thu", "Fri"],
    availabilityStart: "09:00",
    availabilityEnd: "18:00",
    createdAgentId: "",
    completed: false,
  },
  campaign: {
    template: "standard",
    name: "",
    scheduleWindow: "Mon-Fri 09:00-18:00",
    retryLimit: 2,
    queueSize: 20,
    callsPerMinute: 20,
    listId: "",
    status: "draft",
    completed: false,
    createdCampaignId: "",
  },
};

type Snapshot = {
  lists: LeadList[];
  leads: Lead[];
  providers: ApiProviderAccount[];
  agents: Awaited<ReturnType<typeof listAgents>>;
  campaigns: Campaign[];
};

function normalizePhone(value: string): string {
  return value.replace(/\s+/g, "");
}

function findFirstIncomplete(completed: boolean[]): number {
  const index = completed.findIndex((value) => !value);
  return index === -1 ? completed.length - 1 : index;
}

export default function OnboardingPage() {
  const router = useRouter();
  const [snapshot, setSnapshot] = useState<Snapshot>({
    lists: [],
    leads: [],
    providers: [],
    agents: [],
    campaigns: [],
  });
  const [draft, setDraft] = useState<OnboardingDraft>(defaultDraft);
  const [stepIndex, setStepIndex] = useState(0);
  const [newListName, setNewListName] = useState("");
  const [creatingList, setCreatingList] = useState(false);

  async function handleCreateNewList(event: FormEvent) {
    event.preventDefault();
    if (!newListName.trim()) {
      setToast("List name cannot be empty.", "error");
      return;
    }
    setCreatingList(true);
    try {
      const created = await createLeadList({
        name: newListName.trim(),
        description: "Created during onboarding",
        is_active: true,
      });
      await refreshSnapshot();
      updateDraft((previous) => ({
        ...previous,
        lead: { ...previous.lead, listId: created.id },
        campaign: { ...previous.campaign, listId: created.id },
      }));
      setNewListName("");
      setToast(`Lead List "${created.name}" created successfully!`, "success");
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Failed to create lead list.", "error");
    } finally {
      setCreatingList(false);
    }
  }
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"success" | "error" | "info">("info");
  const [importing, setImporting] = useState(false);
  const [hydrated, setHydrated] = useState(false);
  const [liveProviderNumbers, setLiveProviderNumbers] = useState<string[]>([]);

  const tenantId = typeof window === "undefined" ? null : localStorage.getItem("wnd_tenant_id");
  const scopedDraftKey = getTenantScopedStorageKey(ONBOARDING_DRAFT_KEY, tenantId);

  const completed = useMemo(() => {
    const providerDone = draft.provider.completed || snapshot.providers.some((provider) => provider.status === "active");
    const agentDone = draft.agent.completed || snapshot.agents.length > 0;
    const leadDone = draft.lead.completed || snapshot.leads.length > 0;
    const campaignDone = draft.campaign.completed || snapshot.campaigns.length > 0;
    return [providerDone, agentDone, leadDone, campaignDone];
  }, [draft, snapshot]);

  const progress = useMemo(() => {
    const done = completed.filter(Boolean).length;
    const total = STEPS.length;
    return { done, total, percent: Math.round((done / total) * 100) };
  }, [completed]);

  const allDone = completed.every(Boolean);

  async function refreshSnapshot() {
    const [lists, leads, providers, agents, campaigns] = await Promise.all([
      listLeadLists(),
      listLeads(),
      listProviderAccounts(),
      listAgents(),
      listCampaigns(),
    ]);
    setSnapshot({ lists, leads, providers, agents, campaigns });
    const defaultListId = lists[0]?.id ?? "";
    if (defaultListId) {
      setDraft((previous) => ({
        ...previous,
        list: {
          ...previous.list,
          createdListId: previous.list.createdListId || defaultListId,
          completed: true,
        },
        lead: {
          ...previous.lead,
          listId: previous.lead.listId || defaultListId,
        },
        campaign: {
          ...previous.campaign,
          listId: previous.campaign.listId || defaultListId,
        },
      }));
    }
  }

  function setToast(text: string, tone: "success" | "error" | "info" = "info") {
    setMessage(text);
    setMessageTone(tone);
  }

  function updateDraft(updater: (previous: OnboardingDraft) => OnboardingDraft) {
    setDraft((previous) => updater(previous));
  }

  function ensureCurrentStepAllowed(targetIndex: number) {
    if (targetIndex === 0) {
      setStepIndex(0);
      return;
    }
    if (!completed[targetIndex - 1]) {
      setToast("Complete the previous step first to keep setup sequence valid.", "error");
      return;
    }
    setStepIndex(targetIndex);
  }

  function saveProgressNow() {
    if (typeof window === "undefined") {
      return;
    }
    localStorage.setItem(scopedDraftKey, JSON.stringify(draft));
    setToast("Progress saved.", "success");
  }

  function completeOnboardingAndGoDashboard() {
    setOnboardingComplete(tenantId);
    if (typeof window !== "undefined") {
      localStorage.removeItem(scopedDraftKey);
    }
    router.replace("/dashboard");
  }

  async function loadAll() {
    setLoading(true);
    setMessage("");
    try {
      if (typeof window !== "undefined") {
        const raw = localStorage.getItem(scopedDraftKey);
        if (raw) {
          try {
            const parsed = JSON.parse(raw) as OnboardingDraft;
            setDraft({
              ...defaultDraft,
              ...parsed,
              list: { ...defaultDraft.list, ...parsed.list },
              lead: { ...defaultDraft.lead, ...parsed.lead },
              provider: { ...defaultDraft.provider, ...parsed.provider },
              agent: { ...defaultDraft.agent, ...parsed.agent },
              campaign: { ...defaultDraft.campaign, ...parsed.campaign },
            });
          } catch {
            localStorage.removeItem(scopedDraftKey);
          }
        }
      }
      await refreshSnapshot();
      setHydrated(true);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Failed to load onboarding data.", "error");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadAll();
  }, []);

  useEffect(() => {
    setStepIndex(findFirstIncomplete(completed));
  }, [completed]);

  // Automatically mark onboarding as complete once all steps are completed,
  // preventing redirects when clicking sidebar items before the final CTA button is clicked.
  useEffect(() => {
    if (hydrated && allDone) {
      setOnboardingComplete(tenantId);
    }
  }, [hydrated, allDone, tenantId]);

  useEffect(() => {
    if (!hydrated || typeof window === "undefined") {
      return;
    }
    localStorage.setItem(scopedDraftKey, JSON.stringify(draft));
  }, [draft, hydrated, scopedDraftKey]);

  // When entering step 3, fetch phone numbers directly from the live Twilio API.
  // We bypass the DB validation chain (which requires is_validated+status=active)
  // because at this point the user has only tested credentials, not gone through
  // the full admin number-management flow.
  useEffect(() => {
    if (stepIndex !== 1) return;

    const activeProvider = snapshot.providers.find(
      (provider) => provider.provider_type === "twilio" && provider.status === "active"
    );
    if (!activeProvider) return;

    void (async () => {
      try {
        const numbers = await fetchProviderNumbersFromTwilio(activeProvider.id);
        const phoneNumbers = numbers.map((n) => n.phone_number).filter(Boolean);
        setLiveProviderNumbers(phoneNumbers);
      } catch {
        // Silently ignore — sandbox mode or no numbers on account.
      }
    })();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stepIndex, snapshot.providers]);

  // Auto-select the first live number when the agent step becomes active.
  useEffect(() => {
    if (stepIndex !== 1 || liveProviderNumbers.length === 0) {
      return;
    }
    setDraft((previous) => {
      if (previous.agent.twilioNumberId) {
        return previous;
      }
      const first = liveProviderNumbers[0];
      if (!first) {
        return previous;
      }
      return { ...previous, agent: { ...previous.agent, twilioNumberId: first } };
    });
  }, [stepIndex, liveProviderNumbers]);

  async function onCreateManualLead(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const phone = normalizePhone(draft.lead.phone);
    if (draft.lead.fullName.trim().length < 2) {
      setToast("Lead full name is required.", "error");
      return;
    }
    if (!E164_REGEX.test(phone)) {
      setToast("Phone must be valid E.164 format, for example +15551234567.", "error");
      return;
    }
    if (draft.lead.email && !EMAIL_REGEX.test(draft.lead.email)) {
      setToast("Lead email is not valid.", "error");
      return;
    }
    if (!draft.lead.listId) {
      setToast("Select a list before adding leads.", "error");
      return;
    }

    const phoneExists = snapshot.leads.some((lead) => normalizePhone(lead.phone) === phone);
    const emailExists = draft.lead.email
      ? snapshot.leads.some((lead) => (lead.email ?? "").toLowerCase() === draft.lead.email.toLowerCase())
      : false;
    if (phoneExists || emailExists) {
      setToast("Duplicate detected: phone or email already exists in leads.", "error");
      return;
    }

    setSaving(true);
    try {
      const created = await saveLead({
        full_name: draft.lead.fullName.trim(),
        phone,
        email: draft.lead.email.trim() || undefined,
        company: draft.lead.company.trim() || undefined,
        status: "new",
        owner_agent: "Unassigned",
        next_follow_up_at: null,
        tags: [],
        notes: ["Created during onboarding"],
      });
      await attachLeadsToList(draft.lead.listId, [created.id]);
      updateDraft((previous) => ({
        ...previous,
        lead: { ...previous.lead, completed: true, phone, mode: "manual" },
      }));
      await refreshSnapshot();
      setToast("Lead added and attached to list.", "success");
      setStepIndex(3);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Failed to save lead.", "error");
    } finally {
      setSaving(false);
    }
  }

  async function onImportLeads(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }
    const lower = file.name.toLowerCase();
    if (!lower.endsWith(".csv") && !lower.endsWith(".xlsx") && !lower.endsWith(".xls")) {
      setToast("Only CSV or Excel files are supported for import.", "error");
      return;
    }
    if (!draft.lead.listId) {
      setToast("Create/select a list first.", "error");
      return;
    }

    if (lower.endsWith(".csv")) {
      const content = await file.text();
      const rows = content.split(/\r?\n/).slice(1, 201);
      const importedPhones = rows
        .map((row) => row.split(",")[1]?.trim())
        .filter(Boolean)
        .map((value) => normalizePhone(value!));
      const duplicateInSystem = importedPhones.some((phone) =>
        snapshot.leads.some((lead) => normalizePhone(lead.phone) === phone)
      );
      if (duplicateInSystem) {
        setToast("Duplicate phone numbers detected against existing leads. Clean the file and retry.", "error");
        return;
      }
    }

    setImporting(true);
    setMessage("");
    try {
      const createdJob = await importLeadsFromFile(file);
      let current = await getLeadImportJob(createdJob.job_id);
      let attempts = 0;
      while (["queued", "processing"].includes(current.status) && attempts < 120) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        current = await getLeadImportJob(createdJob.job_id);
        attempts += 1;
      }
      if (current.status !== "completed") {
        throw new Error("Lead import did not complete. Review import job status and file format.");
      }
      updateDraft((previous) => ({
        ...previous,
        lead: {
          ...previous.lead,
          importedFileName: file.name,
          mode: "import",
          completed: true,
        },
      }));
      await refreshSnapshot();
      setToast(`Lead import completed: ${current.successful_rows} rows imported.`, "success");
      setStepIndex(3);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Lead import failed.", "error");
    } finally {
      setImporting(false);
      event.target.value = "";
    }
  }

  async function onCreateProvider(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!draft.provider.displayName.trim()) {
      setToast("Provider display name is required.", "error");
      return;
    }
    if (draft.provider.providerType === "twilio" && !/^AC[A-Za-z0-9]{20,40}$/.test(draft.provider.accountSid.trim())) {
      setToast("Twilio Account SID appears invalid.", "error");
      return;
    }
    if (!draft.provider.authToken.trim()) {
      setToast("API/Auth token is required.", "error");
      return;
    }
    if (draft.provider.fromNumber && !E164_REGEX.test(normalizePhone(draft.provider.fromNumber))) {
      setToast("Default from number must be E.164 format.", "error");
      return;
    }
    if (draft.provider.whatsappFrom) {
      const raw = draft.provider.whatsappFrom.trim();
      const normalized = raw.startsWith("whatsapp:") ? raw.slice("whatsapp:".length) : raw;
      if (!E164_REGEX.test(normalizePhone(normalized))) {
        setToast("WhatsApp From must be E.164 format (optionally prefixed with whatsapp:).", "error");
        return;
      }
    }

    setSaving(true);
    try {
      const { token, tenantId: requestTenantId } = getTenantContext();
      const response = await apiRequest<{ data: { id: string } }>("/providers", {
        method: "POST",
        token,
        tenantId: requestTenantId,
        body: {
          provider_type: draft.provider.providerType,
          display_name: draft.provider.displayName.trim(),
          credentials: {
            account_sid: draft.provider.accountSid.trim(),
            auth_token: draft.provider.authToken.trim(),
            from_number: normalizePhone(draft.provider.fromNumber),
            whatsapp_from: draft.provider.whatsappFrom.trim(),
          },
        },
      });
      updateDraft((previous) => ({
        ...previous,
        provider: {
          ...previous.provider,
          createdProviderId: response.data.id,
          tested: false,
          completed: false,
        },
      }));
      await refreshSnapshot();
      setToast("Provider saved. Auto-validating & syncing numbers...", "success");
      await autoSetupProvider(response.data.id);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Failed to create provider.", "error");
    } finally {
      setSaving(false);
    }
  }

  async function onTestProviderConnection() {
    const providerId = draft.provider.createdProviderId || snapshot.providers[0]?.id;
    if (!providerId) {
      setToast("Create a provider first before running validation.", "error");
      return;
    }
    setToast("Running provider auto-setup (test + sync numbers)...", "info");
    await autoSetupProvider(providerId);
  }

  async function autoSetupProvider(providerId: string) {
    setSaving(true);
    try {
      const { token, tenantId: requestTenantId } = getTenantContext();
      const numbers = await fetchProviderNumbersFromTwilio(providerId);
      const normalizedFrom = draft.provider.fromNumber ? normalizePhone(draft.provider.fromNumber) : "";
      const pickFrom = numbers.find((item) => item.phone_number === normalizedFrom)?.phone_number ?? numbers[0]?.phone_number ?? "";

      if (pickFrom && pickFrom !== normalizedFrom) {
        await apiRequest(`/providers/${providerId}`, {
          method: "PATCH",
          token,
          tenantId: requestTenantId,
          body: { credentials: { from_number: pickFrom } },
        });
        updateDraft((previous) => ({
          ...previous,
          provider: { ...previous.provider, fromNumber: pickFrom },
        }));
      }

      if (numbers.length > 0) {
        await syncProviderNumbers(providerId, numbers);
      }

      let providerPhoneNumberId: string | null = null;
      if (pickFrom) {
        const validatedNumbers = await apiRequest<{ data: Array<{ id: string; provider_account_id: string; phone_number: string }> }>(
          "/admin/settings/communication/numbers/validated",
          { token, tenantId: requestTenantId }
        );
        providerPhoneNumberId =
          validatedNumbers.data.find((n) => n.provider_account_id === providerId && n.phone_number === pickFrom)?.id ?? null;
      }

      await apiRequest(`/admin/settings/communication/providers/${providerId}/test`, {
        method: "POST",
        token,
        tenantId: requestTenantId,
        body: providerPhoneNumberId ? { provider_phone_number_id: providerPhoneNumberId } : {},
      });

      updateDraft((previous) => ({
        ...previous,
        provider: {
          ...previous.provider,
          tested: true,
          completed: true,
        },
      }));
      await refreshSnapshot();
      setToast("Provider connection test passed.", "success");
      setStepIndex(1);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Provider validation failed.", "error");
    } finally {
      setSaving(false);
    }
  }

  async function onCreateAgent(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!draft.agent.identity.trim()) {
      setToast("Agent name is required.", "error");
      return;
    }
    if (!draft.agent.twilioNumberId) {
      setToast("Select a Twilio number for this agent.", "error");
      return;
    }

    // Sanitize to match backend regex ^[A-Za-z0-9._-]+$: collapse spaces/special chars to hyphens.
    const sanitizedName = draft.agent.identity.trim().replace(/[^A-Za-z0-9._-]+/g, "-").replace(/^-+|-+$/g, "");

    setSaving(true);
    try {
      const providerId =
        (draft.provider.createdProviderId || snapshot.providers.find((p) => p.provider_type === "twilio" && p.status === "active")?.id) ?? "";
      if (!providerId) {
        throw new Error("No active Twilio provider found. Complete provider validation first.");
      }

      const created = await createAgent({
        company_number: sanitizedName,
        status: draft.agent.status,
      });

      const { token, tenantId: requestTenantId } = getTenantContext();
      const validatedNumbers = await apiRequest<{ data: Array<{ id: string; provider_account_id: string; phone_number: string }> }>(
        "/admin/settings/communication/numbers/validated",
        { token, tenantId: requestTenantId }
      );
      const selectedNumberId =
        validatedNumbers.data.find((n) => n.provider_account_id === providerId && n.phone_number === draft.agent.twilioNumberId)?.id ?? "";
      if (!selectedNumberId) {
        throw new Error("Selected Twilio number is not validated/synced yet. Re-run provider validation.");
      }
      await assignAgentNumber({
        agent_id: created.id,
        provider_account_id: providerId,
        provider_phone_number_id: selectedNumberId,
        status: "active",
      });

      updateDraft((previous) => ({
        ...previous,
        agent: {
          ...previous.agent,
          createdAgentId: created.id,
          completed: true,
        },
      }));
      await refreshSnapshot();
      setToast("Agent created and assigned validated number.", "success");
      setStepIndex(2);
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Failed to create agent.", "error");
    } finally {
      setSaving(false);
    }
  }

  async function onCreateCampaign(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!draft.campaign.name.trim()) {
      setToast("Campaign name is required.", "error");
      return;
    }
    if (!draft.campaign.listId) {
      setToast("Select a target lead list.", "error");
      return;
    }
    if (!draft.provider.tested && !snapshot.providers.some((provider) => provider.status === "active")) {
      setToast("Validate a provider connection before launching campaign setup.", "error");
      return;
    }
    if (snapshot.agents.length === 0) {
      setToast("Create at least one agent before creating a campaign.", "error");
      return;
    }
    if (snapshot.leads.length === 0) {
      setToast("Import or create at least one lead before campaign launch.", "error");
      return;
    }
    if (draft.campaign.retryLimit < 0 || draft.campaign.queueSize < 1 || draft.campaign.callsPerMinute < 1) {
      setToast("Campaign scheduling values are invalid.", "error");
      return;
    }

    const selectedList = snapshot.lists.find((item) => item.id === draft.campaign.listId);
    if (!selectedList) {
      setToast("Selected list no longer exists. Refresh and select again.", "error");
      return;
    }

    setSaving(true);
    try {
      const created = await saveCampaign({
        name: draft.campaign.name.trim(),
        type: "auto",
        status: draft.campaign.status,
        schedule_window: draft.campaign.scheduleWindow.trim(),
        retry_limit: Number(draft.campaign.retryLimit),
        queue_size: Number(draft.campaign.queueSize),
        calls_per_minute: Number(draft.campaign.callsPerMinute),
        lead_list_name: selectedList.name,
        lead_list_ids: [selectedList.id],
      });
      updateDraft((previous) => ({
        ...previous,
        campaign: {
          ...previous.campaign,
          createdCampaignId: created.id,
          completed: true,
        },
      }));
      await refreshSnapshot();
      setToast("Campaign created and launch validation passed.", "success");
      completeOnboardingAndGoDashboard();
    } catch (error) {
      setToast(error instanceof Error ? error.message : "Failed to create campaign.", "error");
    } finally {
      setSaving(false);
    }
  }

  const isStepUnlocked = (index: number) => index === 0 || completed[index - 1];
  const selectedTemplate = draft.campaign.template;
  const hasListInCampaign = Boolean(draft.campaign.listId);
  const launchChecks = [
    { label: "Lead list selected", passed: hasListInCampaign },
    { label: "At least one lead exists", passed: snapshot.leads.length > 0 },
    { label: "Provider validated", passed: draft.provider.tested || snapshot.providers.some((provider) => provider.status === "active") },
    { label: "At least one agent exists", passed: snapshot.agents.length > 0 },
    { label: "Schedule configured", passed: draft.campaign.scheduleWindow.trim().length > 0 },
  ];

  useEffect(() => {
    if (selectedTemplate === "standard") {
      updateDraft((previous) => ({
        ...previous,
        campaign: {
          ...previous.campaign,
          retryLimit: 2,
          queueSize: 20,
          callsPerMinute: 20,
          scheduleWindow: previous.campaign.scheduleWindow || "Mon-Fri 09:00-18:00",
        },
      }));
      return;
    }
    if (selectedTemplate === "high_volume") {
      updateDraft((previous) => ({
        ...previous,
        campaign: {
          ...previous.campaign,
          retryLimit: 1,
          queueSize: 50,
          callsPerMinute: 40,
          scheduleWindow: "Mon-Sat 08:00-20:00",
        },
      }));
      return;
    }
    updateDraft((previous) => ({
      ...previous,
      campaign: {
        ...previous.campaign,
        retryLimit: 3,
        queueSize: 10,
        callsPerMinute: 12,
        scheduleWindow: "Mon-Fri 10:00-17:00",
      },
    }));
  }, [selectedTemplate]);

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <Card title="Production Onboarding Workflow" subtitle="Complete all four steps in order to make the system immediately operational.">
          {loading ? (
            <Typography variant="body2" color="text.secondary">
              Loading onboarding context...
            </Typography>
          ) : (
            <Stack spacing={2}>
              <Box sx={{ border: 1, borderColor: "divider", borderRadius: 1.5, p: 2, bgcolor: "background.default" }}>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  Setup Progress: {progress.done}/{progress.total} ({progress.percent}%)
                </Typography>
                <LinearProgress variant="determinate" value={progress.percent} sx={{ mt: 1.25, height: 8, borderRadius: 99 }} />
                {allDone ? (
                  <Stack direction="row" spacing={1} sx={{ mt: 1.25, justifyContent: "flex-end" }}>
                    <Button variant="contained" onClick={completeOnboardingAndGoDashboard}>
                      Go to Dashboard
                    </Button>
                  </Stack>
                ) : null}
              </Box>

              <Box sx={{ display: "grid", gap: 1.25, gridTemplateColumns: { xs: "1fr", lg: "repeat(4, minmax(0, 1fr))" } }}>
                {STEPS.map((step, index) => {
                  const done = completed[index];
                  const active = stepIndex === index;
                  const unlocked = isStepUnlocked(index);
                  return (
                    <Box
                      key={step.id}
                      role="button"
                      tabIndex={0}
                      onClick={() => ensureCurrentStepAllowed(index)}
                      onKeyDown={(event) => {
                        if (event.key === "Enter" || event.key === " ") {
                          ensureCurrentStepAllowed(index);
                        }
                      }}
                      sx={{
                        border: 1,
                        borderColor: active ? "primary.main" : "divider",
                        borderRadius: 1.5,
                        p: 1.5,
                        cursor: unlocked ? "pointer" : "not-allowed",
                        opacity: unlocked ? 1 : 0.55,
                        bgcolor: done ? "success.light" : "background.paper",
                      }}
                    >
                      <Typography variant="caption" sx={{ fontWeight: 700, color: done ? "success.dark" : "text.secondary" }}>
                        {done ? "Completed" : unlocked ? "Pending" : "Locked"}
                      </Typography>
                      <Typography variant="body2" sx={{ fontWeight: 700, mt: 0.4 }}>
                        {step.label}
                      </Typography>
                    </Box>
                  );
                })}
              </Box>

              {/* Step 1: Connect Calling Provider */}
              {stepIndex === 0 ? (
                <Card title={STEPS[0].label} subtitle={STEPS[0].subtitle}>
                  <Alert severity="info" sx={{ mb: 2 }}>
                    <strong>Setup Twilio / Vonage:</strong> A valid provider account is required to generate calls and SMS. Enter credentials, save, and validate connection before moving to the next step.
                  </Alert>

                  <Box component="form" onSubmit={onCreateProvider} sx={{ display: "grid", gap: 2.5 }}>
                    <TextField
                      select
                      size="medium"
                      label="Provider Type"
                      value={draft.provider.providerType}
                      onChange={(event) =>
                        updateDraft((previous) => ({
                          ...previous,
                          provider: { ...previous.provider, providerType: event.target.value as "twilio" | "vonage" },
                        }))
                      }
                    >
                      <MenuItem value="twilio">Twilio (Recommended)</MenuItem>
                      <MenuItem value="vonage">Vonage</MenuItem>
                    </TextField>
                    <TextField
                      required
                      size="medium"
                      label="Display Name"
                      value={draft.provider.displayName}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, provider: { ...previous.provider, displayName: event.target.value } }))}
                    />
                    <TextField
                      required
                      size="medium"
                      label="Account SID"
                      value={draft.provider.accountSid}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, provider: { ...previous.provider, accountSid: event.target.value } }))}
                    />
                    <TextField
                      required
                      size="medium"
                      label="Auth Token"
                      type="password"
                      value={draft.provider.authToken}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, provider: { ...previous.provider, authToken: event.target.value } }))}
                    />
                    <TextField
                      size="medium"
                      label="Default From Number"
                      placeholder="+15551234567"
                      value={draft.provider.fromNumber}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, provider: { ...previous.provider, fromNumber: event.target.value } }))}
                    />
                    <TextField
                      size="medium"
                      label="WhatsApp From Number (Optional)"
                      placeholder="whatsapp:+14155238886"
                      value={draft.provider.whatsappFrom}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, provider: { ...previous.provider, whatsappFrom: event.target.value } }))}
                    />
                    <Stack direction="row" spacing={1} sx={{ mt: 2, justifyContent: "space-between", alignItems: "center" }}>
                      <Stack direction="row" spacing={1}>
                        <Button type="submit" disabled={saving}>
                          {saving ? "Saving..." : "Save Provider"}
                        </Button>
                        <Button type="button" variant="outlined" onClick={() => void onTestProviderConnection()} disabled={saving}>
                          Validate Connection
                        </Button>
                      </Stack>
                      <Button
                        type="button"
                        variant="contained"
                        disabled={!completed[0]}
                        onClick={() => setStepIndex(1)}
                      >
                        Next: Agent Setup →
                      </Button>
                    </Stack>
                  </Box>
                </Card>
              ) : null}

              {/* Step 2: Create Agent & Assign Caller ID */}
              {stepIndex === 1 ? (
                <Card title={STEPS[1].label} subtitle={STEPS[1].subtitle}>
                  <Alert severity="info" sx={{ mb: 2 }}>
                    <strong>Setup Agent:</strong> Assign a validated phone number to the agent profile for making and receiving calls.
                  </Alert>

                  <Box component="form" onSubmit={onCreateAgent} sx={{ display: "grid", gap: 2.5 }}>
                    <TextField
                      required
                      size="medium"
                      label="Agent Name"
                      placeholder="sales-agent-1"
                      value={draft.agent.identity}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, agent: { ...previous.agent, identity: event.target.value } }))}
                    />
                    <TextField
                      select
                      size="medium"
                      label="Caller ID / Synced Number"
                      value={draft.agent.twilioNumberId}
                      disabled={liveProviderNumbers.length === 0}
                      helperText={
                        liveProviderNumbers.length === 0
                          ? "Fetching numbers from Twilio..."
                          : "Select validated caller ID number."
                      }
                      onChange={(event) =>
                        updateDraft((previous) => ({
                          ...previous,
                          agent: { ...previous.agent, twilioNumberId: event.target.value },
                        }))
                      }
                    >
                      {liveProviderNumbers.length === 0 ? (
                        <MenuItem value="">No numbers found</MenuItem>
                      ) : null}
                      {liveProviderNumbers.map((phone) => (
                        <MenuItem key={phone} value={phone}>
                          {phone}
                        </MenuItem>
                      ))}
                    </TextField>
                    <Stack direction="row" spacing={1} sx={{ mt: 2, justifyContent: "space-between", alignItems: "center" }}>
                      <Stack direction="row" spacing={1}>
                        <Button type="button" variant="outlined" onClick={() => setStepIndex(0)}>
                          ← Back
                        </Button>
                        <Button type="submit" disabled={saving}>
                          {saving ? "Saving..." : "Create Agent"}
                        </Button>
                      </Stack>
                      <Button
                        type="button"
                        variant="contained"
                        disabled={!completed[1]}
                        onClick={() => setStepIndex(2)}
                      >
                        Next: Leads Setup →
                      </Button>
                    </Stack>
                  </Box>
                </Card>
              ) : null}

              {/* Step 3: Create List & Add Leads */}
              {stepIndex === 2 ? (
                <Card title={STEPS[2].label} subtitle={STEPS[2].subtitle}>
                  <Stack spacing={3.5}>
                    {/* Inline Lead List Creation Box */}
                    <Box sx={{ border: 1, borderColor: "divider", borderRadius: 1.5, p: 2, bgcolor: "background.default" }}>
                      <Typography variant="subtitle2" sx={{ mb: 1, fontWeight: 600 }}>
                        Create a New Lead List
                      </Typography>
                      <Box component="form" onSubmit={handleCreateNewList} sx={{ display: "flex", gap: 2 }}>
                        <TextField
                          size="small"
                          label="List Name"
                          fullWidth
                          value={newListName}
                          onChange={(e) => setNewListName(e.target.value)}
                        />
                        <Button type="submit" disabled={creatingList} sx={{ minWidth: 120 }}>
                          {creatingList ? "Creating..." : "Create List"}
                        </Button>
                      </Box>
                    </Box>

                    <TextField
                      select
                      size="medium"
                      label="Select Target List"
                      value={draft.lead.listId}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, lead: { ...previous.lead, listId: event.target.value } }))}
                      helperText="Create a new list above or select an existing one."
                    >
                      <MenuItem value="">Select list</MenuItem>
                      {snapshot.lists.map((item) => (
                        <MenuItem key={item.id} value={item.id}>
                          {item.name}
                        </MenuItem>
                      ))}
                    </TextField>

                    <Box component="form" onSubmit={onCreateManualLead} sx={{ display: "grid", gap: 2.5 }}>
                      <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>Manual Lead Entry</Typography>
                      <TextField
                        required
                        size="medium"
                        label="Full Name"
                        value={draft.lead.fullName}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, lead: { ...previous.lead, fullName: event.target.value } }))}
                      />
                      <TextField
                        required
                        size="medium"
                        label="Phone Number"
                        placeholder="+15551234567"
                        value={draft.lead.phone}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, lead: { ...previous.lead, phone: event.target.value } }))}
                      />
                      <TextField
                        size="medium"
                        label="Email Address (Optional)"
                        value={draft.lead.email}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, lead: { ...previous.lead, email: event.target.value } }))}
                      />
                      <TextField
                        size="medium"
                        label="Company Name (Optional)"
                        value={draft.lead.company}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, lead: { ...previous.lead, company: event.target.value } }))}
                      />
                      <Stack direction="row" spacing={1}>
                        <Button type="submit" disabled={saving}>
                          {saving ? "Saving..." : "Add Lead"}
                        </Button>
                      </Stack>
                    </Box>

                    <Box sx={{ borderTop: 1, borderColor: "divider", pt: 1.5 }}>
                      <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>
                        Bulk Import (CSV / Excel) {importing && " - Importing..."}
                      </Typography>
                      <Box
                        component="input"
                        type="file"
                        accept=".csv,.xlsx,.xls"
                        onChange={onImportLeads}
                        disabled={importing}
                        sx={{ mt: 1 }}
                      />
                      <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 0.5 }}>
                        Format: The CSV must include a header row with &apos;full_name,phone,email&apos;.
                      </Typography>
                    </Box>

                    <Divider sx={{ my: 2 }} />
                    <Stack direction="row" spacing={1} sx={{ justifyContent: "space-between", alignItems: "center" }}>
                      <Button type="button" variant="outlined" onClick={() => setStepIndex(1)}>
                        ← Back
                      </Button>
                      <Button
                        type="button"
                        variant="contained"
                        disabled={!completed[2]}
                        onClick={() => setStepIndex(3)}
                      >
                        Next: Campaign Setup →
                      </Button>
                    </Stack>
                  </Stack>
                </Card>
              ) : null}

              {/* Step 4: Setup & Launch Campaign */}
              {stepIndex === 3 ? (
                <Card title={STEPS[3].label} subtitle={STEPS[3].subtitle}>
                  <Box component="form" onSubmit={onCreateCampaign} sx={{ display: "grid", gap: 2.5 }}>
                    <TextField
                      select
                      size="medium"
                      label="Campaign Template"
                      value={draft.campaign.template}
                      onChange={(event) =>
                        updateDraft((previous) => ({
                          ...previous,
                          campaign: {
                            ...previous.campaign,
                            template: event.target.value as "standard" | "high_volume" | "quality_first",
                          },
                        }))
                      }
                    >
                      <MenuItem value="standard">Standard outreach speed</MenuItem>
                      <MenuItem value="high_volume">High volume blast dialing</MenuItem>
                      <MenuItem value="quality_first">Focus calling</MenuItem>
                    </TextField>
                    <TextField
                      required
                      size="medium"
                      label="Campaign Name"
                      value={draft.campaign.name}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, campaign: { ...previous.campaign, name: event.target.value } }))}
                    />
                    <TextField
                      select
                      size="medium"
                      label="Target Lead List"
                      value={draft.campaign.listId}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, campaign: { ...previous.campaign, listId: event.target.value } }))}
                    >
                      <MenuItem value="">Select list</MenuItem>
                      {snapshot.lists.map((list) => (
                        <MenuItem key={list.id} value={list.id}>
                          {list.name}
                        </MenuItem>
                      ))}
                    </TextField>
                    <TextField
                      required
                      size="medium"
                      label="Schedule Window"
                      value={draft.campaign.scheduleWindow}
                      onChange={(event) => updateDraft((previous) => ({ ...previous, campaign: { ...previous.campaign, scheduleWindow: event.target.value } }))}
                    />
                    <Box sx={{ display: "grid", gap: 2.5, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}>
                      <TextField
                        size="medium"
                        type="number"
                        label="Retry Limit"
                        value={draft.campaign.retryLimit}
                        inputProps={{ min: 0 }}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, campaign: { ...previous.campaign, retryLimit: Number(event.target.value) } }))}
                      />
                      <TextField
                        size="medium"
                        type="number"
                        label="Queue Size"
                        value={draft.campaign.queueSize}
                        inputProps={{ min: 1 }}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, campaign: { ...previous.campaign, queueSize: Number(event.target.value) } }))}
                      />
                      <TextField
                        size="medium"
                        type="number"
                        label="Calls/Minute"
                        value={draft.campaign.callsPerMinute}
                        inputProps={{ min: 1 }}
                        onChange={(event) => updateDraft((previous) => ({ ...previous, campaign: { ...previous.campaign, callsPerMinute: Number(event.target.value) } }))}
                      />
                    </Box>
                    <TextField
                      select
                      size="medium"
                      label="Launch State"
                      value={draft.campaign.status}
                      onChange={(event) =>
                        updateDraft((previous) => ({
                          ...previous,
                          campaign: { ...previous.campaign, status: event.target.value as Campaign["status"] },
                        }))
                      }
                    >
                      <MenuItem value="draft">Draft (Draft mode me rakhein)</MenuItem>
                    </TextField>

                    <Box sx={{ border: 1, borderColor: "divider", borderRadius: 1.5, p: 2, bgcolor: "background.default", mb: 2 }}>
                      <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1 }}>
                        Campaign Launch Checklist (Running eligibility check)
                      </Typography>
                      {launchChecks.map((check) => (
                        <Typography key={check.label} variant="body2" sx={{ display: "flex", alignItems: "center", gap: 1, mt: 0.5 }}>
                          {check.passed ? "✅ Passed: " : "⚠️ Pending: "}
                          <span style={{ color: check.passed ? "inherit" : "gray" }}>{check.label}</span>
                        </Typography>
                      ))}
                    </Box>

                    <Stack direction="row" spacing={1} sx={{ mt: 2, justifyContent: "space-between", alignItems: "center" }}>
                      <Button type="button" variant="outlined" onClick={() => setStepIndex(2)}>
                        ← Back
                      </Button>
                      <Button type="submit" disabled={saving}>
                        {saving ? "Launching..." : "Launch Campaign 🚀"}
                      </Button>
                    </Stack>
                  </Box>
                </Card>
              ) : null}

              {allDone ? (
                <Alert severity="success" variant="outlined">
                  Onboarding complete. This tenant is now ready for immediate operation.
                </Alert>
              ) : null}
            </Stack>
          )}

          {message ? (
            <Alert sx={{ mt: 2 }} severity={messageTone === "error" ? "error" : messageTone === "success" ? "success" : "info"}>
              {message}
            </Alert>
          ) : null}

          <Stack direction="row" spacing={1} sx={{ mt: 2 }}>
            <Button type="button" variant="outlined" onClick={() => void loadAll()} disabled={loading || saving}>
              Refresh
            </Button>
            <Button type="button" variant="outlined" onClick={saveProgressNow} disabled={loading}>
              Save Progress
            </Button>
          </Stack>
        </Card>

        <Card
          title="Critical Missing Steps Analysis"
          subtitle="Gaps that can still block immediate usability after onboarding and recommended mitigations."
        >
          <Stack spacing={1.25}>
            <GapRow
              title="User Profile Completion"
              impact="Missing personal profile metadata can break ownership routing, audit visibility, and escalation flows."
              recommendation="Require first and last name, timezone, and emergency contact before handoff to production."
            />
            <GapRow
              title="Payment Method Setup"
              impact="Without billing setup, outbound provider usage and feature limits can fail at runtime."
              recommendation="Add billing activation checkpoint with card validation and plan confirmation."
            />
            <GapRow
              title="Notification Preferences"
              impact="Users may miss failed imports, provider downtime, and campaign risk alerts."
              recommendation="Collect channel preferences (email/SMS/in-app) and severity thresholds."
            />
            <GapRow
              title="System-Wide Settings"
              impact="Default timezone, compliance windows, and caller-ID policies may be incorrect for production dialing."
              recommendation="Add tenant-wide defaults step for timezone, quiet hours, compliance policy, and caller-ID behavior."
            />
            <GapRow
              title="Operational Readiness"
              impact="No explicit smoke test can allow launch with hidden misconfiguration."
              recommendation="Run post-onboarding checks: test call, lead-to-agent assignment check, and campaign dry-run validation."
            />
          </Stack>
        </Card>
      </Box>
    </AppShell>
  );
}

function GapRow({ title, impact, recommendation }: { title: string; impact: string; recommendation: string }) {
  return (
    <Box sx={{ border: 1, borderColor: "divider", borderRadius: 1.5, p: 1.5 }}>
      <Typography variant="body2" sx={{ fontWeight: 700 }}>
        {title}
      </Typography>
      <Typography variant="body2" color="text.secondary">
        Impact: {impact}
      </Typography>
      <Typography variant="body2" color="text.secondary">
        Recommendation: {recommendation}
      </Typography>
    </Box>
  );
}
