"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
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
} from "@/ui";
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, SkeletonLines, ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import {
  getLeadImportJob,
  importLeadsFromFile,
  listLeads,
  saveLead,
} from "@/lib/product-api";
import { getTenantContext } from "@/lib/tenant-context";
import type { Lead, LeadImportStatus, LeadStatus } from "@/types/product";

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

export default function LeadsPage() {
  const [leads, setLeads] = useState<Lead[]>([]);
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
  const [ownerDrafts, setOwnerDrafts] = useState<Record<string, string>>({});

  async function load() {
    setLoading(true);
    try {
      const data = await listLeads();
      setLeads(data);
      setOwnerDrafts(
        data.reduce<Record<string, string>>((acc, lead) => {
          acc[lead.id] = lead.owner_agent ?? "";
          return acc;
        }, {})
      );
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load leads.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }

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

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    setMessageTone("neutral");
    const localDigits = form.phone_local.replace(/\D+/g, "");
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
    const formData = new FormData(event.currentTarget);
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
        event.currentTarget.reset();
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

  async function updateLeadOwnerInline(lead: Lead) {
    const owner = ownerDrafts[lead.id] || "Unassigned";
    try {
      await saveLead(
        {
          full_name: lead.full_name,
          phone: lead.phone,
          email: lead.email,
          company: lead.company,
          status: lead.status,
          owner_agent: owner,
          next_follow_up_at: lead.next_follow_up_at ?? null,
          tags: lead.tags,
          notes: lead.notes,
        },
        lead.id
      );
      setMessage("Lead owner updated.");
      setMessageTone("success");
      await load();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to update lead owner.");
      setMessageTone("error");
    }
  }

  return (
    <AppShell requiredPermissions={["call.initiate"]}>
      <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "repeat(3, 1fr)" } }}>
        <SectionCard
          title="Lead Management"
          subtitle="Create and update lead profile data with structured sections."
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
                  onChange={(event) => setForm((prev) => ({ ...prev, phone_local: event.target.value }))}
                  placeholder="Phone number"
                  helperText="Country code is selected separately."
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
                Reset
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
          <Paper variant="outlined" sx={{ overflowX: "hidden" }}>
            <Table size="medium" sx={{ width: "100%", tableLayout: "fixed" }}>
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell sx={{ width: "16%" }}>Name</TableCell>
                  <TableCell sx={{ width: "12%" }}>Phone</TableCell>
                  <TableCell sx={{ width: "16%" }}>Status</TableCell>
                  <TableCell sx={{ width: "16%" }}>Agent</TableCell>
                  <TableCell sx={{ width: "8%" }}>Tags</TableCell>
                  <TableCell sx={{ width: "10%" }}>Follow-Up</TableCell>
                  <TableCell sx={{ width: "12%" }}>Notes</TableCell>
                  <TableCell sx={{ width: "10%" }}>Action</TableCell>
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
                      verticalAlign: "top",
                      bgcolor: selectedLeadId === lead.id ? "action.selected" : "inherit",
                    }}
                  >
                    <TableCell sx={{ verticalAlign: "top", overflow: "hidden" }}>
                      <Typography variant="body2" sx={{ whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                        {lead.full_name}
                      </Typography>
                      <Typography variant="caption" color="text.secondary" sx={{ display: "block", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                        {lead.company || "No company"}
                      </Typography>
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "top", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                      {lead.phone}
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "top" }}>
                      <Stack spacing={0.75}>
                        <StatusBadge label={lead.status} />
                        <TextField
                          select
                          size="medium"
                          value={lead.status}
                          onClick={(event) => event.stopPropagation()}
                          onChange={(event) =>
                            void updateLeadStatusInline(lead, event.target.value as LeadStatus)
                          }
                          fullWidth
                        >
                          {leadStatuses.map((status) => (
                            <MenuItem key={status} value={status}>
                              {status}
                            </MenuItem>
                          ))}
                        </TextField>
                      </Stack>
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "top" }}>
                      <Stack spacing={0.75} alignItems="stretch">
                        <TextField
                          size="medium"
                          value={ownerDrafts[lead.id] ?? ""}
                          onClick={(event) => event.stopPropagation()}
                          onChange={(event) =>
                            setOwnerDrafts((prev) => ({ ...prev, [lead.id]: event.target.value }))
                          }
                          placeholder="Owner"
                          fullWidth
                        />
                        <MuiButton
                          type="button"
                          size="medium"
                          variant="outlined"
                          onClick={(event) => {
                            event.stopPropagation();
                            void updateLeadOwnerInline(lead);
                          }}
                          fullWidth
                        >
                          Save
                        </MuiButton>
                      </Stack>
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "top", whiteSpace: "normal", overflowWrap: "anywhere" }}>
                      {lead.tags.join(", ") || "None"}
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "top" }}>
                      {lead.next_follow_up_at
                        ? new Date(lead.next_follow_up_at).toLocaleString()
                        : "None"}
                    </TableCell>
                    <TableCell sx={{ whiteSpace: "pre-line", overflowWrap: "anywhere", verticalAlign: "top" }}>
                      <Typography variant="caption">{lead.notes.join("\n") || "No notes"}</Typography>
                    </TableCell>
                    <TableCell sx={{ verticalAlign: "top" }}>
                      <MuiButton
                        type="button"
                        size="small"
                        variant="outlined"
                        onClick={() => {
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
                        }}
                        fullWidth
                      >
                        Edit
                      </MuiButton>
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
    </AppShell>
  );
}
