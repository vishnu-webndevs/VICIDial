import { apiRequest } from "@/lib/api";
import { API_BASE_URL } from "@/lib/runtime-config";
import { getTenantContext, getTenantScopedStorageKey } from "@/lib/tenant-context";
import type {
  AgentEntity,
  AgentActivity,
  AgentAnalytics,
  AnalyticsSummary,
  CallDetail,
  CallRecord,
  Campaign,
  CampaignAnalytics,
  CampaignMessageReport,
  CampaignRunStatus,
  CampaignStatusPayload,
  DialQueueItem,
  Lead,
  LeadList,
  LeadImportStatus,
  LeadTimelineResponse,
  LeadStatus,
  MessageThread,
  MessageTemplate,
  MetaWhatsappTemplate,
  ProviderNumberOption,
  TeamMember,
  TrendPoint,
} from "@/types/product";

type ApiListResponse<T> = { data: T[]; meta?: Record<string, unknown> };
type ApiDataResponse<T> = { data: T; meta?: Record<string, unknown> };
type ApiEnvelope<T> = { data: T };

export async function listMetaTemplates(params: { provider_account_id?: string } = {}): Promise<MetaWhatsappTemplate[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  if (params.provider_account_id) {
    search.set("provider_account_id", params.provider_account_id);
  }
  const path = `/meta-templates${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<{ data: { templates: MetaWhatsappTemplate[] } }>(path, { token, tenantId });
  return response.data.templates ?? [];
}

export async function syncMetaTemplates(providerAccountId?: string): Promise<{ ok: boolean; count: number }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ data: { sync: { ok: boolean; count: number } } }>("/meta-templates/sync", {
    method: "POST",
    token,
    tenantId,
    body: { provider_account_id: providerAccountId },
  });
  return response.data.sync;
}

export async function sendWhatsAppDebugTest(payload: {
  phone_number: string;
  template_id: string;
  variables: Record<string, string>;
}): Promise<{ success: boolean; message: any; debug_result: any }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean; message: any; debug_result: any }>("/whatsapp-debug/send-test", {
    method: "POST",
    token,
    tenantId,
    body: payload,
  });
  return response;
}

export async function fetchWhatsAppDebugInspector(): Promise<{
  messages: any[];
  comparison: any[];
}> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ data: { messages: any[]; comparison: any[] } }>("/whatsapp-debug/delivery-inspector", {
    token,
    tenantId,
  });
  return response.data;
}

export type SessionProfile = {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  is_platform_admin: boolean;
  current_tenant: {
    id: string;
    name: string;
    slug: string;
    status: string;
  } | null;
  role: {
    slug: string;
    name: string;
  } | null;
  permissions: string[];
  plan: {
    name: string;
    slug: string;
    features: Record<string, { value: number | boolean | string; label: string; type: "limit" | "boolean" | "text" }>;
  } | null;
  usage: Record<string, number>;
};

export type PublicPlan = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  price_monthly: number;
  price_yearly: number;
  sort_order: number;
  features: Array<{
    id: string;
    key: string;
    type: "limit" | "boolean" | "text";
    value: string;
    label: string | null;
  }>;
};

export type ProviderAccount = {
  id: string;
  provider_type: string;
  display_name: string;
  status: string;
};

export type WebhookLog = {
  id: string;
  source: "provider" | "stripe";
  event_type: string;
  provider_event_type: string | null;
  status: string | null;
  processed_at: string | null;
  error_message: string | null;
};

export type WebhookOverview = {
  callback_urls: {
    stripe: string;
    twilio: string;
    vonage: string;
  };
  active_provider_accounts: {
    twilio: number;
    vonage: number;
  };
  window_hours: number;
  metrics: {
    stripe_total: number;
    stripe_failed: number;
    stripe_failure_rate: number;
    provider_total: number;
    provider_failed: number;
    provider_failure_rate: number;
  };
  recent_failures: Array<{
    id: string;
    source: "provider" | "stripe";
    event_type: string;
    status: string | null;
    error_message: string | null;
    occurred_at: string | null;
  }>;
};

export type PlannedFeatureStatus = {
  feature_key: string;
  status: string;
  evidence_count: number;
};

export type DialerLoopAction = {
  at: string;
  type: string;
  details?: Record<string, unknown>;
};

function nowIso(): string {
  return new Date().toISOString();
}

function createId(prefix: string): string {
  return `${prefix}-${Math.random().toString(16).slice(2, 10)}-${Date.now()}`;
}

function readTenantStore<T>(key: string, fallback: T): T {
  if (typeof window === "undefined") {
    return fallback;
  }

  const { tenantId } = getTenantContext();
  const scopedKey = getTenantScopedStorageKey(key, tenantId);
  const raw = localStorage.getItem(scopedKey);
  if (!raw) {
    return fallback;
  }

  try {
    return JSON.parse(raw) as T;
  } catch {
    return fallback;
  }
}

function writeTenantStore<T>(key: string, value: T): void {
  if (typeof window === "undefined") {
    return;
  }

  const { tenantId } = getTenantContext();
  const scopedKey = getTenantScopedStorageKey(key, tenantId);
  localStorage.setItem(scopedKey, JSON.stringify(value));
}

function isNotFoundError(error: unknown): boolean {
  return error instanceof Error && /404|not found|Request failed with status 404/i.test(error.message);
}

export async function createCall(payload: {
  to: string;
  agent_id?: string;
  from?: string;
  provider_account_id?: string;
  metadata?: Record<string, unknown>;
}): Promise<CallRecord> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<CallRecord>>("/calls", {
    method: "POST",
    token,
    tenantId,
    body: payload,
  });

  return response.data;
}

export async function listCalls(params: {
  status?: string;
  provider_account_id?: string;
  to_number?: string;
  from?: string;
  to?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}): Promise<{ calls: CallRecord[]; total: number; currentPage: number; lastPage: number }> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      search.set(key, String(value));
    }
  });

  const path = `/calls${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<ApiListResponse<CallRecord> & { meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    path,
    { token, tenantId }
  );

  const pagination = response.meta?.pagination ?? {};
  return {
    calls: response.data ?? [],
    total: Number(pagination.total ?? response.data.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function getCallDetail(callId: string): Promise<CallDetail> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<CallDetail>>(`/calls/${callId}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function retryCall(callId: string): Promise<CallRecord> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<CallRecord>>(`/calls/${callId}/retry`, {
    method: "POST",
    token,
    tenantId,
  });
  return response.data;
}

export async function dispatchCallNow(callId: string): Promise<CallRecord> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<CallRecord>>(`/calls/${callId}/dispatch-now`, {
    method: "POST",
    token,
    tenantId,
  });
  return response.data;
}

async function updateCallControl(
  callId: string,
  action: "mute" | "hold" | "end",
  payload: Record<string, unknown> = {}
): Promise<CallRecord> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<CallRecord>>(`/calls/${callId}/${action}`, {
    method: "POST",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function setCallMuted(callId: string, muted: boolean): Promise<CallRecord> {
  return updateCallControl(callId, "mute", { muted });
}

export async function setCallOnHold(callId: string, onHold: boolean): Promise<CallRecord> {
  return updateCallControl(callId, "hold", { on_hold: onHold });
}

export async function endCall(callId: string): Promise<CallRecord> {
  return updateCallControl(callId, "end");
}

export async function reportDialerLoopIncident(payload: {
  timestamp: string;
  session_id: string;
  loop_signature: string;
  browser: {
    user_agent: string;
    platform?: string;
    language?: string;
  };
  error_stack_trace?: string;
  actions: DialerLoopAction[];
  metadata?: Record<string, unknown>;
}): Promise<{ incident_id: string; logged_at: string }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ incident_id: string; logged_at: string }>>(
    "/calls/dialer-loop-incidents",
    {
      method: "POST",
      token,
      tenantId,
      body: payload,
    }
  );

  return response.data;
}

export async function fetchSessionProfile(): Promise<SessionProfile> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<SessionProfile>>("/auth/me", {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchPublicPlans(): Promise<PublicPlan[]> {
  const response = await apiRequest<ApiDataResponse<PublicPlan[]>>("/plans");
  return response.data ?? [];
}

export async function streamCallEvents(args: {
  onMessage: (nextCall: CallRecord) => void;
  onCursor?: (cursor: string) => void;
  cursor?: string;
  signal?: AbortSignal;
}): Promise<void> {
  const { onMessage, onCursor, cursor, signal } = args;
  const { token, tenantId } = getTenantContext();
  const url = new URL(`${API_BASE_URL}/realtime/calls/stream`);
  const isNgrokApi = /ngrok-free\.(app|dev)/i.test(API_BASE_URL);
  if (cursor) {
    url.searchParams.set("cursor", cursor);
  }

  const response = await fetch(url.toString(), {
    method: "GET",
    headers: {
      Accept: "text/event-stream",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(tenantId ? { "X-Tenant-Id": tenantId } : {}),
      ...(isNgrokApi ? { "ngrok-skip-browser-warning": "true" } : {}),
    },
    signal,
  });

  if (!response.ok || !response.body) {
    throw new Error(`Realtime stream failed with status ${response.status}`);
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  let eventType = "";
  let dataBuffer = "";

  while (true) {
    const { done, value } = await reader.read();
    if (done) {
      return;
    }

    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split(/\r?\n/);
    buffer = lines.pop() ?? "";

    for (const line of lines) {
      if (line.startsWith("event:")) {
        eventType = line.slice(6).trim();
        continue;
      }
      if (line.startsWith("data:")) {
        dataBuffer += line.slice(5).trim();
        continue;
      }
      if (line === "") {
        if (eventType === "call.status.updated" && dataBuffer) {
          try {
            const parsed = JSON.parse(dataBuffer) as { call?: CallRecord; cursor?: string };
            if (parsed.call) {
              onMessage(parsed.call);
            }
            if (parsed.cursor && onCursor) {
              onCursor(parsed.cursor);
            }
          } catch {
            // Ignore malformed payload chunk and keep stream alive.
          }
        }
        eventType = "";
        dataBuffer = "";
      }
    }
  }
}

export async function fetchCallAnalytics(from: string, to: string): Promise<AnalyticsSummary> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ summary: AnalyticsSummary }>>(
    `/analytics/calls?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&granularity=day`,
    { token, tenantId }
  );
  return response.data.summary;
}

export type DashboardSummary = {
  calls_today: number;
  active_campaigns: number;
  agents_online: number;
  conversion_rate: number;
  calls_per_hour: Array<{ hour: number; calls: number }>;
};

export async function fetchDashboardSummary(): Promise<DashboardSummary> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<DashboardSummary>>("/analytics/dashboard-summary", {
    token,
    tenantId,
  });
  return response.data;
}

export async function startCampaign(campaignId: string): Promise<{ campaign: Campaign; run: CampaignRunStatus | null }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ campaign: Campaign; run: CampaignRunStatus | null }>>(
    `/campaigns/${campaignId}/start`,
    { method: "POST", token, tenantId }
  );
  return response.data;
}

export async function pauseCampaign(campaignId: string): Promise<{ campaign: Campaign; run: CampaignRunStatus | null }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ campaign: Campaign; run: CampaignRunStatus | null }>>(
    `/campaigns/${campaignId}/pause`,
    { method: "POST", token, tenantId }
  );
  return response.data;
}

export async function stopCampaign(campaignId: string): Promise<{ campaign: Campaign; run: CampaignRunStatus | null }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ campaign: Campaign; run: CampaignRunStatus | null }>>(
    `/campaigns/${campaignId}/stop`,
    { method: "POST", token, tenantId }
  );
  return response.data;
}

export async function getCampaignStatus(campaignId: string): Promise<CampaignStatusPayload> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<CampaignStatusPayload>>(`/campaigns/${campaignId}/status`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function getCampaignQueue(
  campaignId: string,
  params: { page?: number; per_page?: number } = {}
): Promise<{ items: DialQueueItem[]; total: number }> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      search.set(key, String(value));
    }
  });
  const path = `/campaigns/${campaignId}/queue${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<ApiListResponse<DialQueueItem> & { meta?: { pagination?: { total?: number } } }>(
    path,
    { token, tenantId }
  );
  return {
    items: response.data,
    total: Number(response.meta?.pagination?.total ?? response.data.length),
  };
}

export async function loadCampaignCommandCenter(
  campaignId: string,
  params: { page?: number; per_page?: number } = {}
): Promise<{ entries: DialQueueItem[]; total: number; currentPage: number; lastPage: number }> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      search.set(key, String(value));
    }
  });
  const path = `/campaigns/${campaignId}/queue${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<ApiListResponse<DialQueueItem> & { meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    path,
    { token, tenantId }
  );
  const pagination = response.meta?.pagination ?? {};
  return {
    entries: response.data ?? [],
    total: Number(pagination.total ?? response.data.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function loadCampaignMessageReport(campaignId: string, params: { run_id?: string } = {}): Promise<CampaignMessageReport> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  if (params.run_id) {
    search.set("run_id", params.run_id);
  }
  const path = `/campaigns/${campaignId}/message-report${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<{ data: CampaignMessageReport }>(path, { token, tenantId });
  return response.data;
}

export async function loadCampaignStatus(campaignId: string): Promise<{
  queue_depth: number;
  active_calls: number;
  connect_rate: number;
  answer_rate: number;
  drop_rate: number;
  pacing_ratio: number;
  statuses: Record<string, number>;
}> {
  const status = await getCampaignStatus(campaignId);
  const pending = Number(status.queue?.pending ?? 0);
  const inProgress = Number(status.queue?.in_progress ?? 0);
  return {
    queue_depth: pending,
    active_calls: inProgress,
    connect_rate: 0,
    answer_rate: 0,
    drop_rate: 0,
    pacing_ratio: 1,
    statuses: {},
  };
}

export async function listAgentActivities(
  params: { page?: number; per_page?: number } = {}
): Promise<AgentActivity[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({
    page: String(params.page ?? 1),
    per_page: String(params.per_page ?? 25),
  });
  const response = await apiRequest<ApiListResponse<AgentActivity>>(`/agents/activities?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data ?? [];
}

export async function updateAgentSession(
  sessionId: string,
  payload: { paused?: boolean; pause_reason?: string }
): Promise<AgentActivity> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<AgentActivity>>(`/agents/sessions/${sessionId}`, {
    method: "PATCH",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function upsertAgentSession(payload: {
  agent_id: string;
  status: "offline" | "available" | "busy" | "on_break";
  capacity?: number;
}): Promise<AgentActivity> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<AgentActivity>>("/agents/session", {
    method: "POST",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function listAgents(): Promise<AgentEntity[]> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiListResponse<AgentEntity>>("/agents", { token, tenantId });
  return response.data ?? [];
}

export async function createAgent(input: { company_number: string; status?: "active" | "inactive"; destination_number?: string | null }): Promise<AgentEntity> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<AgentEntity>>("/agents", {
    method: "POST",
    token,
    tenantId,
    body: input,
  });
  return response.data;
}

export async function updateAgent(
  agentId: string,
  input: { company_number?: string; status?: "active" | "inactive"; destination_number?: string | null }
): Promise<AgentEntity> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<AgentEntity>>(`/agents/${agentId}`, {
    method: "PATCH",
    token,
    tenantId,
    body: input,
  });
  return response.data;
}

export async function deleteAgent(agentId: string): Promise<void> {
  const { token, tenantId } = getTenantContext();
  await apiRequest(`/agents/${agentId}`, {
    method: "DELETE",
    token,
    tenantId,
  });
}

export async function listValidatedProviderNumbers(): Promise<ProviderNumberOption[]> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiListResponse<ProviderNumberOption>>("/agents/validated-numbers", { token, tenantId });
  return response.data ?? [];
}

type RawProviderNumber = {
  sid?: string;
  phone_number: string;
  friendly_name?: string;
  capabilities?: Record<string, boolean>;
};

export async function fetchProviderNumbersFromTwilio(providerId: string): Promise<RawProviderNumber[]> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ data: { numbers: RawProviderNumber[] } }>(
    `/admin/settings/communication/providers/${providerId}/twilio/numbers`,
    { token, tenantId }
  );
  return response.data.numbers ?? [];
}

export async function syncProviderNumbers(providerId: string, numbers: RawProviderNumber[]): Promise<void> {
  const { token, tenantId } = getTenantContext();
  await apiRequest(`/admin/settings/communication/providers/${providerId}/numbers/sync`, {
    method: "POST",
    token,
    tenantId,
    body: { numbers },
  });
}

export async function assignAgentNumber(input: {
  agent_id: string;
  provider_account_id: string;
  provider_phone_number_id: string;
  status?: "active" | "inactive";
}): Promise<void> {
  const { token, tenantId } = getTenantContext();
  await apiRequest("/agents/number-assignments", {
    method: "POST",
    token,
    tenantId,
    body: input,
  });
}

export async function fetchCampaignAnalytics(params: {
  from: string;
  to: string;
  campaign_id?: string;
}): Promise<CampaignAnalytics[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({ from: params.from, to: params.to });
  if (params.campaign_id) {
    search.set("campaign_id", params.campaign_id);
  }
  const response = await apiRequest<ApiListResponse<CampaignAnalytics>>(`/analytics/campaigns?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchAgentAnalytics(params?: {
  from: string;
  to: string;
  agent_id?: string;
}): Promise<AgentAnalytics[]> {
  const { token, tenantId } = getTenantContext();
  const now = new Date();
  const fallbackFrom = new Date(now);
  fallbackFrom.setDate(now.getDate() - 30);
  const from = params?.from ?? fallbackFrom.toISOString().slice(0, 10);
  const to = params?.to ?? now.toISOString().slice(0, 10);
  const search = new URLSearchParams({ from, to });
  if (params?.agent_id) {
    search.set("agent_id", params.agent_id);
  }
  const response = await apiRequest<ApiListResponse<AgentAnalytics>>(`/analytics/agents?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchTrendAnalytics(params: {
  from: string;
  to: string;
  group_by?: "day" | "hour";
}): Promise<TrendPoint[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({
    from: params.from,
    to: params.to,
    group_by: params.group_by ?? "day",
  });
  const response = await apiRequest<ApiListResponse<TrendPoint>>(`/analytics/trends?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchHeatmapAnalytics(from: string, to: string): Promise<Array<Record<string, unknown>>> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({ from, to });
  const response = await apiRequest<ApiListResponse<Record<string, unknown>>>(`/analytics/heatmap?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchScorecardsAnalytics(from: string, to: string): Promise<Array<Record<string, unknown>>> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({ from, to });
  const response = await apiRequest<ApiListResponse<Record<string, unknown>>>(`/analytics/scorecards?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchListPerformanceAnalytics(from: string, to: string): Promise<Array<Record<string, unknown>>> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({ from, to });
  const response = await apiRequest<ApiListResponse<Record<string, unknown>>>(`/analytics/lists?${search.toString()}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function fetchPlannedFeatureStatus(): Promise<{
  generated_at: string;
  features: PlannedFeatureStatus[];
}> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{
    generated_at: string;
    features: PlannedFeatureStatus[];
  }>>("/features/planned/status", {
    token,
    tenantId,
  });
  return response.data;
}

type LeadInput = Omit<Lead, "id" | "updated_at">;

export async function listLeads(options: { listId?: string; perPage?: number } = {}): Promise<Lead[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  search.set("per_page", String(options.perPage ?? 200));
  if (options.listId) {
    search.set("list_id", options.listId);
  }
  try {
    const response = await apiRequest<ApiListResponse<Lead>>(`/leads?${search.toString()}`, { token, tenantId });
    return response.data;
  } catch (error) {
    if (!isNotFoundError(error)) {
      throw error;
    }
  }

  return readTenantStore<Lead[]>("leads", []);
}

export async function saveLead(input: LeadInput, leadId?: string): Promise<Lead> {
  const { token, tenantId } = getTenantContext();
  const path = leadId ? `/leads/${leadId}` : "/leads";
  const method = leadId ? "PATCH" : "POST";

  try {
    const response = await apiRequest<ApiDataResponse<Lead>>(path, {
      method,
      token,
      tenantId,
      body: input,
    });
    return response.data;
  } catch (error) {
    if (!isNotFoundError(error)) {
      throw error;
    }
  }

  const leads = readTenantStore<Lead[]>("leads", []);
  const updatedLead: Lead = leadId
    ? {
        ...(leads.find((lead) => lead.id === leadId) ?? {
          id: leadId,
          full_name: input.full_name,
          phone: input.phone,
          status: input.status,
          owner_agent: input.owner_agent,
          tags: [],
          notes: [],
        }),
        ...input,
        updated_at: nowIso(),
      }
    : {
        ...input,
        id: createId("lead"),
        updated_at: nowIso(),
      };

  const nextLeads = leadId
    ? leads.map((lead) => (lead.id === leadId ? updatedLead : lead))
    : [updatedLead, ...leads];
  writeTenantStore("leads", nextLeads);
  return updatedLead;
}

export async function importLeads(rows: Array<{ full_name: string; phone: string; email?: string }>): Promise<Lead[]> {
  const csv = rows
    .map((row) => [row.full_name, row.phone, row.email ?? ""].join(","))
    .join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const file = new File([blob], "leads.csv", { type: "text/csv" });
  return importLeadsFromFile(file).then(() => rows.map((row) => ({
    id: createId("lead"),
    full_name: row.full_name,
    phone: row.phone,
    email: row.email,
    company: "",
    status: "new" as LeadStatus,
    owner_agent: "Unassigned",
    next_follow_up_at: null,
    tags: [],
    notes: ["Imported from CSV text"],
    updated_at: nowIso(),
  })));
}

export async function importLeadsFromFile(
  file: File,
  options: { list_ids?: string[]; skip_duplicates?: boolean; skip_dnc?: boolean } = {}
): Promise<{ job_id: string; status: string }> {
  const { token, tenantId } = getTenantContext();
  const formData = new FormData();
  formData.append("file", file);
  if (options.list_ids && options.list_ids.length > 0) {
    options.list_ids.forEach((id) => formData.append("list_ids[]", id));
  }
  if (typeof options.skip_duplicates === "boolean") {
    formData.append("skip_duplicates", options.skip_duplicates ? "1" : "0");
  }
  if (typeof options.skip_dnc === "boolean") {
    formData.append("skip_dnc", options.skip_dnc ? "1" : "0");
  }
  const response = await apiRequest<ApiDataResponse<{ job_id: string; status: string }>>("/leads/import", {
    method: "POST",
    token,
    tenantId,
    body: formData,
  });
  return response.data;
}

export async function listLeadLists(): Promise<LeadList[]> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiListResponse<LeadList>>("/lead-lists", { token, tenantId });
  return response.data;
}

export async function createLeadList(payload: {
  name: string;
  description?: string;
  is_active?: boolean;
}): Promise<LeadList> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<LeadList>>("/lead-lists", {
    method: "POST",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function attachLeadsToList(listId: string, leadIds: string[]): Promise<{ list_id: string; attached_count: number }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ list_id: string; attached_count: number }>>(`/lead-lists/${listId}/leads`, {
    method: "POST",
    token,
    tenantId,
    body: { lead_ids: leadIds },
  });
  return response.data;
}

export async function detachLeadsFromList(listId: string, leadIds: string[]): Promise<{ list_id: string; detached_count: number }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<{ list_id: string; detached_count: number }>>(`/lead-lists/${listId}/leads/detach`, {
    method: "POST",
    token,
    tenantId,
    body: { lead_ids: leadIds },
  });
  return response.data;
}

export async function fetchLeadTimeline(leadId: string, params: { page?: number; per_page?: number } = {}): Promise<{
  payload: LeadTimelineResponse;
  total: number;
  currentPage: number;
  lastPage: number;
}> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      search.set(key, String(value));
    }
  });
  const path = `/leads/${leadId}/timeline${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<ApiDataResponse<LeadTimelineResponse> & { meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    path,
    { token, tenantId }
  );

  const pagination = response.meta?.pagination ?? {};
  return {
    payload: response.data,
    total: Number(pagination.total ?? response.data.timeline.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function sendLeadSms(leadId: string, body: string): Promise<Record<string, unknown>> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<Record<string, unknown>>>(`/leads/${leadId}/sms`, {
    method: "POST",
    token,
    tenantId,
    body: { content: body },
  });
  return response.data;
}

export async function sendLeadWhatsapp(leadId: string, body: string): Promise<Record<string, unknown>> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<Record<string, unknown>>>(`/leads/${leadId}/whatsapp`, {
    method: "POST",
    token,
    tenantId,
    body: { content: body },
  });
  return response.data;
}

export async function listCallbacks(params: { state?: "due" | "recent"; page?: number; per_page?: number } = {}): Promise<{
  data: Array<Record<string, unknown>>;
  total: number;
  currentPage: number;
  lastPage: number;
}> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      search.set(key, String(value));
    }
  });
  const response = await apiRequest<ApiListResponse<Record<string, unknown>> & { meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    `/leads/callbacks${search.toString() ? `?${search.toString()}` : ""}`,
    { token, tenantId }
  );
  const pagination = response.meta?.pagination ?? {};
  return {
    data: response.data,
    total: Number(pagination.total ?? response.data.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function listInboxThreads(
  channel: "sms" | "whatsapp",
  params: { per_page?: number; page?: number; status?: string; priority?: string } = {}
): Promise<{
  data: MessageThread[];
  total: number;
  currentPage: number;
  lastPage: number;
}> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({
    channel,
    per_page: String(params.per_page ?? 25),
    page: String(params.page ?? 1),
  });
  if (params.status) {
    search.set("status", params.status);
  }
  if (params.priority) {
    search.set("priority", params.priority);
  }
  const response = await apiRequest<{ success: boolean; data: MessageThread[]; meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    `/inbox/threads?${search.toString()}`,
    { token, tenantId }
  );
  const pagination = response.meta?.pagination ?? {};
  return {
    data: response.data,
    total: Number(pagination.total ?? response.data.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function sendInboxThreadMessage(threadId: string, body: string): Promise<Record<string, unknown>> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean; data: Record<string, unknown> }>(`/inbox/threads/${threadId}/messages`, {
    method: "POST",
    token,
    tenantId,
    body: { body },
  });
  return response.data;
}

export async function deleteInboxThread(threadId: string): Promise<boolean> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean }>(`/inbox/threads/${threadId}`, {
    method: "DELETE",
    token,
    tenantId,
  });
  return response.success;
}

export async function clearInboxThreadMessages(threadId: string): Promise<boolean> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean }>(`/inbox/threads/${threadId}/messages`, {
    method: "DELETE",
    token,
    tenantId,
  });
  return response.success;
}

export async function listInboxThreadMessages(threadId: string, params: { per_page?: number; page?: number } = {}): Promise<{
  data: any[];
  total: number;
  currentPage: number;
  lastPage: number;
}> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({
    per_page: String(params.per_page ?? 50),
    page: String(params.page ?? 1),
  });
  const response = await apiRequest<{ success: boolean; data: any[]; meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    `/inbox/threads/${threadId}/messages?${search.toString()}`,
    { token, tenantId }
  );
  const pagination = response.meta?.pagination ?? {};
  return {
    data: response.data,
    total: Number(pagination.total ?? response.data.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function updateInboxThread(
  threadId: string,
  payload: { assigned_user_id?: string | null; status?: string; priority?: string }
): Promise<MessageThread> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean; data: MessageThread }>(`/inbox/threads/${threadId}`, {
    method: "PATCH",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function fetchInboxSlaPolicy(): Promise<{
  enabled: boolean;
  first_response_minutes: number;
  resolution_minutes: number;
}> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean; data: { enabled: boolean; first_response_minutes: number; resolution_minutes: number } }>(
    "/inbox/sla-policy",
    { token, tenantId }
  );
  return response.data;
}

export async function updateInboxSlaPolicy(payload: {
  enabled?: boolean;
  first_response_minutes?: number;
  resolution_minutes?: number;
}): Promise<{ enabled: boolean; first_response_minutes: number; resolution_minutes: number }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ success: boolean; data: { enabled: boolean; first_response_minutes: number; resolution_minutes: number } }>(
    "/inbox/sla-policy",
    { method: "POST", token, tenantId, body: payload }
  );
  return response.data;
}

export async function listTeamMembers(params: { page?: number; per_page?: number } = {}): Promise<{
  data: TeamMember[];
  total: number;
  currentPage: number;
  lastPage: number;
}> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams({
    page: String(params.page ?? 1),
    per_page: String(params.per_page ?? 25),
  });
  const response = await apiRequest<ApiListResponse<TeamMember> & { meta?: { pagination?: { total?: number; current_page?: number; last_page?: number } } }>(
    `/team/members?${search.toString()}`,
    { token, tenantId }
  );
  const pagination = response.meta?.pagination ?? {};
  return {
    data: response.data,
    total: Number(pagination.total ?? response.data.length),
    currentPage: Number(pagination.current_page ?? params.page ?? 1),
    lastPage: Number(pagination.last_page ?? 1),
  };
}

export async function listProviderAccounts(): Promise<ProviderAccount[]> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiListResponse<ProviderAccount>>("/providers", { token, tenantId });
  return response.data;
}

export async function listMessageTemplates(params: { channel?: "sms" | "whatsapp"; category?: string; q?: string; active?: boolean } = {}): Promise<MessageTemplate[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  if (params.channel) search.set("channel", params.channel);
  if (params.category) search.set("category", params.category);
  if (params.q) search.set("q", params.q);
  if (typeof params.active === "boolean") search.set("active", params.active ? "1" : "0");
  const path = `/message-templates${search.toString() ? `?${search.toString()}` : ""}`;
  const response = await apiRequest<ApiListResponse<MessageTemplate>>(path, { token, tenantId });
  return response.data;
}

export async function createMessageTemplate(payload: Pick<MessageTemplate, "channel" | "key" | "name" | "body"> & { category?: string | null; is_active?: boolean }): Promise<MessageTemplate> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<MessageTemplate>>("/message-templates", {
    method: "POST",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function updateMessageTemplate(id: string, payload: Partial<Pick<MessageTemplate, "name" | "body" | "is_active" | "category">>): Promise<MessageTemplate> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<MessageTemplate>>(`/message-templates/${id}`, {
    method: "PATCH",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function deleteMessageTemplate(id: string): Promise<void> {
  const { token, tenantId } = getTenantContext();
  await apiRequest(`/message-templates/${id}`, { method: "DELETE", token, tenantId });
}

export type WhatsAppIntegrationProvider = {
  id: string;
  provider_type: string;
  display_name: string;
  status: string;
  last_tested_at?: string | null;
  last_error_code?: string | null;
  last_error_message?: string | null;
  secrets?: {
    meta_app_secret_configured?: boolean;
    meta_access_token_configured?: boolean;
    webhook_verify_token_configured?: boolean;
    meta_app_secret_suffix?: string | null;
    meta_access_token_suffix?: string | null;
    webhook_verify_token_suffix?: string | null;
  };
  settings: {
    enabled: boolean;
    meta_app_id?: string | null;
    meta_app_secret?: string | null;
    meta_access_token?: string | null;
    whatsapp_business_account_id?: string | null;
    phone_number_id?: string | null;
    webhook_verify_token?: string | null;
  };
};

export async function getWhatsAppIntegration(): Promise<WhatsAppIntegrationProvider | null> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ data: { provider: WhatsAppIntegrationProvider | null } }>("/whatsapp-integration", { token, tenantId });
  return response.data.provider;
}

export async function saveWhatsAppIntegration(payload: {
  enabled: boolean;
  display_name?: string | null;
  meta_app_id?: string | null;
  meta_app_secret?: string | null;
  meta_access_token?: string | null;
  whatsapp_business_account_id?: string | null;
  phone_number_id?: string | null;
  webhook_verify_token?: string | null;
}): Promise<WhatsAppIntegrationProvider> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ data: { provider: WhatsAppIntegrationProvider } }>("/whatsapp-integration", {
    method: "PUT",
    token,
    tenantId,
    body: payload,
  });
  return response.data.provider;
}

export async function testWhatsAppIntegration(): Promise<{ ok: boolean; provider: WhatsAppIntegrationProvider; meta?: { display_phone_number?: string | null; verified_name?: string | null }; error?: string; status_code?: number }> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<{ data: { ok: boolean; provider: WhatsAppIntegrationProvider; meta?: { display_phone_number?: string | null; verified_name?: string | null }; error?: string; status_code?: number } }>(
    "/whatsapp-integration/test",
    { method: "POST", token, tenantId }
  );
  return response.data;
}

export async function getLeadImportJob(jobId: string): Promise<LeadImportStatus> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<LeadImportStatus>>(`/leads/import-jobs/${jobId}`, {
    token,
    tenantId,
  });
  return response.data;
}

type CampaignInput = Omit<Campaign, "id" | "updated_at">;

export async function listCampaigns(params: { type?: string } = {}): Promise<Campaign[]> {
  const { token, tenantId } = getTenantContext();
  const search = new URLSearchParams();
  if (params.type) {
    search.set("type", params.type);
  }
  try {
    const path = `/campaigns${search.toString() ? `?${search.toString()}` : ""}`;
    const response = await apiRequest<ApiListResponse<Campaign>>(path, { token, tenantId });
    return response.data;
  } catch (error) {
    if (!isNotFoundError(error)) {
      throw error;
    }
  }

  return readTenantStore<Campaign[]>("campaigns", []);
}

export async function updateCampaign(campaignId: string, payload: Record<string, unknown>): Promise<Campaign> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiDataResponse<Campaign>>(`/campaigns/${campaignId}`, {
    method: "PATCH",
    token,
    tenantId,
    body: payload,
  });
  return response.data;
}

export async function saveCampaign(input: CampaignInput, campaignId?: string): Promise<Campaign> {
  const { token, tenantId } = getTenantContext();
  const path = campaignId ? `/campaigns/${campaignId}` : "/campaigns";
  const method = campaignId ? "PATCH" : "POST";

  try {
    const response = await apiRequest<ApiDataResponse<Campaign>>(path, {
      method,
      token,
      tenantId,
      body: input,
    });
    return response.data;
  } catch (error) {
    if (!isNotFoundError(error)) {
      throw error;
    }
  }

  const campaigns = readTenantStore<Campaign[]>("campaigns", []);
  const updated: Campaign = campaignId
    ? {
        ...(campaigns.find((campaign) => campaign.id === campaignId) ?? {
          id: campaignId,
          name: input.name,
          type: input.type,
          status: "draft",
          lead_list_name: input.lead_list_name,
          schedule_window: input.schedule_window,
          retry_limit: input.retry_limit,
          queue_size: input.queue_size,
        }),
        ...input,
        updated_at: nowIso(),
      }
    : {
        ...input,
        id: createId("campaign"),
        updated_at: nowIso(),
      };

  const next = campaignId
    ? campaigns.map((campaign) => (campaign.id === campaignId ? updated : campaign))
    : [updated, ...campaigns];
  writeTenantStore("campaigns", next);
  return updated;
}

export async function fetchWebhookOverview(): Promise<WebhookOverview> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiEnvelope<WebhookOverview>>("/webhooks/overview", { token, tenantId });
  return response.data;
}

export async function listWebhookLogs(perPage = 50): Promise<WebhookLog[]> {
  const { token, tenantId } = getTenantContext();
  const response = await apiRequest<ApiEnvelope<WebhookLog[]>>(`/webhooks/delivery-logs?per_page=${perPage}`, {
    token,
    tenantId,
  });
  return response.data;
}

export async function replayWebhookEvent(source: "provider" | "stripe", id: string): Promise<void> {
  const { token, tenantId } = getTenantContext();
  await apiRequest("/webhooks/replay", {
    method: "POST",
    token,
    tenantId,
    body: { source, id },
  });
}
