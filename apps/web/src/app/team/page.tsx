"use client";

import { FormEvent, useEffect, useState } from "react";
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
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";

type TeamListResponse = {
  data: Array<{
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
  }>;
  meta?: {
    pagination?: {
      current_page: number;
      last_page: number;
    };
  };
};

export default function TeamPage() {
  const [members, setMembers] = useState<TeamListResponse["data"]>([]);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("support_analyst");
  const [statusByMember, setStatusByMember] = useState<Record<string, "active" | "disabled">>({});
  const [currentPage, setCurrentPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");

  async function loadTeam(page = 1) {
    setLoading(true);
    setMessage("");

    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<TeamListResponse>(`/team/members?page=${page}`, {
        token,
        tenantId,
      });
      setMembers(response.data);
      setCurrentPage(response.meta?.pagination?.current_page ?? 1);
      setLastPage(response.meta?.pagination?.last_page ?? 1);
      const nextStatus: Record<string, "active" | "disabled"> = {};
      response.data.forEach((member) => {
        nextStatus[member.id] = member.status === "disabled" ? "disabled" : "active";
      });
      setStatusByMember(nextStatus);
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : "Failed to fetch team.";
      setMessage(errorMessage);
    } finally {
      setLoading(false);
    }
  }

  async function inviteMember(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest("/team/invitations", {
        method: "POST",
        token,
        tenantId,
        body: { email, role },
      });
      setEmail("");
      setMessage("Invitation sent successfully.");
      await loadTeam(currentPage);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to send invitation.");
    }
  }

  async function updateMember(memberId: string) {
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest(`/team/members/${memberId}`, {
        method: "PATCH",
        token,
        tenantId,
        body: { status: statusByMember[memberId] },
      });
      setMessage("Member updated.");
      await loadTeam(currentPage);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to update team member.");
    }
  }

  async function removeMember(memberId: string) {
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest(`/team/members/${memberId}`, {
        method: "DELETE",
        token,
        tenantId,
      });
      setMessage("Member removed.");
      await loadTeam(currentPage);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to remove team member.");
    }
  }

  useEffect(() => {
    void loadTeam(1);
  }, []);

  return (
    <AppShell requiredPermissions={["team.view"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard title="Invite Team Member" subtitle="Send invitation with role and onboarding token.">
          <Box
            component="form"
            sx={{ display: "grid", gap: 1.25, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}
            onSubmit={inviteMember}
          >
            <TextField
              size="medium"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              type="email"
              placeholder="teammate@company.com"
              required
            />
            <TextField
              select
              size="medium"
              value={role}
              onChange={(event) => setRole(event.target.value)}
            >
              <MenuItem value="company_admin">Company Admin</MenuItem>
              <MenuItem value="billing_manager">Billing Manager</MenuItem>
              <MenuItem value="developer_manager">Developer Manager</MenuItem>
              <MenuItem value="operations_manager">Operations Manager</MenuItem>
              <MenuItem value="support_analyst">Support Analyst</MenuItem>
            </TextField>
            <MuiButton type="submit" variant="contained">
              Send Invite
            </MuiButton>
          </Box>
        </SectionCard>

        <SectionCard title="Team Directory" subtitle="Manage role assignments and member status.">
          <Paper variant="outlined" sx={{ overflowX: "auto" }}>
            <Table size="medium">
              <TableHead>
                <TableRow sx={{ bgcolor: "action.hover" }}>
                  <TableCell>Name</TableCell>
                  <TableCell>Email</TableCell>
                  <TableCell>Role</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={5}>
                      <LoadingState label="Loading team members..." />
                    </TableCell>
                  </TableRow>
                ) : null}
                {members.map((member) => (
                  <TableRow key={member.id} hover>
                    <TableCell>
                      {member.user ? `${member.user.first_name} ${member.user.last_name}`.trim() : "Invited user"}
                    </TableCell>
                    <TableCell>{member.user?.email ?? "-"}</TableCell>
                    <TableCell>{member.role.name}</TableCell>
                    <TableCell>
                      <TextField
                        select
                        size="medium"
                        value={statusByMember[member.id] ?? "active"}
                        onChange={(event) =>
                          setStatusByMember((prev) => ({
                            ...prev,
                            [member.id]: event.target.value as "active" | "disabled",
                          }))
                        }
                        sx={{ minWidth: 120 }}
                      >
                        <MenuItem value="active">Active</MenuItem>
                        <MenuItem value="disabled">Disabled</MenuItem>
                      </TextField>
                    </TableCell>
                    <TableCell>
                      <Stack direction="row" spacing={1}>
                        <MuiButton
                          type="button"
                          size="medium"
                          variant="outlined"
                          onClick={() => void updateMember(member.id)}
                        >
                          Save
                        </MuiButton>
                        <MuiButton
                          type="button"
                          size="medium"
                          variant="outlined"
                          color="error"
                          onClick={() => void removeMember(member.id)}
                        >
                          Remove
                        </MuiButton>
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
                {members.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5}>
                      <EmptyState title="No team members" description="No team members were found for this tenant." />
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableBody>
            </Table>
          </Paper>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 1.5 }}>
            <MuiButton
              type="button"
              variant="outlined"
              size="medium"
              disabled={currentPage <= 1 || loading}
              onClick={() => void loadTeam(currentPage - 1)}
            >
              Previous
            </MuiButton>
            <Typography variant="body2">
              Page {currentPage} of {lastPage}
            </Typography>
            <MuiButton
              type="button"
              variant="outlined"
              size="medium"
              disabled={currentPage >= lastPage || loading}
              onClick={() => void loadTeam(currentPage + 1)}
            >
              Next
            </MuiButton>
          </Stack>
          {message ? <ErrorState message={message} /> : null}
        </SectionCard>
      </Box>
    </AppShell>
  );
}
