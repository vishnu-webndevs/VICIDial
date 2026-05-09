"use client";

import { useEffect, useState } from "react";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import {
  Box,
  MuiButton,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
} from "@/ui";

type AuditLog = {
  id: string;
  action: string;
  resource_type: string;
  resource_id: string | null;
  actor?: {
    first_name?: string;
    last_name?: string;
    email?: string;
  } | null;
  created_at: string;
};

export default function AuditLogsPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");

  async function loadLogs() {
    setLoading(true);
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<{ data: AuditLog[] }>("/audit-logs?per_page=50", { token, tenantId });
      setLogs(response.data ?? []);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load audit logs.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadLogs();
  }, []);

  return (
    <AppShell requiredPermissions={["audit.view"]}>
      <SectionCard title="Audit Logs" subtitle="Tenant-scoped activity history and traceability records.">
        <MuiButton type="button" variant="outlined" disabled={loading} onClick={() => void loadLogs()}>
          {loading ? "Refreshing..." : "Refresh"}
        </MuiButton>
        <TableContainer component={Paper} variant="outlined" sx={{ mt: 3 }}>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Action</TableCell>
                <TableCell>Resource</TableCell>
                <TableCell>Actor</TableCell>
                <TableCell>Created</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={4}>
                      <LoadingState label="Loading audit logs..." />
                    </TableCell>
                  </TableRow>
                ) : null}
              {logs.map((log) => (
                <TableRow hover key={log.id}>
                  <TableCell>{log.action}</TableCell>
                  <TableCell>
                    {log.resource_type} {log.resource_id ? `(${log.resource_id.slice(0, 8)})` : ""}
                  </TableCell>
                  <TableCell>
                    {log.actor
                      ? `${log.actor.first_name ?? ""} ${log.actor.last_name ?? ""}`.trim() || log.actor.email || "-"
                      : "-"}
                  </TableCell>
                  <TableCell>{new Date(log.created_at).toLocaleString()}</TableCell>
                </TableRow>
              ))}
              {logs.length === 0 ? (
                <TableRow>
                    <TableCell colSpan={4}>
                      <EmptyState title="No audit logs" description="No audit entries are available for this tenant." />
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </TableContainer>
        {message ? <Box sx={{ mt: 2 }}><ErrorState message={message} /></Box> : null}
      </SectionCard>
    </AppShell>
  );
}
