"use client";

import Link from "next/link";
import { Box, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, Typography } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";

const roleCatalog = [
  { slug: "company_owner", name: "Company Owner", scope: "Tenant", summary: "Full tenant access (excluding platform-only controls)." },
  { slug: "company_admin", name: "Company Admin", scope: "Tenant", summary: "Manage team, providers, campaigns, and billing-related actions." },
  { slug: "billing_manager", name: "Billing Manager", scope: "Tenant", summary: "Billing, invoices, and usage visibility." },
  { slug: "developer_manager", name: "Developer Manager", scope: "Tenant", summary: "Provider + webhook + API token access for integrations." },
  { slug: "operations_manager", name: "Operations Manager", scope: "Tenant", summary: "Operational monitoring, call exports, analytics, and audits." },
  { slug: "support_analyst", name: "Support Analyst", scope: "Tenant", summary: "Read-only support access for audits, calls, and analytics." },
  { slug: "admin", name: "Admin", scope: "Tenant", summary: "Legacy admin role for non-platform actions." },
  { slug: "agency", name: "Agency", scope: "Tenant", summary: "Limited tenant management for agency workflows." },
  { slug: "team", name: "Team", scope: "Tenant", summary: "Basic call + analytics access." },
  { slug: "super_admin", name: "Super Admin", scope: "Platform", summary: "Platform-wide administration across tenants." },
  { slug: "platform_super_admin", name: "Platform Super Admin", scope: "Platform", summary: "Highest platform role; includes all permissions." },
] as const;

export default function UserRolesPage() {
  return (
    <AppShell requiredPermissions={["role.assign"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard title="User Roles" subtitle="Roles define permissions. Assign roles to users from the Team page.">
          <Stack direction={{ xs: "column", sm: "row" }} spacing={1} sx={{ alignItems: { sm: "center" } }}>
            <Typography variant="body2" color="text.secondary">
              Role assignment is tied to team memberships, so user-to-role mapping happens in Team.
            </Typography>
            <MuiButton component={Link} href="/team" variant="contained" sx={{ alignSelf: { xs: "flex-start", sm: "center" } }}>
              Open Team
            </MuiButton>
          </Stack>
        </SectionCard>

        <SectionCard title="Role Catalog" subtitle="Reference list of available roles and intended scope.">
          <Paper variant="outlined" sx={{ overflowX: "auto" }}>
            <Table size="medium" sx={{ minWidth: 760 }}>
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell>Role</TableCell>
                  <TableCell>Slug</TableCell>
                  <TableCell>Scope</TableCell>
                  <TableCell>Summary</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {roleCatalog.map((role) => (
                  <TableRow key={role.slug} hover>
                    <TableCell>{role.name}</TableCell>
                    <TableCell>{role.slug}</TableCell>
                    <TableCell>{role.scope}</TableCell>
                    <TableCell>{role.summary}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Paper>
        </SectionCard>
      </Box>
    </AppShell>
  );
}
