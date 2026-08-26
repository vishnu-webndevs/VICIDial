"use client";

import { useEffect, useState, useMemo } from "react";
import { Avatar, Box, MenuItem, Modal, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import Link from "next/link";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

type PlanItem = {
  id: string;
  name: string;
  slug: string;
  price_monthly?: number;
  price_yearly?: number;
  is_paid?: boolean;
  plan_type?: string;
};

type SubscriptionItem = {
  billing_cycle?: string;
  started_at?: string | null;
  expires_at?: string | null;
  is_expired?: boolean;
  status?: string;
};

type CompanyItem = {
  id: string;
  name: string;
  status: string;
  plan: PlanItem | null;
  subscription?: SubscriptionItem | null;
  usage: Record<string, number>;
};

export default function SuperAdminCompaniesPage() {
  const [companies, setCompanies] = useState<CompanyItem[]>([]);
  const [plans, setPlans] = useState<PlanItem[]>([]);
  const [search, setSearch] = useState("");
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");

  // Expiry Modal state
  const [expiryModalCompany, setExpiryModalCompany] = useState<CompanyItem | null>(null);
  const [newExpiryDate, setNewExpiryDate] = useState<string>("");
  const [savingExpiry, setSavingExpiry] = useState(false);

  async function loadData() {
    try {
      const { token, tenantId } = getTenantContext();
      const [companiesRes, plansRes, currentTenantRes] = await Promise.all([
        apiRequest<{ data: CompanyItem[] }>("/super-admin/companies", { token, tenantId }).catch(() => ({ data: [] })),
        apiRequest<{ data: Array<{ id: string; name: string; slug: string; is_active: boolean }> }>("/super-admin/plans", {
          token,
          tenantId,
        }).catch(() => ({ data: [] })),
        apiRequest<{ data: { id: string; name: string; status?: string } }>("/tenant", { token, tenantId }).catch(() => ({ data: null })),
      ]);

      let list = companiesRes.data ?? [];
      if (list.length === 0 && currentTenantRes.data) {
        list = [
          {
            id: currentTenantRes.data.id,
            name: currentTenantRes.data.name,
            status: currentTenantRes.data.status ?? "active",
            plan: null,
            usage: {},
          },
        ];
      }

      setCompanies(list);
      setPlans((plansRes.data ?? []).filter((plan) => plan.is_active).map((plan) => ({
        id: plan.id,
        name: plan.name,
        slug: plan.slug,
      })));
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load companies.");
      setMessageTone("error");
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  async function onChangePlan(companyId: string, planId: string) {
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/super-admin/companies/${companyId}/plan`, {
        method: "PUT",
        token,
        tenantId,
        body: { plan_id: planId, billing_cycle: "monthly" },
      });
      setMessage("Company plan updated successfully.");
      setMessageTone("success");
      await loadData();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to change plan.");
      setMessageTone("error");
    }
  }

  function applyDurationPreset(days: number) {
    const target = new Date();
    target.setDate(target.getDate() + days);
    setNewExpiryDate(target.toISOString().split("T")[0]);
  }

  function openExpiryModal(company: CompanyItem) {
    setExpiryModalCompany(company);
    if (company.subscription?.expires_at) {
      setNewExpiryDate(new Date(company.subscription.expires_at).toISOString().split("T")[0]);
    } else {
      applyDurationPreset(28);
    }
  }

  async function saveExpiryModal() {
    if (!expiryModalCompany) return;
    setSavingExpiry(true);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/super-admin/companies/${expiryModalCompany.id}/expiry`, {
        method: "PUT",
        token,
        tenantId,
        body: { expires_at: newExpiryDate || null },
      });
      setMessage("Subscription expiration date updated successfully.");
      setMessageTone("success");
      setExpiryModalCompany(null);
      await loadData();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to update expiration date.");
      setMessageTone("error");
    } finally {
      setSavingExpiry(false);
    }
  }

  const filteredCompanies = useMemo(() => {
    if (!search.trim()) return companies;
    const term = search.toLowerCase();
    return companies.filter((c) => (c.name ?? "").toLowerCase().includes(term) || (c.status ?? "").toLowerCase().includes(term));
  }, [companies, search]);

  return (
    <AppShell requiredRoles={["platform_super_admin", "super_admin"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2.5 }}>
        {/* Header */}
        <Box sx={{ display: "flex", flexDirection: { xs: "column", sm: "row" }, justifyContent: "space-between", alignItems: { xs: "stretch", sm: "center" }, gap: 1.5 }}>
          <Box>
            <Typography variant="h5" sx={{ fontWeight: 700, color: "#1e293b" }}>
              Company & Tenant Governance
            </Typography>
            <Typography variant="body2" sx={{ color: "#64748b", mt: 0.5 }}>
              Manage tenant subscriptions, active plans, and usage limits across companies.
            </Typography>
          </Box>
          <TextField
            size="small"
            placeholder="Search companies..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            sx={{ width: { xs: "100%", sm: 260 }, bgcolor: "#fff", borderRadius: 1 }}
          />
        </Box>

        {/* Navigation Quick Links */}
        <Paper variant="outlined" sx={{ p: 1.5, display: "flex", gap: 1, flexWrap: "wrap", bgcolor: "#ffffff", borderRadius: 2 }}>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin">Overview</MuiButton>
          <MuiButton size="small" variant="contained" sx={{ bgcolor: "#6366f1" }}>Companies</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/agency">Agencies</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/plans">Plans</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/settings">Settings</MuiButton>
        </Paper>

        <SectionCard title="Registered Companies" subtitle="Assign plans and monitor resource usage by company.">
          <Paper variant="outlined" sx={{ overflowX: "auto", width: "100%", maxWidth: "100%", minWidth: 0, borderRadius: 2 }}>
            <Table size="medium" sx={{ minWidth: 800 }}>
              <TableHead>
                <TableRow sx={{ bgcolor: "#f8fafc" }}>
                  <TableCell sx={{ fontWeight: 700, color: "#475569" }}>Company</TableCell>
                  <TableCell sx={{ fontWeight: 700, color: "#475569" }}>Plan & Type</TableCell>
                  <TableCell sx={{ fontWeight: 700, color: "#475569" }}>Subscription Validity</TableCell>
                  <TableCell sx={{ fontWeight: 700, color: "#475569" }}>Usage Breakdown</TableCell>
                  <TableCell sx={{ fontWeight: 700, color: "#475569" }}>Assign Plan</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {filteredCompanies.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} sx={{ textAlign: "center", py: 4, color: "text.secondary" }}>
                      No companies found matching your search.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredCompanies.map((company) => {
                    const initials = company.name.split(" ").map((n) => n[0]).join("").substring(0, 2).toUpperCase() || "CO";
                    const isPaid = company.plan?.is_paid ?? (company.plan?.price_monthly ? company.plan.price_monthly > 0 : false);

                    return (
                      <TableRow key={company.id} hover sx={{ "&:last-child td": { borderBottom: 0 } }}>
                        {/* Company Column */}
                        <TableCell sx={{ minWidth: 180 }}>
                          <Box sx={{ display: "flex", alignItems: "center", gap: 1.5 }}>
                            <Avatar sx={{ bgcolor: "#6366f1", width: 38, height: 38, fontSize: "0.875rem", fontWeight: 700 }}>
                              {initials}
                            </Avatar>
                            <Box>
                              <Typography variant="subtitle2" sx={{ fontWeight: 700, color: "#1e293b" }}>
                                {company.name}
                              </Typography>
                              <Box sx={{ mt: 0.25 }}>
                                <StatusBadge label={company.status ?? "active"} />
                              </Box>
                            </Box>
                          </Box>
                        </TableCell>

                        {/* Plan & Type Column */}
                        <TableCell sx={{ minWidth: 160 }}>
                          <Typography variant="subtitle2" sx={{ fontWeight: 700, color: "#0f172a" }}>
                            {company.plan?.name ?? "Starter"}
                          </Typography>
                          <Box sx={{ mt: 0.5 }}>
                            <Box
                              component="span"
                              sx={{
                                px: 1,
                                py: 0.25,
                                borderRadius: 1,
                                fontSize: "0.7rem",
                                fontWeight: 700,
                                bgcolor: isPaid ? "rgba(99, 102, 241, 0.12)" : "rgba(100, 116, 139, 0.12)",
                                color: isPaid ? "#4f46e5" : "#475569",
                                border: `1px solid ${isPaid ? "rgba(99, 102, 241, 0.2)" : "rgba(100, 116, 139, 0.2)"}`,
                              }}
                            >
                              {isPaid ? "Paid Plan" : "28-Day Demo Trial"}
                            </Box>
                          </Box>
                        </TableCell>

                        {/* Subscription Validity Column */}
                        <TableCell sx={{ minWidth: 220 }}>
                          {(() => {
                            const isExpired = company.subscription?.is_expired || company.status === "expired" || (company.subscription?.expires_at ? new Date(company.subscription.expires_at) < new Date() : false);
                            return (
                              <Box sx={{ display: "grid", gap: 0.4 }}>
                                <Typography variant="caption" sx={{ color: "#475569", display: "flex", alignItems: "center", gap: 0.5 }}>
                                  <span>📅</span> <strong>Started:</strong> {company.subscription?.started_at ? new Date(company.subscription.started_at).toLocaleDateString() : "N/A"}
                                </Typography>
                                <Typography
                                  variant="caption"
                                  sx={{
                                    color: isExpired ? "#ef4444" : (company.subscription?.expires_at ? "#d97706" : "#2563eb"),
                                    fontWeight: 700,
                                    display: "flex",
                                    alignItems: "center",
                                    gap: 0.5,
                                  }}
                                >
                                  <span>⏳</span> <strong>Expires:</strong>{" "}
                                  {company.subscription?.expires_at
                                    ? `${new Date(company.subscription.expires_at).toLocaleDateString()}${isExpired ? " (Expired)" : ""}`
                                    : "30-Day Demo Trial"}
                                </Typography>
                                <MuiButton
                                  size="small"
                                  variant="outlined"
                                  onClick={() => openExpiryModal(company)}
                                  sx={{ mt: 0.5, py: 0.25, px: 1, fontSize: "0.7rem", borderRadius: 1, alignSelf: "flex-start", borderColor: isExpired ? "#fca5a5" : "#cbd5e1", color: isExpired ? "#dc2626" : "#475569" }}
                                >
                                  {isExpired ? "Extend Expiry" : "Set Expiry Date"}
                                </MuiButton>
                              </Box>
                            );
                          })()}
                        </TableCell>

                        {/* Usage Breakdown Column */}
                        <TableCell sx={{ minWidth: 200 }}>
                          {Object.entries(company.usage ?? {}).length === 0 ? (
                            <Typography variant="caption" color="text.secondary">Standard limits active</Typography>
                          ) : (
                            <Box sx={{ display: "flex", flexWrap: "wrap", gap: 0.5, maxWidth: 220 }}>
                              {Object.entries(company.usage ?? {})
                                .filter(([_, val]) => Number(val) > 0)
                                .slice(0, 4)
                                .map(([key, value]) => (
                                  <Box
                                    key={key}
                                    component="span"
                                    sx={{
                                      px: 1,
                                      py: 0.25,
                                      borderRadius: 1,
                                      fontSize: "0.6875rem",
                                      fontWeight: 600,
                                      bgcolor: "#f1f5f9",
                                      color: "#334155",
                                      border: "1px solid #e2e8f0",
                                    }}
                                  >
                                    {key.replace(/_/g, " ")}: {value}
                                  </Box>
                                ))}
                              {Object.entries(company.usage ?? {}).filter(([_, val]) => Number(val) > 0).length === 0 && (
                                <Typography variant="caption" sx={{ color: "#94a3b8" }}>Standard limits active</Typography>
                              )}
                            </Box>
                          )}
                        </TableCell>

                        {/* Assign Plan Column */}
                        <TableCell sx={{ minWidth: 160 }}>
                          <TextField
                            select
                            size="small"
                            defaultValue={company.plan?.id ?? ""}
                            onChange={(event) => void onChangePlan(company.id, event.target.value)}
                            sx={{ minWidth: 150, bgcolor: "#fff" }}
                          >
                            {plans.map((plan) => (
                              <MenuItem key={plan.id} value={plan.id}>
                                {plan.name}
                              </MenuItem>
                            ))}
                          </TextField>
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </Paper>
        </SectionCard>
      </Box>

      {/* Set Expiry Modal */}
      <Modal
        open={expiryModalCompany !== null}
        onClose={() => setExpiryModalCompany(null)}
        title={`Set Expiry Date — ${expiryModalCompany?.name ?? ""}`}
      >
        <Box sx={{ display: "grid", gap: 2 }}>
          <Typography variant="body2" sx={{ color: "#64748b" }}>
            Select a quick plan duration preset to automatically calculate the expiration date, or pick a custom date below.
          </Typography>

          <Box sx={{ display: "flex", flexWrap: "wrap", gap: 1, my: 0.5 }}>
            <MuiButton size="small" variant="outlined" onClick={() => applyDurationPreset(28)} sx={{ fontSize: "0.75rem", borderRadius: 1.5, textTransform: "none", fontWeight: 600 }}>
              +1 Month (28 Days)
            </MuiButton>
            <MuiButton size="small" variant="outlined" onClick={() => applyDurationPreset(56)} sx={{ fontSize: "0.75rem", borderRadius: 1.5, textTransform: "none", fontWeight: 600 }}>
              +2 Months (56 Days)
            </MuiButton>
            <MuiButton size="small" variant="outlined" onClick={() => applyDurationPreset(84)} sx={{ fontSize: "0.75rem", borderRadius: 1.5, textTransform: "none", fontWeight: 600 }}>
              +3 Months (84 Days)
            </MuiButton>
            <MuiButton size="small" variant="outlined" onClick={() => applyDurationPreset(168)} sx={{ fontSize: "0.75rem", borderRadius: 1.5, textTransform: "none", fontWeight: 600 }}>
              +6 Months (168 Days)
            </MuiButton>
            <MuiButton size="small" variant="outlined" onClick={() => applyDurationPreset(365)} sx={{ fontSize: "0.75rem", borderRadius: 1.5, textTransform: "none", fontWeight: 600 }}>
              +1 Year (365 Days)
            </MuiButton>
          </Box>

          <TextField
            type="date"
            label="Expiration Date"
            InputLabelProps={{ shrink: true }}
            value={newExpiryDate}
            onChange={(e) => setNewExpiryDate(e.target.value)}
            fullWidth
            sx={{ bgcolor: "#fff" }}
          />

          {newExpiryDate && (
            <Typography variant="caption" sx={{ color: "#4f46e5", fontWeight: 700, display: "block", mt: -0.5 }}>
              📅 Expiration Date: {new Date(newExpiryDate).toLocaleDateString("en-US", { weekday: "short", year: "numeric", month: "short", day: "numeric" })}
            </Typography>
          )}

          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 1 }}>
            <MuiButton variant="outlined" onClick={() => setExpiryModalCompany(null)}>
              Cancel
            </MuiButton>
            <MuiButton variant="contained" onClick={() => void saveExpiryModal()} disabled={savingExpiry} sx={{ bgcolor: "#6366f1" }}>
              {savingExpiry ? "Saving..." : "Save Expiry Date"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>
    </AppShell>
  );
}
