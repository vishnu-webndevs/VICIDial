export type CallStatus =
  | "queued"
  | "ringing"
  | "in_progress"
  | "completed"
  | "failed"
  | "busy"
  | "no_answer"
  | "timeout"
  | "rejected"
  | "canceled";

export type CallRecord = {
  id: string;
  direction: "outbound" | "inbound";
  status: CallStatus;
  provider_call_id?: string | null;
  from_number: string;
  to_number: string;
  duration_seconds: number;
  retry_count: number;
  failure_reason: string | null;
  provider: {
    id: string;
    label: string;
    type?: string;
  } | null;
  started_at?: string | null;
  ended_at?: string | null;
  created_at: string;
  controls?: {
    muted: boolean;
    on_hold: boolean;
  };
};

export type CallEvent = {
  type: string;
  provider_event_type?: string | null;
  status_after?: string | null;
  occurred_at: string;
};

export type CallDetail = CallRecord & {
  metadata?: Record<string, unknown>;
  initiated_by?: {
    id: string;
    name: string;
  } | null;
  events: CallEvent[];
};

export type LeadStatus =
  | "new"
  | "contacted"
  | "qualified"
  | "proposal"
  | "won"
  | "lost"
  | "follow_up";

export type Lead = {
  id: string;
  full_name: string;
  phone: string;
  email?: string;
  company?: string;
  status: LeadStatus;
  owner_agent: string;
  next_follow_up_at?: string | null;
  tags: string[];
  notes: string[];
  updated_at: string;
};

export type LeadImportStatus = {
  id: string;
  status: "queued" | "processing" | "completed" | "failed";
  file_name: string;
  total_rows: number;
  processed_rows: number;
  successful_rows: number;
  failed_rows: number;
  progress: number;
  errors: Array<{ row: number | null; message: string }>;
  started_at?: string | null;
  finished_at?: string | null;
  created_at?: string | null;
};

export type CampaignType = "outbound_call" | "sms" | "whatsapp" | "outreach" | "manual" | "auto";

export type Campaign = {
  id: string;
  name: string;
  type: CampaignType;
  status: "draft" | "running" | "paused" | "completed" | "scheduled";
  lead_list_name: string;
  lead_list_ids?: string[];
  schedule_window: string;
  retry_limit: number;
  queue_size: number;
  calls_per_minute?: number;
  priority?: number;
  preferred_provider_account_id?: string | null;
  channel?: "sms" | "whatsapp" | null;
  provider_account_id?: string | null;
  message_content?: string | null;
  message_template_key?: string | null;
  updated_at: string;
};

export type MessageTemplate = {
  id: string;
  tenant_id: string;
  channel: "sms" | "whatsapp";
  category?: string | null;
  key: string;
  name: string;
  body: string;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
};

export type AnalyticsSummary = {
  total_calls: number;
  completed: number;
  failed: number;
  success_rate: number;
  total_duration_seconds: number;
  avg_duration_seconds: number;
};

export type CampaignRunStatus = {
  id: string;
  status: "queued" | "running" | "paused" | "stopped" | "completed";
  total_items: number;
  queued_items: number;
  completed_items: number;
  failed_items: number;
  retried_items: number;
  calls_dispatched: number;
  calls_connected: number;
  calls_failed: number;
  calls_per_minute: number;
  started_at?: string | null;
  paused_at?: string | null;
  stopped_at?: string | null;
  last_tick_at?: string | null;
};

export type DialQueueItem = {
  id: string;
  status: "pending" | "processing" | "dialed" | "completed" | "failed";
  priority: number;
  attempt_count: number;
  max_attempts: number;
  failure_reason?: string | null;
  available_at?: string | null;
  enqueued_at?: string | null;
  processed_at?: string | null;
  lead: {
    id: string;
    full_name: string;
    phone: string;
  } | null;
  agent: {
    id: string;
    name: string;
  } | null;
};

export type AgentActivity = {
  id: string;
  agent_id: string;
  name: string;
  agent_name?: string;
  status: "offline" | "available" | "busy" | "on_break" | "online";
  paused?: boolean;
  calls_handled?: number;
  last_active_at?: string | null;
  capacity: number;
  active_assignments: number;
  last_heartbeat_at?: string | null;
};

export type AgentEntity = {
  id: string;
  company_number: string;
  status: "active" | "inactive";
  created_at?: string | null;
  destination_number?: string | null;
  default_number?: {
    id: string;
    provider_account_id: string;
    phone_number: string;
    friendly_name?: string | null;
  } | null;
  session?: {
    id: string;
    status: string;
    capacity: number;
    active_assignments: number;
    last_heartbeat_at?: string | null;
  } | null;
};

export type ProviderNumberOption = {
  id: string;
  provider_account_id: string;
  phone_number: string;
  friendly_name?: string | null;
  status: string;
  is_validated: boolean;
};

export type CampaignStatusPayload = {
  campaign: Campaign;
  run: CampaignRunStatus | null;
  queue: {
    pending: number;
    in_progress: number;
    completed: number;
    failed: number;
  };
  agents: AgentActivity[];
};

export type CampaignAnalytics = {
  campaign_id: string | null;
  total_calls: number;
  connected_calls: number;
  unsuccessful_calls: number;
  success_rate: number;
  total_duration_seconds: number;
  avg_duration_seconds: number;
};

export type CampaignMessageReportEntry = {
  thread_id: string;
  channel: string;
  counterparty_number: string;
  lead: { id: string; full_name: string; phone: string } | null;
  outbound: Array<{
    id: string;
    status: string;
    body: string;
    sent_at?: string | null;
    delivered_at?: string | null;
    provider_message_id?: string | null;
  }>;
  inbound: Array<{
    id: string;
    status: string;
    body: string;
    sent_at?: string | null;
  }>;
  counts: { outbound: number; inbound: number };
};

export type CampaignMessageReport = {
  campaign_id: string;
  campaign_run_id: string;
  channel: string;
  entries: CampaignMessageReportEntry[];
};

export type AgentAnalytics = {
  agent_id: string;
  agent_name?: string;
  total_calls: number;
  connected_calls: number;
  success_rate: number;
  avg_duration_seconds: number;
};

export type TrendPoint = {
  bucket: string;
  total_calls: number;
  connected_calls: number;
  unsuccessful_calls: number;
};

export type LeadList = {
  id: string;
  name: string;
  description?: string | null;
  is_active: boolean;
  leads_count?: number;
  created_at?: string;
  updated_at?: string;
};

export type LeadTimelineEntry = {
  type: "call" | "sms" | "whatsapp" | "note" | "callback";
  id: string;
  at: string;
  direction?: string | null;
  content?: string | null;
  agent?: string | null;
  duration?: number | null;
  disposition?: string | null;
  recording_url?: string | null;
  recording_duration?: number | null;
  status?: string | null;
  scheduled_at?: string | null;
};

export type LeadTimelineResponse = {
  lead_id: string;
  lead: Lead;
  timeline: LeadTimelineEntry[];
};

export type MessageThread = {
  id: string;
  tenant_id?: string;
  channel: "sms" | "whatsapp";
  counterparty_number: string;
  contact_id?: string | null;
  project_id?: string | null;
  assigned_user_id?: string | null;
  lead_id?: string | null;
  subject?: string | null;
  status?: string;
  priority?: "low" | "normal" | "high" | "urgent" | string;
  last_message_at?: string | null;
  first_inbound_at?: string | null;
  first_outbound_at?: string | null;
  first_response_due_at?: string | null;
  resolution_due_at?: string | null;
  sla_first_response_breached_at?: string | null;
  sla_resolution_breached_at?: string | null;
  metadata?: Record<string, unknown>;
};

export type TeamMember = {
  id: string;
  status: string;
  role: {
    slug: string;
    name: string;
  };
  user: {
    id: string;
    email: string;
    first_name: string;
    last_name: string;
  } | null;
};
