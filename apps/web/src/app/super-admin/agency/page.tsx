"use client";

import { useEffect, useMemo, useState } from "react";
import { Box, MuiButton, Paper, Stack, Typography } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";
import { EmptyPanel, KpiCard, ToastMessage } from "@/components/ui-primitives";
import Link from "next/link";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

type AgencyCompany = {
  id: string;
  name: string;
  active_campaigns: number;
  agents: number;
  calls_today: number;
  conversion_rate: number;
  campaigns?: Array<{ id: string; name: string; status: string }>;
  team_members?: Array<{ id: string; name: string; role: string; status: string }>;
};

export default function SuperAdminAgencyPage() {
  const [companies, setCompanies] = useState<AgencyCompany[]>([]);
  const [expanded, setExpanded] = useState<string>("");
  const [message, setMessage] = useState("");

  async function loadData() {
    try {
      const { token, tenantId } = getTenantContext();
      const [tenantResponse, campaignResponse, memberResponse, dashboardResponse] = await Promise.all([
        apiRequest<{ data: { id: string; name: string } }>("/tenant", { token, tenantId }),
        apiRequest<{ data: Array<{ id: string; name: string; status: string }> }>("/campaigns?per_page=200", { token, tenantId }),
        apiRequest<{ data: Array<{ id: string; status: string; role?: { slug?: string } | null; user?: { first_name?: string; last_name?: string } | null }> }>(
          "/team/members?per_page=200",
          { token, tenantId }
        ),
        apiRequest<{ data: { calls_today?: number; conversion_rate?: number } }>("/analytics/dashboard-summary", { token, tenantId }).catch(() => ({
          data: { calls_today: 0, conversion_rate: 0 },
        })),
      ]);

      const campaigns = campaignResponse.data ?? [];
      const members = memberResponse.data ?? [];
      const activeCampaigns = campaigns.filter((item) => item.status === "running").length;

      const teamMembers = members.map((member) => ({
        id: member.id,
        name: `${member.user?.first_name ?? ""} ${member.user?.last_name ?? ""}`.trim() || "Pending Invite",
        role: member.role?.slug ?? "unknown",
        status: member.status,
      }));

      setCompanies([
        {
          id: tenantResponse.data.id,
          name: tenantResponse.data.name,
          active_campaigns: activeCampaigns,
          agents: teamMembers.filter((member) => member.role === "team" && member.status === "active").length,
          calls_today: Number(dashboardResponse.data.calls_today ?? 0),
          conversion_rate: Number(dashboardResponse.data.conversion_rate ?? 0),
          campaigns,
          team_members: teamMembers,
        },
      ]);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load agency dashboard.");
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadData();
  }, []);

  const summary = useMemo(() => {
    return companies.reduce(
      (acc, company) => {
        acc.totalCompanies += 1;
        acc.activeCampaigns += Number(company.active_campaigns ?? 0);
        acc.agentsOnline += Number(company.agents ?? 0);
        acc.callsToday += Number(company.calls_today ?? 0);
        return acc;
      },
      { totalCompanies: 0, activeCampaigns: 0, agentsOnline: 0, callsToday: 0 }
    );
  }, [companies]);

  return (
    <AppShell
      requiredPermissions={["tenant.view"]}
      requiredRoles={["platform_super_admin", "super_admin"]}
    >
      {message ? <ToastMessage tone="error" message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2.5 }}>
        {/* Header */}
        <Box sx={{ display: "flex", flexDirection: { xs: "column", sm: "row" }, justifyContent: "space-between", alignItems: { xs: "stretch", sm: "center" }, gap: 1.5 }}>
          <Box>
            <Typography variant="h5" sx={{ fontWeight: 700, color: "#1e293b" }}>
              Agency Reseller Workspace
            </Typography>
            <Typography variant="body2" sx={{ color: "#64748b", mt: 0.5 }}>
              Monitor managed companies, active campaign volume, and agency team activities.
            </Typography>
          </Box>
        </Box>

        {/* Navigation Quick Links */}
        <Paper variant="outlined" sx={{ p: 1.5, display: "flex", gap: 1, flexWrap: "wrap", bgcolor: "#ffffff", borderRadius: 2 }}>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin">Overview</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/companies">Companies</MuiButton>
          <MuiButton size="small" variant="contained" sx={{ bgcolor: "#6366f1" }}>Agencies</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/plans">Plans</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/settings">Settings</MuiButton>
        </Paper>

        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", sm: "repeat(2, 1fr)", lg: "repeat(4, 1fr)" } }}>
          <KpiCard label="Total Companies" value={summary.totalCompanies} />
          <KpiCard label="Active Campaigns" value={summary.activeCampaigns} />
          <KpiCard label="Agents Online" value={summary.agentsOnline} />
          <KpiCard label="Calls Today" value={summary.callsToday} />
        </Box>

        <SectionCard title="Managed Companies" subtitle="Expand a company to view active campaigns and team roster.">
          {companies.length === 0 ? <EmptyPanel title="No companies" description="No companies are linked to this agency." /> : null}
          <Stack spacing={1.5}>
            {companies.map((company) => {
              const isExpanded = expanded === company.id;
              return (
                <Paper key={company.id} variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
                  <Box sx={{ display: "flex", flexDirection: { xs: "column", sm: "row" }, justifyContent: "space-between", alignItems: { xs: "stretch", sm: "center" }, gap: 1.5 }}>
                    <Box sx={{ display: "grid", gap: 0.5 }}>
                      <Typography variant="subtitle1" sx={{ fontWeight: 600, color: "#1e293b" }}>
                        {company.name}
                      </Typography>
                      <Typography variant="caption" sx={{ color: "#64748b" }}>
                        {company.active_campaigns} active campaigns | {company.agents} active agents | {company.calls_today} calls today | {company.conversion_rate}% conversion
                      </Typography>
                    </Box>
                    <MuiButton size="medium" variant="outlined" onClick={() => setExpanded(isExpanded ? "" : company.id)}>
                      {isExpanded ? "Hide Details" : "View Details"}
                    </MuiButton>
                  </Box>

                  {isExpanded ? (
                    <Box sx={{ mt: 2, display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", md: "1fr 1fr" } }}>
                      <Paper variant="outlined" sx={{ p: 2, bgcolor: "#f8fafc", borderRadius: 2 }}>
                        <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: "#1e293b" }}>
                          Active Campaigns ({company.campaigns?.length ?? 0})
                        </Typography>
                        {(company.campaigns ?? []).length === 0 ? (
                          <Typography variant="body2" color="text.secondary">No campaigns listed.</Typography>
                        ) : (
                          (company.campaigns ?? []).map((campaign) => (
                            <Box key={campaign.id} sx={{ py: 0.5, display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                              <Typography variant="body2" sx={{ fontWeight: 500 }}>{campaign.name}</Typography>
                              <Typography variant="caption" sx={{ textTransform: "capitalize", px: 1, py: 0.25, borderRadius: 1, bgcolor: "#e2e8f0" }}>
                                {campaign.status}
                              </Typography>
                            </Box>
                          ))
                        )}
                      </Paper>

                      <Paper variant="outlined" sx={{ p: 2, bgcolor: "#f8fafc", borderRadius: 2 }}>
                        <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: "#1e293b" }}>
                          Team Roster ({company.team_members?.length ?? 0})
                        </Typography>
                        {(company.team_members ?? []).length === 0 ? (
                          <Typography variant="body2" color="text.secondary">No team members listed.</Typography>
                        ) : (
                          (company.team_members ?? []).map((member) => (
                            <Box key={member.id} sx={{ py: 0.5, display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                              <Typography variant="body2" sx={{ fontWeight: 500 }}>{member.name}</Typography>
                              <Typography variant="caption" color="text.secondary">
                                {member.role} ({member.status})
                              </Typography>
                            </Box>
                          ))
                        )}
                      </Paper>
                    </Box>
                  ) : null}
                </Paper>
              );
            })}
          </Stack>
        </SectionCard>
      </Box>
    </AppShell>
  );
}
