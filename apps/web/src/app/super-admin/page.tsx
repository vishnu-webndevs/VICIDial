"use client";

import { FormEvent, useEffect, useState } from "react";
import { Box, Modal, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";
import { EmptyPanel, KpiCard, ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";
import Link from "next/link";

export default function SuperAdminPage() {
  const [admins, setAdmins] = useState<Array<{ id: string; name: string; email: string; companies_count: number; status: string }>>([]);
  const [agencies, setAgencies] = useState<Array<{ id: string; name: string; email: string; companies_count: number; status: string }>>([]);
  const [message, setMessage] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [modal, setModal] = useState<"admin" | "agency" | null>(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);

  async function loadData() {
    try {
      const { token, tenantId } = getTenantContext();
      const teamResponse = await apiRequest<{
        data: Array<{
          id: string;
          status: string;
          role?: { slug?: string; name?: string } | null;
          user?: { first_name?: string; last_name?: string; email?: string } | null;
        }>;
      }>("/platform/team/members?per_page=200", { token, tenantId });

      const rows = (teamResponse.data ?? []).map((member) => {
        const fullName = `${member.user?.first_name ?? ""} ${member.user?.last_name ?? ""}`.trim() || member.user?.email || "Pending Invite";
        return {
          id: member.id,
          name: fullName,
          email: member.user?.email ?? "Invitation pending",
          companies_count: 1,
          status: member.status ?? "unknown",
          role: member.role?.slug ?? "",
        };
      });

      setAdmins(rows.filter((row) => ["platform_super_admin", "super_admin", "company_admin", "admin"].includes(row.role)));
      setAgencies(rows.filter((row) => row.role === "agency"));
    } catch (err) {
      setMessage({ tone: "error", text: err instanceof Error ? err.message : "Failed to load super admin data." });
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  function openModal(type: "admin" | "agency") {
    setModal(type);
    setName("");
    setEmail("");
  }

  async function onInvite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest("/platform/team/invitations", {
        method: "POST",
        token,
        tenantId,
        body: {
          email,
          role: modal === "admin" ? "admin" : "agency",
        },
      });
      setMessage({ tone: "success", text: `${modal === "admin" ? "Admin" : "Agency"} invitation sent.` });
      setModal(null);
      await loadData();
    } catch (error) {
      setMessage({ tone: "error", text: error instanceof Error ? error.message : "Failed to send invite." });
    } finally {
      setSaving(false);
    }
  }

  return (
    <AppShell
      requiredPermissions={["team.view", "team.invite", "role.assign"]}
      requiredRoles={["platform_super_admin", "super_admin"]}
    >
      {message ? <ToastMessage tone={message.tone} message={message.text} /> : null}
      <Box sx={{ display: "grid", gap: 2.5 }}>
        {/* Header Title & Actions */}
        <Box sx={{ display: "flex", flexDirection: { xs: "column", sm: "row" }, justifyContent: "space-between", alignItems: { xs: "stretch", sm: "center" }, gap: 1.5 }}>
          <Box>
            <Typography variant="h5" sx={{ fontWeight: 700, color: "#1e293b" }}>
              Super Admin Console
            </Typography>
            <Typography variant="body2" sx={{ color: "#64748b", mt: 0.5 }}>
              Platform management, company coverage, agencies, and system governance.
            </Typography>
          </Box>
          <Box sx={{ display: "flex", gap: 1 }}>
            <MuiButton variant="contained" onClick={() => openModal("admin")}>
              Invite Admin
            </MuiButton>
            <MuiButton variant="outlined" onClick={() => openModal("agency")}>
              Invite Agency
            </MuiButton>
          </Box>
        </Box>

        {/* Platform KPI Metrics */}
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", sm: "repeat(2, 1fr)", lg: "repeat(4, 1fr)" } }}>
          <KpiCard label="Platform Admins" value={admins.length} hint="Active platform managers" />
          <KpiCard label="Agency Partners" value={agencies.length} hint="Registered resellers" />
          <KpiCard label="Total Coverage" value={admins.reduce((acc, a) => acc + (a.companies_count || 1), 0)} hint="Monitored tenants" />
          <KpiCard label="System Status" value="Operational" hint="All services active" />
        </Box>

        {/* Navigation Quick Links */}
        <Paper variant="outlined" sx={{ p: 1.5, display: "flex", gap: 1, flexWrap: "wrap", bgcolor: "#ffffff", borderRadius: 2 }}>
          <MuiButton size="small" variant="contained" sx={{ bgcolor: "#6366f1" }}>Overview</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/companies">Companies</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/agency">Agencies</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/plans">Plans</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/settings">Settings</MuiButton>
        </Paper>

        <SectionCard title="Admins" subtitle="Platform admin roster and company coverage.">
          {admins.length === 0 ? (
            <EmptyPanel title="No admin records" description="No platform admin records are available in this context." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto", width: "100%", maxWidth: "100%", minWidth: 0 }}>
              <Table size="medium" sx={{ minWidth: 600 }}>
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Name</TableCell>
                    <TableCell>Email</TableCell>
                    <TableCell>Companies</TableCell>
                    <TableCell>Status</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {admins.map((admin) => (
                    <TableRow key={admin.id} hover>
                      <TableCell sx={{ fontWeight: 600 }}>{admin.name}</TableCell>
                      <TableCell>{admin.email}</TableCell>
                      <TableCell>{admin.companies_count}</TableCell>
                      <TableCell>
                        <Box
                          component="span"
                          sx={{
                            px: 1.25,
                            py: 0.5,
                            borderRadius: 999,
                            fontSize: 12,
                            fontWeight: 600,
                            bgcolor: admin.status === "active" ? "rgba(34, 197, 94, 0.12)" : "rgba(245, 158, 11, 0.12)",
                            color: admin.status === "active" ? "#16a34a" : "#d97706",
                          }}
                        >
                          {admin.status}
                        </Box>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          )}
        </SectionCard>

        <SectionCard title="Agencies" subtitle="Agency roster and company coverage.">
          {agencies.length === 0 ? (
            <EmptyPanel title="No agencies" description="No agencies are available in this context." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto", width: "100%", maxWidth: "100%", minWidth: 0 }}>
              <Table size="medium" sx={{ minWidth: 600 }}>
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Name</TableCell>
                    <TableCell>Email</TableCell>
                    <TableCell>Companies</TableCell>
                    <TableCell>Status</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {agencies.map((agency) => (
                    <TableRow key={agency.id} hover>
                      <TableCell sx={{ fontWeight: 600 }}>{agency.name}</TableCell>
                      <TableCell>{agency.email}</TableCell>
                      <TableCell>{agency.companies_count}</TableCell>
                      <TableCell>
                        <Box
                          component="span"
                          sx={{
                            px: 1.25,
                            py: 0.5,
                            borderRadius: 999,
                            fontSize: 12,
                            fontWeight: 600,
                            bgcolor: agency.status === "active" ? "rgba(34, 197, 94, 0.12)" : "rgba(245, 158, 11, 0.12)",
                            color: agency.status === "active" ? "#16a34a" : "#d97706",
                          }}
                        >
                          {agency.status}
                        </Box>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Paper>
          )}
        </SectionCard>
      </Box>

      <Modal open={modal !== null} onClose={() => setModal(null)}>
        <Box component="form" onSubmit={onInvite} sx={{ display: "grid", gap: 1.5, pt: 1 }}>
          <Typography variant="h6" sx={{ fontWeight: 600 }}>
            Invite {modal === "admin" ? "Platform Admin" : "Agency Reseller"}
          </Typography>
          <TextField
            size="medium"
            required
            label="Name"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />
          <TextField
            size="medium"
            required
            type="email"
            label="Email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 1 }}>
            <MuiButton variant="outlined" onClick={() => setModal(null)}>Cancel</MuiButton>
            <MuiButton type="submit" variant="contained" disabled={saving}>
              {saving ? "Sending..." : "Send Invite"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>
    </AppShell>
  );
}
