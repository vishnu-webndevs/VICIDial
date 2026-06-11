"use client";

import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Box,
  MenuItem,
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
  Chip,
} from "@/ui";
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, SkeletonLines, ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import {
  deleteLead,
  getLeadImportJob,
  importLeadsFromFile,
  listLeads,
  saveLead,
  listAgents,
  listLeadLists,
  createLeadList,
  attachLeadsToList,
  detachLeadsFromList,
} from "@/lib/product-api";
import { getTenantContext } from "@/lib/tenant-context";
import type { Lead, LeadImportStatus, LeadStatus, AgentEntity, LeadList } from "@/types/product";

const leadStatuses: LeadStatus[] = [
  "new",
  "contacted",
  "qualified",
  "proposal",
  "won",
  "lost",
  "follow_up",
];

type CountryDialOption = {
  countryCode: string;
  label: string;
  dialCode: string;
};

const countryDialOptions: CountryDialOption[] = [
  { countryCode: "US", label: "United States", dialCode: "+1" },
  { countryCode: "CA", label: "Canada", dialCode: "+1" },
  { countryCode: "GB", label: "United Kingdom", dialCode: "+44" },
  { countryCode: "AU", label: "Australia", dialCode: "+61" },
  { countryCode: "IN", label: "India", dialCode: "+91" },
  { countryCode: "PH", label: "Philippines", dialCode: "+63" },
  { countryCode: "AE", label: "UAE", dialCode: "+971" },
  { countryCode: "SA", label: "Saudi Arabia", dialCode: "+966" },
  { countryCode: "DE", label: "Germany", dialCode: "+49" },
  { countryCode: "FR", label: "France", dialCode: "+33" },
  { countryCode: "ES", label: "Spain", dialCode: "+34" },
  { countryCode: "BR", label: "Brazil", dialCode: "+55" },
];
const DEFAULT_COUNTRY = "US";
const PHONE_E164_REGEX = /^\+[1-9]\d{7,14}$/;

const countryPhoneLength: Record<string, number | number[]> = {
  US: 10,
  CA: 10,
  GB: [10, 11],
  AU: 9,
  IN: 10,
  PH: 10,
  AE: 9,
  SA: 9,
  DE: [10, 11],
  FR: 9,
  ES: 9,
  BR: [10, 11],
};

type LeadFormState = {
  id?: string;
  full_name: string;
  phone_country: string;
  phone_local: string;
  email: string;
  company: string;
  status: LeadStatus;
  owner_agent: string;
  next_follow_up_at: string;
  tags: string;
  notes: string;
};

function createDefaultLeadForm(defaultCountry = DEFAULT_COUNTRY): LeadFormState {
  return {
    full_name: "",
    phone_country: defaultCountry,
    phone_local: "",
    email: "",
    company: "",
    status: "new",
    owner_agent: "",
    next_follow_up_at: "",
    tags: "",
    notes: "",
  };
}

function getCountryOption(countryCode: string): CountryDialOption {
  return countryDialOptions.find((option) => option.countryCode === countryCode) ?? countryDialOptions[0];
}

function parsePhoneForForm(phone: string, fallbackCountry: string): { phone_country: string; phone_local: string } {
  const normalized = phone.replace(/\s+/g, "");
  if (!normalized.startsWith("+")) {
    return { phone_country: fallbackCountry, phone_local: normalized };
  }

  const matched = [...countryDialOptions]
    .sort((a, b) => b.dialCode.length - a.dialCode.length)
    .find((option) => normalized.startsWith(option.dialCode));
  if (!matched) {
    return { phone_country: fallbackCountry, phone_local: normalized.slice(1) };
  }

  return {
    phone_country: matched.countryCode,
    phone_local: normalized.slice(matched.dialCode.length),
  };
}

type PageTab = "leads" | "lists";

export default function LeadsPage() {
  const [activeTab, setActiveTab] = useState<PageTab>("leads");
  const [leads, setLeads] = useState<Lead[]>([]);
  const [agents, setAgents] = useState<AgentEntity[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [defaultLeadCountry, setDefaultLeadCountry] = useState(DEFAULT_COUNTRY);
  const [form, setForm] = useState<LeadFormState>(createDefaultLeadForm(DEFAULT_COUNTRY));
  const [importState, setImportState] = useState<LeadImportStatus | null>(null);
  const [importing, setImporting] = useState(false);
  const [selectedLeadId, setSelectedLeadId] = useState<string>("");

  // Lead Lists state
  const [lists, setLists] = useState<LeadList[]>([]);
  const [selectedListId, setSelectedListId] = useState("");
  const [selectedLeadIdsForList, setSelectedLeadIdsForList] = useState<string[]>([]);
  const [listMode, setListMode] = useState<"add" | "remove">("add");
  const [listName, setListName] = useState("");
  const [listDescription, setListDescription] = useState("");
  const [listsLoading, setListsLoading] = useState(false);

  async function load() {
    setLoading(true);
    try {
      const [leadsData, agentsData] = await Promise.all([
        listLeads(),
        listAgents().catch(() => []),
      ]);
      setLeads(leadsData);
      setAgents(agentsData);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load leads.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }

  const loadLists = useCallback(async () => {
    setListsLoading(true);
    try {
      const [listData, leadData] = await Promise.all([
        listLeadLists(),
        listMode === "remove" && selectedListId ? listLeads({ listId: selectedListId }) : listLeads(),
      ]);
      setLists(listData);
      setLeads(leadData);
      if (!selectedListId && listData.length > 0) {
        setSelectedListId(listData[0].id);
      }
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load lists data.");
      setMessageTone("error");
    } finally {
      setListsLoading(false);
    }
  }, [listMode, selectedListId]);

  async function loadLeadDefaults() {
    try {
      const { token, tenantId } = getTenantContext();
      const response = await apiRequest<{ data?: { settings?: { metadata?: { default_lead_country?: string } } } }>(
        "/tenant",
        { token, tenantId }
      );
      const configuredCountry = response.data?.settings?.metadata?.default_lead_country?.toUpperCase();
      const resolvedCountry = countryDialOptions.some((option) => option.countryCode === configuredCountry)
        ? configuredCountry!
        : DEFAULT_COUNTRY;

      setDefaultLeadCountry(resolvedCountry);
      setForm((prev) => (
        prev.id || prev.phone_local
          ? prev
          : { ...prev, phone_country: resolvedCountry }
      ));
    } catch {
      // Keep sane defaults when tenant settings cannot be loaded.
    }
  }

  useEffect(() => {
    void load();
    void loadLeadDefaults();
  }, []);

  // Load lists when switching to lists tab
  useEffect(() => {
    if (activeTab === "lists") {
      void loadLists();
    }
  }, [activeTab, loadLists]);

  async function onCreateList(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    try {
      await createLeadList({ name: listName.trim(), description: listDescription.trim() || undefined });
      setListName("");
      setListDescription("");
      setMessage("Lead list created.");
      setMessageTone("success");
      await loadLists();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to create lead list.");
      setMessageTone("error");
    }
  }

  async function onAttachLeads() {
    if (!selectedListId || selectedLeadIdsForList.length === 0) return;
    setMessage("");
    try {
      const response = await attachLeadsToList(selectedListId, selectedLeadIdsForList);
      setMessage(`${response.attached_count} leads attached to selected list.`);
      setMessageTone("success");
      setSelectedLeadIdsForList([]);
      await loadLists();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to attach leads.");
      setMessageTone("error");
    }
  }

  async function onDetachLeads() {
    if (!selectedListId || selectedLeadIdsForList.length === 0) return;
    setMessage("");
    try {
      const response = await detachLeadsFromList(selectedListId, selectedLeadIdsForList);
      setMessage(`${response.detached_count} leads removed from selected list.`);
      setMessageTone("success");
      setSelectedLeadIdsForList([]);
      await loadLists();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to remove leads from list.");
      setMessageTone("error");
    }
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    setMessageTone("neutral");
    const localDigits = form.phone_local.replace(/\D+/g, "");
    
    // Country-specific length validation
    const expectedLength = countryPhoneLength[form.phone_country];
    if (expectedLength) {
      const isValidLength = Array.isArray(expectedLength) 
        ? expectedLength.includes(localDigits.length) 
        : localDigits.length === expectedLength;
        
      if (!isValidLength) {
        const expectedText = Array.isArray(expectedLength) ? expectedLength.join(" or ") : expectedLength;
        setMessage(`Phone number for ${getCountryOption(form.phone_country).label} must be exactly ${expectedText} digits.`);
        setMessageTone("error");
        return;
      }
    }

    const dialCode = getCountryOption(form.phone_country).dialCode;
    const normalizedPhone = `${dialCode}${localDigits}`;
    if (!PHONE_E164_REGEX.test(normalizedPhone)) {
      setMessage("Phone is invalid. Select country code and enter a valid number.");
      setMessageTone("error");
      return;
    }

    try {
      await saveLead(
        {
          full_name: form.full_name,
          phone: normalizedPhone,
          email: form.email || undefined,
          company: form.company || undefined,
          status: form.status,
          owner_agent: form.owner_agent || "Unassigned",
          next_follow_up_at: form.next_follow_up_at || null,
          tags: form.tags
            .split(",")
            .map((item) => item.trim())
            .filter(Boolean),
          notes: form.notes
            .split("\n")
            .map((item) => item.trim())
            .filter(Boolean),
        },
        form.id
      );
      setMessage(form.id ? "Lead updated." : "Lead created.");
      setMessageTone("success");
      setForm(createDefaultLeadForm(defaultLeadCountry));
      await load();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to save lead.");
      setMessageTone("error");
    }
  }

  async function handleImport(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formEl = event.currentTarget;
    const formData = new FormData(formEl);
    const file = formData.get("csv_file");
    if (!(file instanceof File)) {
      setMessage("Select a CSV file first.");
      setMessageTone("error");
      return;
    }
    if (!file.name.toLowerCase().endsWith(".csv")) {
      setMessage("Only CSV files are supported.");
      setMessageTone("error");
      return;
    }

    try {
      setImporting(true);
      setMessage("");
      const createdJob = await importLeadsFromFile(file);
      let pollCount = 0;
      let current = await getLeadImportJob(createdJob.job_id);
      setImportState(current);

      while (["queued", "processing"].includes(current.status) && pollCount < 120) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        current = await getLeadImportJob(createdJob.job_id);
        setImportState(current);
        pollCount += 1;
      }

      if (current.status === "completed") {
        setMessage(
          `Import completed: ${current.successful_rows} success, ${current.failed_rows} failed.`
        );
        setMessageTone("success");
        formEl?.reset();
        await load();
      } else if (current.status === "failed") {
        setMessage("Import failed. Review the error summary.");
        setMessageTone("error");
      } else {
        setMessage("Import is still processing. Keep this page open for progress.");
        setMessageTone("neutral");
      }
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Lead import failed.");
      setMessageTone("error");
    } finally {
      setImporting(false);
    }
  }

  const filtered = useMemo(
    () =>
      leads.filter((lead) => {
        const matchesSearch =
          !search ||
          [lead.full_name, lead.phone, lead.email ?? "", lead.company ?? ""]
            .join(" ")
            .toLowerCase()
            .includes(search.toLowerCase());
        const matchesStatus = !statusFilter || lead.status === statusFilter;
        return matchesSearch && matchesStatus;
      }),
    [leads, search, statusFilter]
  );
  const selectedLead = filtered.find((lead) => lead.id === selectedLeadId) ?? null;
  const availableAgentNames = useMemo(() => {
    const names = new Set(agents.map((a) => a.company_number));
    leads.forEach((lead) => {
      if (lead.owner_agent && lead.owner_agent !== "Unassigned") {
        names.add(lead.owner_agent);
      }
    });
    return Array.from(names);
  }, [agents, leads]);

  const leadFormRef = useRef<HTMLDivElement | null>(null);
  const isEditing = Boolean(form.id);

  async function updateLeadStatusInline(lead: Lead, status: LeadStatus) {
    try {
      await saveLead(
        {
          full_name: lead.full_name,
          phone: lead.phone,
          email: lead.email,
          company: lead.company,
          status,
          owner_agent: lead.owner_agent || "Unassigned",
          next_follow_up_at: lead.next_follow_up_at ?? null,
          tags: lead.tags,
          notes: lead.notes,
        },
        lead.id
      );
      setMessage("Lead status updated.");
      setMessageTone("success");
      await load();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to update lead status.");
      setMessageTone("error");
    }
  }

  const selectedList = lists.find((item) => item.id === selectedListId) ?? null;
  const visibleListLeads = useMemo(() => leads.slice(0, 150), [leads]);

  return (
    <AppShell requiredPermissions={["call.initiate"]}>
      {/* Tab Switcher */}
      <Paper
        variant="outlined"
        sx={{
          mb: 2.5,
          p: 0.5,
          display: "inline-flex",
          gap: 0.5,
          borderRadius: 2,
          bgcolor: "action.hover",
        }}
      >
        <MuiButton
          variant={activeTab === "leads" ? "contained" : "text"}
          size="small"
          onClick={() => setActiveTab("leads")}
          sx={{
            borderRadius: 1.5,
            px: 2.5,
            py: 0.75,
            fontWeight: 600,
            fontSize: "0.8125rem",
            textTransform: "none",
            ...(activeTab !== "leads" && {
              color: "text.secondary",
              "&:hover": { bgcolor: "action.selected" },
            }),
          }}
        >
          Leads
        </MuiButton>
        <MuiButton
          variant={activeTab === "lists" ? "contained" : "text"}
          size="small"
          onClick={() => setActiveTab("lists")}
          sx={{
            borderRadius: 1.5,
            px: 2.5,
            py: 0.75,
            fontWeight: 600,
            fontSize: "0.8125rem",
            textTransform: "none",
            ...(activeTab !== "lists" && {
              color: "text.secondary",
              "&:hover": { bgcolor: "action.selected" },
            }),
          }}
        >
          Lead Lists
        </MuiButton>
      </Paper>

      {activeTab === "leads" ? (
      <>
      <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "repeat(3, 1fr)" } }}>
        <Box ref={leadFormRef}>
          <SectionCard
            title={isEditing ? "Edit Lead" : "Create Lead"}
            subtitle={
              isEditing
                ? "You are editing an existing lead. Update fields and click Update Lead."
                : "Create a new lead profile using the form below."
            }
          >
          <Box component="form" sx={{ display: "grid", gap: 1.5 }} onSubmit={onSubmit}>
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography
                variant="caption"
                sx={{ mb: 1, display: "block", textTransform: "uppercase", fontWeight: 700, color: "text.secondary" }}
              >
                Identity
              </Typography>
              <Stack spacing={1.25}>
                <TextField
                  required
                  size="medium"
                  value={form.full_name}
                  onChange={(event) => setForm((prev) => ({ ...prev, full_name: event.target.value }))}
                  placeholder="Lead Name"
                />
                <TextField
                  required
                  select
                  size="medium"
                  value={form.phone_country}
                  onChange={(event) => setForm((prev) => ({ ...prev, phone_country: event.target.value }))}
                >
                  {countryDialOptions.map((option) => (
                    <MenuItem key={`${option.countryCode}-${option.dialCode}`} value={option.countryCode}>
                      {option.label} ({option.dialCode})
                    </MenuItem>
                  ))}
                </TextField>
                <TextField
                  required
                  size="medium"
                  value={form.phone_local}
                  onChange={(event) => {
                    const numericValue = event.target.value.replace(/\D/g, "");
                    const allowedLength = countryPhoneLength[form.phone_country];
                    const maxLength = allowedLength ? (Array.isArray(allowedLength) ? Math.max(...allowedLength) : allowedLength) : 15;
                    if (numericValue.length > maxLength) return;
                    setForm((prev) => ({ ...prev, phone_local: numericValue }));
                  }}
                  placeholder="Phone number"
                  helperText="Country code is selected separately. Only numbers are allowed."
                />
                <TextField
                  size="medium"
                  value={form.email}
                  onChange={(event) => setForm((prev) => ({ ...prev, email: event.target.value }))}
                  placeholder="Email"
                />
                <TextField
                  size="medium"
                  value={form.company}
                  onChange={(event) => setForm((prev) => ({ ...prev, company: event.target.value }))}
                  placeholder="Company"
                />
              </Stack>
            </Paper>
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography
                variant="caption"
                sx={{ mb: 1, display: "block", textTransform: "uppercase", fontWeight: 700, color: "text.secondary" }}
              >
                Ownership
              </Typography>
              <Stack spacing={1.25}>
                <TextField
                  select
                  size="medium"
                  value={form.status}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, status: event.target.value as LeadStatus }))
                  }
                >
                  {leadStatuses.map((status) => (
                    <MenuItem key={status} value={status}>
                      {status}
                    </MenuItem>
                  ))}
                </TextField>
                <TextField
                  size="medium"
                  value={form.owner_agent}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, owner_agent: event.target.value }))
                  }
                  placeholder="Assigned Agent"
                />
              </Stack>
            </Paper>
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Typography
                variant="caption"
                sx={{ mb: 1, display: "block", textTransform: "uppercase", fontWeight: 700, color: "text.secondary" }}
              >
                Follow-up
              </Typography>
              <Stack spacing={1.25}>
                <TextField
                  type="datetime-local"
                  size="medium"
                  value={form.next_follow_up_at}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, next_follow_up_at: event.target.value }))
                  }
                />
                <TextField
                  size="medium"
                  value={form.tags}
                  onChange={(event) => setForm((prev) => ({ ...prev, tags: event.target.value }))}
                  placeholder="Tags (comma separated)"
                />
                <TextField
                  multiline
                  minRows={3}
                  size="medium"
                  value={form.notes}
                  onChange={(event) => setForm((prev) => ({ ...prev, notes: event.target.value }))}
                  placeholder="Notes (one per line)"
                />
              </Stack>
            </Paper>
            <Stack direction="row" spacing={1}>
              <MuiButton type="submit" variant="contained" fullWidth>
                {form.id ? "Update Lead" : "Create Lead"}
              </MuiButton>
              <MuiButton
                type="button"
                variant="outlined"
                fullWidth
                onClick={() => setForm(createDefaultLeadForm(defaultLeadCountry))}
              >
                {form.id ? "Cancel Edit" : "Reset"}
              </MuiButton>
            </Stack>
          </Box>
          {message ? (
            <Box sx={{ mt: 1.5 }}>
              <ToastMessage
                tone={messageTone}
                title={messageTone === "error" ? "Lead Action Failed" : "Lead Update"}
                message={message}
              />
            </Box>
          ) : null}
          </SectionCard>
        </Box>

        <SectionCard title="Import Leads" subtitle="Upload CSV with columns: full_name,phone,email,company">
          <Box component="form" sx={{ display: "grid", gap: 1.25 }} onSubmit={handleImport}>
            <Box
              component="input"
              name="csv_file"
              type="file"
              accept=".csv,text/csv"
              sx={{
                width: "100%",
                border: 1,
                borderColor: "divider",
                borderRadius: 1,
                px: 1.5,
                py: 1,
                fontSize: 14,
              }}
            />
            <MuiButton type="submit" disabled={importing} variant="contained" fullWidth>
              {importing ? "Importing..." : "Upload and Import"}
            </MuiButton>
          </Box>
          {importState ? (
            <Paper
              variant="outlined"
              sx={{ mt: 1.5, p: 1.5, bgcolor: "action.hover", color: "text.secondary" }}
            >
              <Typography variant="caption" display="block">Status: {importState.status}</Typography>
              <Typography variant="caption" display="block">Progress: {importState.progress}%</Typography>
              <Typography variant="caption" display="block">
                Processed: {importState.processed_rows}/{importState.total_rows} rows
              </Typography>
              <Typography variant="caption" display="block">
                Success/Failed: {importState.successful_rows}/{importState.failed_rows}
              </Typography>
            </Paper>
          ) : null}
          {message ? (
            <Box sx={{ mt: 1.5 }}>
              <ToastMessage tone={messageTone} message={message} />
            </Box>
          ) : null}
        </SectionCard>

        <SectionCard title="Lead Filters" subtitle="Search and lifecycle status tracking">
          <Stack spacing={1.25}>
            <TextField
              size="medium"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search by name/phone/email/company"
            />
            <TextField
              select
              size="medium"
              value={statusFilter}
              onChange={(event) => setStatusFilter(event.target.value)}
            >
              <MenuItem value="">All statuses</MenuItem>
              {leadStatuses.map((status) => (
                <MenuItem key={status} value={status}>
                  {status}
                </MenuItem>
              ))}
            </TextField>
            <Typography variant="body2" color="text.secondary">Visible leads: {filtered.length}</Typography>
          </Stack>
        </SectionCard>
      </Box>

      <Box
        sx={{
          mt: 2,
          display: "grid",
          gap: 2,
          alignItems: "start",
          gridTemplateColumns: { xs: "1fr", xl: "1.65fr 1fr" },
        }}
      >
      <SectionCard title="Lead Table" subtitle="Assignment and follow-up visibility with inline status and owner updates.">
        {loading ? (
          <SkeletonLines rows={8} />
        ) : (
          <Paper variant="outlined" sx={{ overflowX: "auto" }}>
            <Table size="small" sx={{ width: "100%", minWidth: 980, tableLayout: "fixed" }}>
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell sx={{ width: "16%", py: 1.5 }}>Name</TableCell>
                  <TableCell sx={{ width: "12%", py: 1.5 }}>Phone</TableCell>
                  <TableCell sx={{ width: "16%", py: 1.5 }}>Status</TableCell>
                  <TableCell sx={{ width: "16%", py: 1.5 }}>Agent</TableCell>
                  <TableCell sx={{ width: "8%", py: 1.5 }}>Tags</TableCell>
                  <TableCell sx={{ width: "10%", py: 1.5 }}>Follow-Up</TableCell>
                  <TableCell sx={{ width: "12%", py: 1.5 }}>Notes</TableCell>
                  <TableCell sx={{ width: "10%", py: 1.5 }}>Action</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {filtered.map((lead) => (
                  <TableRow
                    key={lead.id}
                    hover
                    onClick={() => setSelectedLeadId(lead.id)}
                    sx={{
                      cursor: "pointer",
                      bgcolor: selectedLeadId === lead.id ? "action.selected" : "inherit",
                      "&:hover": {
                        bgcolor: "action.hover",
                      },
                    }}
                  >
                    <TableCell sx={{ verticalAlign: "middle", overflow: "hidden", py: 1 }}>
                      <Stack direction="row" spacing={1.25} alignItems="center">
                        <Box
                          sx={{
                            width: 32,
                            height: 32,
                            borderRadius: "50%",
                            bgcolor: "primary.light",
                            color: "primary.contrastText",
                            display: "grid",
                            placeItems: "center",
                            fontWeight: 700,
                            fontSize: "0.8125rem",
                            flexShrink: 0,
                          }}
                        >
                          {lead.full_name ? lead.full_name.trim().charAt(0).toUpperCase() : "?"}
                        </Box>
                        <Box sx={{ minWidth: 0 }}>
                          <Typography
                            variant="body2"
                            sx={{
                              fontWeight: 600,
                              color: "text.primary",
                              whiteSpace: "nowrap",
                              overflow: "hidden",
                              textOverflow: "ellipsis",
                            }}
                          >
                            {lead.full_name}
                          </Typography>
                          {lead.company ? (
                            <Typography
                              variant="caption"
                              color="text.secondary"
                              sx={{
                                display: "block",
                                whiteSpace: "nowrap",
                                overflow: "hidden",
                                textOverflow: "ellipsis",
                              }}
                            >
                              {lead.company}
                            </Typography>
                          ) : null}
                        </Box>
                      </Stack>
                    </TableCell>
                    <TableCell
                      sx={{
                        verticalAlign: "middle",
                        whiteSpace: "nowrap",
                        overflow: "hidden",
                        textOverflow: "ellipsis",
                        fontFamily: "monospace",
                        fontSize: "0.875rem",
                        letterSpacing: "0.2px",
                      }}
                    >
                      {lead.phone}
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "middle" }}>
                      <TextField
                        select
                        size="small"
                        value={lead.status}
                        onClick={(event) => event.stopPropagation()}
                        onChange={(event) =>
                          void updateLeadStatusInline(lead, event.target.value as LeadStatus)
                        }
                        fullWidth
                        SelectProps={{
                          renderValue: (value) => (
                            <StatusBadge label={value as string} />
                          ),
                          sx: {
                            minHeight: "auto",
                            "& .MuiSelect-select": {
                              py: "2px",
                              pl: "4px",
                              pr: "24px !important",
                              display: "flex",
                              alignItems: "center",
                              justifyContent: "flex-start",
                            },
                            "& .MuiOutlinedInput-notchedOutline": { border: "none" },
                            "&:hover .MuiOutlinedInput-notchedOutline": { border: "none" },
                            "&.Mui-focused .MuiOutlinedInput-notchedOutline": { border: "none" },
                          }
                        }}
                        sx={{
                          width: "fit-content",
                          bgcolor: "transparent",
                        }}
                      >
                        {leadStatuses.map((status) => (
                          <MenuItem key={status} value={status} sx={{ py: 0.75 }}>
                            <StatusBadge label={status} />
                          </MenuItem>
                        ))}
                      </TextField>
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "middle" }}>
                      <TextField
                        select
                        size="small"
                        value={lead.owner_agent || "Unassigned"}
                        onClick={(event) => event.stopPropagation()}
                        onChange={async (event) => {
                          const newOwner = event.target.value;
                          try {
                            await saveLead(
                              {
                                full_name: lead.full_name,
                                phone: lead.phone,
                                email: lead.email,
                                company: lead.company,
                                status: lead.status,
                                owner_agent: newOwner,
                                next_follow_up_at: lead.next_follow_up_at ?? null,
                                tags: lead.tags,
                                notes: lead.notes,
                              },
                              lead.id
                            );
                            setMessage(`Agent assigned: ${newOwner}`);
                            setMessageTone("success");
                            await load();
                          } catch (err) {
                            setMessage(err instanceof Error ? err.message : "Failed to update agent assignment.");
                            setMessageTone("error");
                          }
                        }}
                        fullWidth
                        SelectProps={{
                          sx: {
                            fontSize: "0.8125rem",
                            py: "2px",
                            px: "4px",
                            bgcolor: "action.hover",
                            borderRadius: "4px",
                            "& .MuiOutlinedInput-notchedOutline": { borderColor: "transparent" },
                            "&:hover .MuiOutlinedInput-notchedOutline": { borderColor: "rgba(0, 0, 0, 0.08)" },
                            "&.Mui-focused .MuiOutlinedInput-notchedOutline": { borderColor: "primary.main" },
                          }
                        }}
                      >
                        <MenuItem value="Unassigned">
                          <Typography variant="body2" sx={{ color: "text.secondary", fontStyle: "italic" }}>
                            Unassigned
                          </Typography>
                        </MenuItem>
                        {availableAgentNames.map((name) => (
                          <MenuItem key={name} value={name}>
                            <Typography variant="body2">{name}</Typography>
                          </MenuItem>
                        ))}
                      </TextField>
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "middle" }}>
                      {lead.tags.length > 0 ? (
                        <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                          {lead.tags.map((tag) => (
                            <Chip
                              key={tag}
                              label={tag}
                              size="small"
                              sx={{
                                fontSize: "0.7rem",
                                height: 18,
                                bgcolor: "action.selected",
                                color: "text.secondary",
                                fontWeight: 500,
                              }}
                            />
                          ))}
                        </Stack>
                      ) : (
                        <Typography variant="caption" color="text.secondary" sx={{ fontStyle: "italic" }}>
                          None
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "middle" }}>
                      {lead.next_follow_up_at ? (
                        <Typography variant="body2" sx={{ fontSize: "0.8125rem", whiteSpace: "nowrap" }}>
                          {new Date(lead.next_follow_up_at).toLocaleString([], {
                            month: "short",
                            day: "2-digit",
                            year: "numeric",
                            hour: "2-digit",
                            minute: "2-digit",
                          })}
                        </Typography>
                      ) : (
                        <Typography variant="caption" color="text.secondary" sx={{ fontStyle: "italic" }}>
                          None
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "middle" }}>
                      {lead.notes.length > 0 ? (
                        <Typography
                          variant="caption"
                          color="text.secondary"
                          sx={{
                            display: "-webkit-box",
                            WebkitLineClamp: 2,
                            WebkitBoxOrient: "vertical",
                            overflow: "hidden",
                            textOverflow: "ellipsis",
                            whiteSpace: "normal",
                            lineHeight: 1.3,
                          }}
                          title={lead.notes.join("\n")}
                        >
                          {lead.notes[lead.notes.length - 1]}
                        </Typography>
                      ) : (
                        <Typography variant="caption" color="text.secondary" sx={{ fontStyle: "italic" }}>
                          No notes
                        </Typography>
                      )}
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "middle" }}>
                      <Stack direction="row" spacing={0.75} justifyContent="flex-start">
                        <MuiButton
                          type="button"
                          size="small"
                          variant="outlined"
                          onClick={(event) => {
                            event.stopPropagation();
                            const parsedPhone = parsePhoneForForm(lead.phone, defaultLeadCountry);
                            setForm({
                              id: lead.id,
                              full_name: lead.full_name,
                              phone_country: parsedPhone.phone_country,
                              phone_local: parsedPhone.phone_local,
                              email: lead.email ?? "",
                              company: lead.company ?? "",
                              status: lead.status,
                              owner_agent: lead.owner_agent,
                              next_follow_up_at: lead.next_follow_up_at
                                ? new Date(lead.next_follow_up_at).toISOString().slice(0, 16)
                                : "",
                              tags: lead.tags.join(", "),
                              notes: lead.notes.join("\n"),
                            });

                            window.requestAnimationFrame(() => {
                              leadFormRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
                            });
                          }}
                          sx={{ minWidth: 42, px: 1, py: 0.25, fontSize: "0.725rem" }}
                        >
                          Edit
                        </MuiButton>
                        <MuiButton
                          type="button"
                          size="small"
                          variant="outlined"
                          color="error"
                          onClick={async (event) => {
                            event.stopPropagation();
                            if (window.confirm(`Are you sure you want to delete lead "${lead.full_name}"?`)) {
                              try {
                                await deleteLead(lead.id);
                                setMessage("Lead deleted successfully.");
                                setMessageTone("success");
                                if (selectedLeadId === lead.id) {
                                  setSelectedLeadId("");
                                }
                                await load();
                              } catch (err) {
                                setMessage(err instanceof Error ? err.message : "Failed to delete lead.");
                                setMessageTone("error");
                              }
                            }
                          }}
                          sx={{ minWidth: 42, px: 1, py: 0.25, fontSize: "0.725rem" }}
                        >
                          Delete
                        </MuiButton>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
                {filtered.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8}>
                      <Typography variant="body2" color="text.secondary">
                        No leads found for current filters. Clear filters or create a new lead to continue.
                      </Typography>
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableBody>
            </Table>
          </Paper>
        )}
      </SectionCard>
      <Paper variant="outlined" sx={{ p: 2, position: { xl: "sticky" }, top: { xl: 80 } }}>
        <Typography variant="subtitle2">Lead Detail Drawer</Typography>
        {selectedLead ? (
          <Stack spacing={1} sx={{ mt: 1.5 }}>
            <Typography variant="body2"><Typography component="span" variant="body2" color="text.secondary">Name:</Typography> {selectedLead.full_name}</Typography>
            <Typography variant="body2"><Typography component="span" variant="body2" color="text.secondary">Phone:</Typography> {selectedLead.phone}</Typography>
            <Typography variant="body2"><Typography component="span" variant="body2" color="text.secondary">Email:</Typography> {selectedLead.email || "N/A"}</Typography>
            <Typography variant="body2"><Typography component="span" variant="body2" color="text.secondary">Company:</Typography> {selectedLead.company || "N/A"}</Typography>
            <Typography variant="body2"><Typography component="span" variant="body2" color="text.secondary">Owner:</Typography> {selectedLead.owner_agent || "Unassigned"}</Typography>
            <Typography variant="body2">
              <Typography component="span" variant="body2" color="text.secondary">Follow-up:</Typography>{" "}
              {selectedLead.next_follow_up_at ? new Date(selectedLead.next_follow_up_at).toLocaleString() : "None"}
            </Typography>
            <Typography variant="body2"><Typography component="span" variant="body2" color="text.secondary">Tags:</Typography> {selectedLead.tags.join(", ") || "None"}</Typography>
            <Box sx={{ mt: 0.5 }}>
              <Typography variant="caption" sx={{ mb: 0.5, display: "block", textTransform: "uppercase", color: "text.secondary", fontWeight: 700 }}>
                Quick Status Update
              </Typography>
              <TextField
                select
                size="medium"
                fullWidth
                value={selectedLead.status}
                onChange={(event) =>
                  void updateLeadStatusInline(selectedLead, event.target.value as LeadStatus)
                }
              >
                {leadStatuses.map((status) => (
                  <MenuItem key={status} value={status}>
                    {status}
                  </MenuItem>
                ))}
              </TextField>
            </Box>
            <Paper
              variant="outlined"
              sx={{ p: 1.5, bgcolor: "action.hover", color: "text.secondary", whiteSpace: "pre-line" }}
            >
              <Typography variant="caption">{selectedLead.notes.join("\n") || "No notes"}</Typography>
            </Paper>
          </Stack>
        ) : (
          <EmptyPanel
            title="No lead selected"
            description="Select a row from the lead table to open details and quickly update lifecycle status."
          />
        )}
      </Paper>
      </Box>
      </>
      ) : (
        /* ===== Lead Lists Tab ===== */
        <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "1fr 1.7fr" } }}>
          <SectionCard title="Lead Lists" subtitle="Create and manage list containers for campaign assignment.">
            <Box component="form" onSubmit={onCreateList} sx={{ display: "grid", gap: 1.25 }}>
              <TextField
                required
                size="medium"
                value={listName}
                onChange={(event) => setListName(event.target.value)}
                placeholder="List name"
              />
              <TextField
                size="medium"
                value={listDescription}
                onChange={(event) => setListDescription(event.target.value)}
                placeholder="Description"
              />
              <MuiButton type="submit" variant="contained">Create List</MuiButton>
            </Box>

            <TextField
              select
              size="medium"
              value={selectedListId}
              onChange={(event) => setSelectedListId(event.target.value)}
              sx={{ mt: 1.5, width: "100%" }}
            >
              <MenuItem value="">Select list</MenuItem>
              {lists.map((item) => (
                <MenuItem key={item.id} value={item.id}>
                  {item.name} ({item.leads_count ?? 0})
                </MenuItem>
              ))}
            </TextField>

            {selectedList ? (
              <Paper variant="outlined" sx={{ mt: 1.5, p: 1.5, bgcolor: "action.hover" }}>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>{selectedList.name}</Typography>
                <Typography variant="caption" color="text.secondary">{selectedList.description || "No description"}</Typography>
              </Paper>
            ) : null}

            {message ? (
              <Box sx={{ mt: 1.5 }}>
                <ToastMessage tone={messageTone} message={message} />
              </Box>
            ) : null}
          </SectionCard>

          <SectionCard
            title="Manage List Leads"
            subtitle={listMode === "remove" ? "Remove leads from selected list." : "Attach existing leads into selected lead list."}
          >
            {listsLoading ? (
              <SkeletonLines rows={6} />
            ) : !selectedListId ? (
              <EmptyPanel title="Select a list first" description="Choose a lead list to add or remove leads." />
            ) : (
              <>
                <Stack direction="row" spacing={1} sx={{ mb: 1.5 }}>
                  <MuiButton
                    variant={listMode === "add" ? "contained" : "outlined"}
                    onClick={() => {
                      setSelectedLeadIdsForList([]);
                      setListMode("add");
                    }}
                  >
                    Add Leads
                  </MuiButton>
                  <MuiButton
                    variant={listMode === "remove" ? "contained" : "outlined"}
                    onClick={() => {
                      setSelectedLeadIdsForList([]);
                      setListMode("remove");
                    }}
                  >
                    Remove Leads
                  </MuiButton>
                </Stack>

                {visibleListLeads.length === 0 ? (
                  <EmptyPanel
                    title={listMode === "remove" ? "No leads in this list" : "No leads available"}
                    description={listMode === "remove" ? "This lead list has no leads attached yet." : "Create or import leads first to populate list membership."}
                  />
                ) : (
                  <>
                    <Paper variant="outlined" sx={{ overflowX: "auto" }}>
                      <Table size="medium" sx={{ minWidth: 620 }}>
                        <TableHead>
                          <TableRow sx={{ bgcolor: "action.hover" }}>
                            <TableCell sx={{ width: 40 }} />
                            <TableCell>Name</TableCell>
                            <TableCell>Phone</TableCell>
                            <TableCell>Status</TableCell>
                            <TableCell>Owner</TableCell>
                          </TableRow>
                        </TableHead>
                        <TableBody>
                          {visibleListLeads.map((lead) => {
                            const selected = selectedLeadIdsForList.includes(lead.id);
                            return (
                              <TableRow key={lead.id} hover onClick={() => {
                                setSelectedLeadIdsForList((prev) => selected ? prev.filter((id) => id !== lead.id) : [...prev, lead.id]);
                              }} sx={{ cursor: "pointer", bgcolor: selected ? "action.selected" : "inherit" }}>
                                <TableCell>
                                  <Box
                                    sx={{
                                      width: 18,
                                      height: 18,
                                      borderRadius: 0.5,
                                      border: 2,
                                      borderColor: selected ? "primary.main" : "divider",
                                      bgcolor: selected ? "primary.main" : "transparent",
                                      display: "grid",
                                      placeItems: "center",
                                      color: "#fff",
                                      fontSize: "0.7rem",
                                      fontWeight: 700,
                                    }}
                                  >
                                    {selected ? "✓" : ""}
                                  </Box>
                                </TableCell>
                                <TableCell>{lead.full_name}</TableCell>
                                <TableCell sx={{ fontFamily: "monospace", fontSize: "0.85rem" }}>{lead.phone}</TableCell>
                                <TableCell><StatusBadge label={lead.status} /></TableCell>
                                <TableCell>{lead.owner_agent || "Unassigned"}</TableCell>
                              </TableRow>
                            );
                          })}
                        </TableBody>
                      </Table>
                    </Paper>
                    <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
                      {listMode === "remove" ? (
                        <MuiButton
                          variant="contained"
                          color="error"
                          onClick={() => void onDetachLeads()}
                          disabled={!selectedListId || selectedLeadIdsForList.length === 0}
                        >
                          Remove {selectedLeadIdsForList.length} Leads
                        </MuiButton>
                      ) : (
                        <MuiButton
                          variant="contained"
                          onClick={() => void onAttachLeads()}
                          disabled={!selectedListId || selectedLeadIdsForList.length === 0}
                        >
                          Attach {selectedLeadIdsForList.length} Leads
                        </MuiButton>
                      )}
                      <MuiButton variant="outlined" onClick={() => setSelectedLeadIdsForList([])}>Clear</MuiButton>
                    </Stack>
                  </>
                )}
              </>
            )}
          </SectionCard>
        </Box>
      )}
    </AppShell>
  );
}
