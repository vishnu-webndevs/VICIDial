"use client";

import { FormEvent, useEffect, useState } from "react";
import { Box, Modal, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField } from "@/ui";
import { AppShell, SectionCard } from "@/components/app-shell";
import { EmptyPanel, ToastMessage } from "@/components/ui-primitives";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

type Company = {
  id: string;
  name: string;
  status: string;
  team_members_count?: number;
};

export default function AdminDashboardPage() {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [message, setMessage] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [modal, setModal] = useState<"team" | null>(null);
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);

  async function loadData() {
    try {
      const { token, tenantId } = getTenantContext();
      const tenantResponse = await apiRequest<{ data: Company }>("/tenant", { token, tenantId });
      const membersResponse = await apiRequest<{ meta?: { pagination?: { total?: number } } }>("/team/members?per_page=1", {
        token,
        tenantId,
      });

      setCompanies([
        {
          id: tenantResponse.data.id,
          name: tenantResponse.data.name,
          status: tenantResponse.data.status,
          team_members_count: Number(membersResponse.meta?.pagination?.total ?? 0),
        },
      ]);
    } catch (err) {
      setMessage({ tone: "error", text: err instanceof Error ? err.message : "Failed to load admin dashboard." });
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  function openModal(nextModal: "team") {
    setModal(nextModal);
    setEmail("");
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    try {
      const { token, tenantId } = getTenantContext();
      if (modal === "team") {
        await apiRequest("/team/invitations", {
          method: "POST",
          token,
          tenantId,
          body: { email, role: "team" },
        });
      }
      setMessage({ tone: "success", text: "Action completed successfully." });
      setModal(null);
      await loadData();
    } catch (error) {
      setMessage({ tone: "error", text: error instanceof Error ? error.message : "Request failed." });
    } finally {
      setSaving(false);
    }
  }

  return (
    <AppShell
      requiredPermissions={["team.view"]}
      requiredRoles={["admin", "company_owner", "company_admin"]}
    >
      {message ? <ToastMessage tone={message.tone} message={message.text} /> : null}
      <Box sx={{ display: "grid", gap: 2 }}>
        <Box sx={{ display: "flex", justifyContent: "flex-end" }}>
          <MuiButton variant="contained" onClick={() => openModal("team")}>Invite Team Member</MuiButton>
        </Box>

        <SectionCard title="Companies" subtitle="Manage companies and invite collaborators.">
          {companies.length === 0 ? (
            <EmptyPanel title="No companies" description="Create your first company to begin onboarding team and agencies." />
          ) : (
            <Paper variant="outlined" sx={{ overflowX: "auto" }}>
              <Table size="medium">
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Name</TableCell>
                    <TableCell>Status</TableCell>
                    <TableCell>Team Members</TableCell>
                    <TableCell>Actions</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {companies.map((company) => (
                    <TableRow key={company.id}>
                      <TableCell>{company.name}</TableCell>
                      <TableCell>{company.status}</TableCell>
                      <TableCell>{company.team_members_count ?? 0}</TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={1}>
                          <MuiButton size="medium" variant="outlined" onClick={() => openModal("team")}>
                            Invite Team Member
                          </MuiButton>
                        </Stack>
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
        <Box component="form" onSubmit={onSubmit} sx={{ display: "grid", gap: 1.25 }}>
          <TextField
            required
            size="medium"
            type="email"
            label="Email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
          <Stack direction="row" spacing={1}>
            <MuiButton variant="outlined" onClick={() => setModal(null)}>Cancel</MuiButton>
            <MuiButton variant="contained" type="submit" disabled={saving}>
              {saving ? "Submitting..." : "Submit"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>
    </AppShell>
  );
}
