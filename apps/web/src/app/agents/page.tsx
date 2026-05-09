"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, TextField, Typography } from "@/ui";
import { AppShell, EmptyState, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { CreateGuard } from "@/components/plans/CreateGuard";
import { assignAgentNumber, createAgent, deleteAgent, listAgents, listProviderAccounts, listValidatedProviderNumbers, updateAgent } from "@/lib/product-api";
import type { AgentEntity, ProviderNumberOption } from "@/types/product";

export default function AgentsPage() {
  const [agents, setAgents] = useState<AgentEntity[]>([]);
  const [numbers, setNumbers] = useState<ProviderNumberOption[]>([]);
  const [providers, setProviders] = useState<Array<{ id: string; display_name: string; provider_type: string; status: string }>>([]);
  const [companyNumber, setCompanyNumber] = useState("");
  const [destinationNumber, setDestinationNumber] = useState("");
  const [newStatus, setNewStatus] = useState<"active" | "inactive">("active");
  const [destinationByAgent, setDestinationByAgent] = useState<Record<string, string>>({});
  const [selectedNumberByAgent, setSelectedNumberByAgent] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [statusFilter, setStatusFilter] = useState<"all" | "active" | "inactive">("all");
  const [activeProviderId, setActiveProviderId] = useState("");

  async function loadData(showSpinner = true) {
    if (showSpinner) {
      setLoading(true);
    }
    try {
      const [agentData, numberData, providerData] = await Promise.all([
        listAgents(),
        listValidatedProviderNumbers(),
        listProviderAccounts(),
      ]);
      const twilioProviders = providerData.filter((provider) => provider.provider_type === "twilio" && provider.status === "active");
      setAgents(agentData);
      setNumbers(numberData);
      setProviders(twilioProviders);
      const fallbackProviderId = twilioProviders[0]?.id ?? "";
      const nextDestination: Record<string, string> = {};
      const nextSelected: Record<string, string> = {};
      agentData.forEach((agent) => {
        nextDestination[agent.id] = agent.destination_number ?? "";
        if (agent.default_number?.id) {
          nextSelected[agent.id] = agent.default_number.id;
        }
      });
      setDestinationByAgent(nextDestination);
      setSelectedNumberByAgent(nextSelected);
      setActiveProviderId((current) => current || fallbackProviderId);
      setMessage("");
    } catch (error) {
      setAgents([]);
      setNumbers([]);
      setProviders([]);
      setSelectedNumberByAgent({});
      setMessage(error instanceof Error ? error.message : "Unable to load agents.");
    } finally {
      setLoading(false);
    }
  }

  async function onCreateAgent(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setMessage("");
    try {
      await createAgent({ company_number: companyNumber, status: newStatus, destination_number: destinationNumber || null });
      setCompanyNumber("");
      setDestinationNumber("");
      setNewStatus("active");
      setMessage("Agent created.");
      await loadData(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to create agent.");
    } finally {
      setSaving(false);
    }
  }

  async function onUpdateAgent(agentId: string, status: "active" | "inactive") {
    setSaving(true);
    setMessage("");
    try {
      await updateAgent(agentId, { status });
      setMessage("Agent updated.");
      await loadData(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to update agent.");
    } finally {
      setSaving(false);
    }
  }

  async function onAssignNumber(agentId: string) {
    if (!activeProviderId) {
      setMessage("Select a provider first.");
      return;
    }
    const numberId = selectedNumberByAgent[agentId];
    if (!numberId) {
      setMessage("Select a validated number first.");
      return;
    }
    setSaving(true);
    setMessage("");
    try {
      await assignAgentNumber({
        agent_id: agentId,
        provider_account_id: activeProviderId,
        provider_phone_number_id: numberId,
        status: "active",
      });
      setMessage("Number assigned.");
      await loadData(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to assign number.");
    } finally {
      setSaving(false);
    }
  }

  async function onSaveDestination(agentId: string) {
    setSaving(true);
    setMessage("");
    try {
      const next = destinationByAgent[agentId] ?? "";
      await updateAgent(agentId, { destination_number: next ? next : null });
      setMessage("Destination updated.");
      await loadData(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to update destination.");
    } finally {
      setSaving(false);
    }
  }

  async function onDeleteAgent(agent: AgentEntity) {
    const confirm = window.prompt(`Type DELETE to remove agent identity ${agent.company_number}`);
    if (confirm !== "DELETE") {
      setMessage("Delete cancelled.");
      return;
    }
    setSaving(true);
    setMessage("");
    try {
      await deleteAgent(agent.id);
      setMessage("Agent deleted.");
      await loadData(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to delete agent.");
    } finally {
      setSaving(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  const totals = useMemo(() => {
    const active = agents.filter((item) => item.status === "active").length;
    const inactive = agents.filter((item) => item.status === "inactive").length;
    const assigned = agents.filter((item) => item.default_number?.id).length;
    return { active, inactive, assigned };
  }, [agents]);

  const filteredAgents = useMemo(() => {
    if (statusFilter === "all") return agents;
    return agents.filter((agent) => agent.status === statusFilter);
  }, [agents, statusFilter]);

  const providerNumbers = useMemo(() => {
    if (!activeProviderId) return [];
    return numbers.filter((number) => number.provider_account_id === activeProviderId);
  }, [numbers, activeProviderId]);

  function formatAgentCode(identity: string): string {
    const compact = identity.trim();
    if (!compact) return "-";
    return compact.replace(/^Agent-/i, "AG-");
  }

  return (
    <AppShell requiredPermissions={["agent.view"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}>
          <Paper variant="outlined" sx={{ p: 1.5 }}>
            <Typography variant="caption">Active Agents</Typography>
            <Typography variant="h6">{totals.active}</Typography>
          </Paper>
          <Paper variant="outlined" sx={{ p: 1.5 }}>
            <Typography variant="caption">Inactive Agents</Typography>
            <Typography variant="h6">{totals.inactive}</Typography>
          </Paper>
          <Paper variant="outlined" sx={{ p: 1.5 }}>
            <Typography variant="caption">With Number Assigned</Typography>
            <Typography variant="h6">{totals.assigned}</Typography>
          </Paper>
        </Box>

        <SectionCard title="Create Agent" subtitle="Agents are identity records only, with no personal profile data.">
          <Box
            component="form"
            onSubmit={onCreateAgent}
            sx={{ display: "grid", gap: 1.25, gridTemplateColumns: { xs: "1fr", md: "repeat(4, 1fr)" } }}
          >
            <TextField
              size="medium"
              value={companyNumber}
              onChange={(event) => setCompanyNumber(event.target.value.replace(/[^A-Za-z0-9._-]/g, ""))}
              placeholder="Agent Identity (e.g. sales-team-1)"
              inputProps={{ maxLength: 64 }}
              required
            />
            <TextField
              size="medium"
              value={destinationNumber}
              onChange={(event) => setDestinationNumber(event.target.value)}
              placeholder="Destination Phone (e.g. +9198...)"
              inputProps={{ maxLength: 16 }}
            />
            <TextField select size="medium" value={newStatus} onChange={(event) => setNewStatus(event.target.value as "active" | "inactive")}>
              <MenuItem value="active">Active</MenuItem>
              <MenuItem value="inactive">Inactive</MenuItem>
            </TextField>
            <CreateGuard featureKey="max_agents" fallbackLabel="Create Agent">
              <MuiButton type="submit" variant="contained" disabled={saving}>
                {saving ? "Saving..." : "Create Agent"}
              </MuiButton>
            </CreateGuard>
          </Box>
        </SectionCard>

        <SectionCard title="Agent Roster" subtitle="Manage agent identities and assign validated caller IDs.">
          {loading ? (
            <LoadingState label="Loading agents..." />
          ) : agents.length === 0 ? (
            <EmptyState title="No agents yet" description="Create an agent identity to begin assignment and campaign mapping." />
          ) : (
            <Paper variant="outlined" sx={{ p: 2 }}>
              <Box sx={{ display: "flex", flexWrap: "wrap", alignItems: "center", justifyContent: "space-between", gap: 1.5, mb: 2 }}>
                <Box sx={{ display: "flex", alignItems: "center", gap: 1 }}>
                  <Typography variant="h6">Agent List</Typography>
                </Box>
                <Box sx={{ display: "flex", flexWrap: "wrap", gap: 1, alignItems: "center" }}>
                  <TextField
                    select
                    size="small"
                    value={statusFilter}
                    onChange={(event) => setStatusFilter(event.target.value as "all" | "active" | "inactive")}
                    sx={{ minWidth: 160 }}
                  >
                    <MenuItem value="all">All Status</MenuItem>
                    <MenuItem value="active">Active</MenuItem>
                    <MenuItem value="inactive">Inactive</MenuItem>
                  </TextField>
                  <TextField
                    select
                    size="small"
                    value={activeProviderId}
                    onChange={(event) => setActiveProviderId(event.target.value)}
                    sx={{ minWidth: 220 }}
                    disabled={providers.length === 0}
                  >
                    <MenuItem value="">{providers.length === 0 ? "No active providers" : "Select Provider"}</MenuItem>
                    {providers.map((provider) => (
                      <MenuItem key={provider.id} value={provider.id}>
                        {provider.display_name}
                      </MenuItem>
                    ))}
                  </TextField>
                </Box>
              </Box>

              <Box sx={{ display: "grid", gap: 1.5 }}>
                {filteredAgents.map((row) => {
                  const selected = selectedNumberByAgent[row.id] ?? "";
                  const selectedValue = providerNumbers.some((n) => n.id === selected) ? selected : "";
                  return (
                    <Paper key={row.id} variant="outlined" sx={{ p: 1.5, borderRadius: 2 }}>
                      <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", lg: "1.4fr 1fr 1fr 1.2fr 1.6fr 1.2fr" }, alignItems: "start" }}>
                        <Box sx={{ display: "flex", gap: 1.25, alignItems: "center" }}>
                          <Box
                            sx={{
                              width: 44,
                              height: 44,
                              borderRadius: "50%",
                              bgcolor: "action.hover",
                              display: "grid",
                              placeItems: "center",
                              fontWeight: 700,
                              color: "text.secondary",
                              flex: "0 0 auto",
                            }}
                          >
                            {String(row.company_number ?? "?").slice(0, 1).toUpperCase()}
                          </Box>
                          <Box sx={{ minWidth: 0 }}>
                            <Typography variant="subtitle2" sx={{ fontWeight: 700, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                              {row.company_number}
                            </Typography>
                            <Typography variant="caption" color="text.secondary" sx={{ display: "block" }}>
                              ID: {formatAgentCode(row.company_number)}
                            </Typography>
                          </Box>
                        </Box>

                        <Box>
                          <Typography variant="caption" color="text.secondary">Status</Typography>
                          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 0.75, flexWrap: "wrap" }}>
                            <StatusBadge label={row.status} />
                            <MuiButton
                              size="medium"
                              variant="outlined"
                              disabled={saving}
                              onClick={() => void onUpdateAgent(row.id, row.status === "active" ? "inactive" : "active")}
                            >
                              {row.status === "active" ? "Disable" : "Enable"}
                            </MuiButton>
                          </Stack>
                        </Box>

                        <Box>
                          <Typography variant="caption" color="text.secondary">Session</Typography>
                          <Typography variant="body2" sx={{ mt: 0.75, fontWeight: 600 }}>
                            {row.session?.status ?? "-"}
                          </Typography>
                        </Box>

                        <Box>
                          <Typography variant="caption" color="text.secondary">Assigned Number</Typography>
                          <Box sx={{ mt: 0.75 }}>
                            <Typography variant="body2" sx={{ fontWeight: 700 }}>
                              {row.default_number?.phone_number ?? "-"}
                            </Typography>
                            {row.default_number?.phone_number ? (
                              <Typography variant="caption" color="text.secondary" sx={{ display: "block" }}>
                                Validated
                              </Typography>
                            ) : null}
                          </Box>
                        </Box>

                        <Box>
                          <Typography variant="caption" color="text.secondary">Destination Number</Typography>
                          <Box sx={{ mt: 0.75, display: "grid", gap: 1 }}>
                            <TextField
                              size="small"
                              value={destinationByAgent[row.id] ?? ""}
                              onChange={(event) =>
                                setDestinationByAgent((prev) => ({
                                  ...prev,
                                  [row.id]: event.target.value,
                                }))
                              }
                              placeholder="Enter destination"
                              inputProps={{ maxLength: 16 }}
                              fullWidth
                            />
                            <MuiButton size="medium" variant="outlined" disabled={saving} onClick={() => void onSaveDestination(row.id)} fullWidth>
                              Save
                            </MuiButton>
                          </Box>
                        </Box>

                        <Box>
                          <Typography variant="caption" color="text.secondary">Actions</Typography>
                          <Box sx={{ mt: 0.75, display: "grid", gap: 1 }}>
                            <TextField
                              select
                              size="small"
                              value={selectedValue}
                              onChange={(event) =>
                                setSelectedNumberByAgent((prev) => ({
                                  ...prev,
                                  [row.id]: event.target.value,
                                }))
                              }
                              disabled={!activeProviderId}
                              fullWidth
                            >
                              <MenuItem value="">
                                {activeProviderId && providerNumbers.length === 0
                                  ? "No validated numbers (sync in Providers)"
                                  : "Select Number"}
                              </MenuItem>
                              {providerNumbers.map((number) => (
                                <MenuItem key={number.id} value={number.id}>
                                  {number.phone_number}
                                </MenuItem>
                              ))}
                            </TextField>
                            <MuiButton size="medium" variant="contained" disabled={saving} onClick={() => void onAssignNumber(row.id)} fullWidth>
                              Assign Number
                            </MuiButton>
                            <MuiButton size="medium" variant="outlined" color="error" disabled={saving} onClick={() => void onDeleteAgent(row)} fullWidth>
                              Delete
                            </MuiButton>
                          </Box>
                        </Box>
                      </Box>
                    </Paper>
                  );
                })}
              </Box>
            </Paper>
          )}
        </SectionCard>
        <Typography variant="caption" color="text.secondary">
          Provider selection is required before number assignment. For now, only active Twilio providers are shown.
        </Typography>
        {message ? (
          <Typography variant="body2" color="text.secondary">
            {message}
          </Typography>
        ) : null}
      </Box>
    </AppShell>
  );
}
