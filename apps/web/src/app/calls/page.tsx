"use client";

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard, StatusBadge } from "@/components/app-shell";
import { Chip, ToastMessage } from "@/components/ui-primitives";
import { listCalls } from "@/lib/product-api";
import { API_BASE_URL } from "@/lib/runtime-config";
import { useLiveCalls } from "@/hooks/use-live-calls";
import type { CallRecord } from "@/types/product";

type FilterState = {
  search: string;
  status: string;
  provider_account_id: string;
  from: string;
  to: string;
  per_page: number;
};

const defaultFilters: FilterState = {
  search: "",
  status: "",
  provider_account_id: "",
  from: "",
  to: "",
  per_page: 10,
};

export default function CallDashboardPage() {
  const [filters, setFilters] = useState<FilterState>(defaultFilters);
  const [history, setHistory] = useState<CallRecord[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const [message, setMessage] = useState("");
  const [exporting, setExporting] = useState(false);
  const [quotaWarning, setQuotaWarning] = useState("");
  const [selectedCallId, setSelectedCallId] = useState<string>("");

  const { liveCalls, refresh } = useLiveCalls();

  const loadHistory = useCallback(async (nextPage = 1, currentFilters = filters) => {
    setLoadingHistory(true);
    setMessage("");
    try {
      const response = await listCalls({
        page: nextPage,
        per_page: currentFilters.per_page,
        status: currentFilters.status || undefined,
        provider_account_id: currentFilters.provider_account_id || undefined,
        to_number: currentFilters.search || undefined,
        from: currentFilters.from || undefined,
        to: currentFilters.to || undefined,
      });
      setHistory(response.calls);
      setPage(response.currentPage);
      setLastPage(response.lastPage);
      await refresh();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load call history.");
    } finally {
      setLoadingHistory(false);
    }
  }, [filters, refresh]);

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void loadHistory(1);
  }

  async function exportCsv() {
    setExporting(true);
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const search = new URLSearchParams();
      if (filters.status) {
        search.set("status", filters.status);
      }
      if (filters.provider_account_id) {
        search.set("provider_account_id", filters.provider_account_id);
      }
      if (filters.search) {
        search.set("to_number", filters.search);
      }
      if (filters.from) {
        search.set("from", filters.from);
      }
      if (filters.to) {
        search.set("to", filters.to);
      }
      search.set("limit", "1000");

      const url = `${API_BASE_URL}/calls/export?${search.toString()}`;
      const response = await fetch(url, {
        headers: {
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(tenantId ? { "X-Tenant-Id": tenantId } : {}),
          Accept: "text/csv",
          ...( /ngrok-free\.(app|dev)/i.test(API_BASE_URL)
            ? { "ngrok-skip-browser-warning": "true" }
            : {}),
        },
      });
      if (!response.ok) {
        if (response.status === 429) {
          setQuotaWarning("Call export quota reached. Upgrade your plan or wait for quota reset.");
        }
        throw new Error(`Export failed with status ${response.status}`);
      }
      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = `calls-export-${Date.now()}.csv`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(objectUrl);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "CSV export failed.");
    } finally {
      setExporting(false);
    }
  }

  useEffect(() => {
    void loadHistory(1, filters);
  }, [loadHistory, filters]);

  const sortedHistory = useMemo(
    () =>
      [...history].sort((a, b) => {
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      }),
    [history]
  );
  const selectedCall = sortedHistory.find((item) => item.id === selectedCallId) ?? sortedHistory[0] ?? null;

  useEffect(() => {
    if (!selectedCallId && sortedHistory.length > 0) {
      setSelectedCallId(sortedHistory[0].id);
    }
  }, [selectedCallId, sortedHistory]);

  function applyStatusChip(status: string) {
    setFilters((prev) => ({ ...prev, status }));
  }

  return (
    <AppShell requiredPermissions={["call.view"]}>
      <Box sx={{ display: "grid", gap: 2 }}>
        <SectionCard
          title="Live Calls Monitoring"
          subtitle="Current active calls in queued/ringing/in-progress states."
        >
          {loadingHistory && liveCalls.length === 0 ? <LoadingState label="Loading live calls..." /> : null}
          {liveCalls.length === 0 ? (
            <EmptyState title="No live calls" description="No active calls currently." />
          ) : (
            <Box sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)", xl: "repeat(3, 1fr)" } }}>
              {liveCalls.map((call) => (
                <Paper key={call.id} variant="outlined" sx={{ p: 1.5 }}>
                  <Box sx={{ mb: 0.5, display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                    <StatusBadge label={call.status} />
                    <MuiButton component={Link} href={`/calls/${call.id}`} variant="text" size="medium">Open</MuiButton>
                  </Box>
                  <Typography variant="body2">From: {call.from_number}</Typography>
                  <Typography variant="body2">To: {call.to_number}</Typography>
                  <Typography variant="body2">Provider: {call.provider?.label ?? "N/A"}</Typography>
                </Paper>
              ))}
            </Box>
          )}
        </SectionCard>

        <SectionCard title="Call History" subtitle="Search, filter, and inspect call records in detail.">
          <Box component="form" sx={{ display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)", xl: "repeat(6, 1fr)" } }} onSubmit={applyFilters}>
            <TextField
              value={filters.search}
              onChange={(event) =>
                setFilters((prev) => ({ ...prev, search: event.target.value }))
              }
              placeholder="Search destination"
              size="medium"
            />
            <TextField
              select
              value={filters.status}
              onChange={(event) =>
                setFilters((prev) => ({ ...prev, status: event.target.value }))
              }
              size="medium"
            >
              <MenuItem value="">All Statuses</MenuItem>
              <MenuItem value="queued">Queued</MenuItem>
              <MenuItem value="ringing">Ringing</MenuItem>
              <MenuItem value="in_progress">In Progress</MenuItem>
              <MenuItem value="completed">Completed</MenuItem>
              <MenuItem value="failed">Failed</MenuItem>
            </TextField>
            <TextField
              value={filters.provider_account_id}
              onChange={(event) =>
                setFilters((prev) => ({
                  ...prev,
                  provider_account_id: event.target.value,
                }))
              }
              placeholder="Provider ID"
              size="medium"
            />
            <TextField
              type="datetime-local"
              value={filters.from}
              onChange={(event) => setFilters((prev) => ({ ...prev, from: event.target.value }))}
              size="medium"
            />
            <TextField
              type="datetime-local"
              value={filters.to}
              onChange={(event) => setFilters((prev) => ({ ...prev, to: event.target.value }))}
              size="medium"
            />
            <MuiButton type="submit" disabled={loadingHistory} variant="contained">
              {loadingHistory ? "Loading..." : "Apply Filters"}
            </MuiButton>
          </Box>
          <Box sx={{ mt: 1.5, display: "flex", flexWrap: "wrap", gap: 1 }}>
            <Chip active={filters.status === ""} onClick={() => applyStatusChip("")}>All</Chip>
            <Chip active={filters.status === "queued"} onClick={() => applyStatusChip("queued")}>Queued</Chip>
            <Chip active={filters.status === "ringing"} onClick={() => applyStatusChip("ringing")}>Ringing</Chip>
            <Chip active={filters.status === "in_progress"} onClick={() => applyStatusChip("in_progress")}>In Progress</Chip>
            <Chip active={filters.status === "completed"} onClick={() => applyStatusChip("completed")}>Completed</Chip>
            <Chip active={filters.status === "failed"} onClick={() => applyStatusChip("failed")}>Failed</Chip>
          </Box>
          <MuiButton
            type="button"
            disabled={exporting}
            onClick={() => void exportCsv()}
            variant="outlined"
            color="inherit"
            sx={{ mt: 1.5 }}
          >
            {exporting ? "Exporting..." : "Export CSV"}
          </MuiButton>
          {quotaWarning ? (
            <Box sx={{ mt: 1.5 }}>
              <ToastMessage tone="neutral" message={quotaWarning} />
            </Box>
          ) : null}

          <Box sx={{ mt: 2, display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "1.6fr 1fr" } }}>
            <Paper variant="outlined" sx={{ overflowX: "auto" }}>
              <Table size="medium" sx={{ minWidth: 900 }}>
                <TableHead>
                  <TableRow sx={{ bgcolor: "action.hover" }}>
                    <TableCell>Status</TableCell>
                    <TableCell>From</TableCell>
                    <TableCell>To</TableCell>
                    <TableCell>Provider</TableCell>
                    <TableCell>Duration</TableCell>
                    <TableCell>Created</TableCell>
                    <TableCell>Action</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {loadingHistory && sortedHistory.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={7}>
                        <LoadingState label="Loading call history..." />
                      </TableCell>
                    </TableRow>
                  ) : null}
                  {sortedHistory.map((call) => (
                    <TableRow
                      key={call.id}
                      onClick={() => setSelectedCallId(call.id)}
                      selected={selectedCall?.id === call.id}
                      hover
                      sx={{ cursor: "pointer" }}
                    >
                      <TableCell>
                        <StatusBadge label={call.status} />
                      </TableCell>
                      <TableCell>{call.from_number}</TableCell>
                      <TableCell>{call.to_number}</TableCell>
                      <TableCell>{call.provider?.label ?? "N/A"}</TableCell>
                      <TableCell>{call.duration_seconds}s</TableCell>
                      <TableCell>{new Date(call.created_at).toLocaleString()}</TableCell>
                      <TableCell><MuiButton component={Link} href={`/calls/${call.id}`} size="medium">View Detail</MuiButton></TableCell>
                    </TableRow>
                  ))}
                  {!loadingHistory && sortedHistory.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={7}>
                        <EmptyState
                          title="No call history"
                          description="Apply filters or place a call from Dialer to populate history."
                        />
                      </TableCell>
                    </TableRow>
                  ) : null}
                </TableBody>
              </Table>
            </Paper>

            <Paper component="aside" variant="outlined" sx={{ p: 2.5, height: "fit-content", minWidth: 280 }}>
              <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>Selected Call Context</Typography>
              {selectedCall ? (
                <Stack spacing={1.5}>
                  <Box sx={{ display: "flex", alignItems: "center", gap: 1 }}>
                    <Box component="span" sx={{ color: "text.secondary", typography: "body2" }}>Status:</Box>
                    <StatusBadge label={selectedCall.status} />
                  </Box>
                  
                  {/* Detailed Connection Alerts */}
                  {selectedCall.status === "busy" && (
                    <Box sx={{ p: 1, borderRadius: 1, bgcolor: "error.lighter", border: "1px solid", borderColor: "error.light" }}>
                      <Typography variant="caption" color="error.dark" sx={{ fontWeight: "bold", display: "block" }}>
                        🚫 Line Busy
                      </Typography>
                    </Box>
                  )}
                  {selectedCall.status === "no_answer" && (
                    <Box sx={{ p: 1, borderRadius: 1, bgcolor: "warning.lighter", border: "1px solid", borderColor: "warning.light" }}>
                      <Typography variant="caption" color="warning.dark" sx={{ fontWeight: "bold", display: "block" }}>
                        ⏳ No Answer
                      </Typography>
                    </Box>
                  )}
                  {selectedCall.status === "rejected" && (
                    <Box sx={{ p: 1, borderRadius: 1, bgcolor: "error.lighter", border: "1px solid", borderColor: "error.light" }}>
                      <Typography variant="caption" color="error.dark" sx={{ fontWeight: "bold", display: "block" }}>
                        🛑 Call Rejected
                      </Typography>
                    </Box>
                  )}
                  {selectedCall.status === "failed" && (
                    <Box sx={{ p: 1, borderRadius: 1, bgcolor: "error.lighter", border: "1px solid", borderColor: "error.light" }}>
                      <Typography variant="caption" color="error.dark" sx={{ fontWeight: "bold", display: "block" }}>
                        ⚠️ Failed: {selectedCall.failure_reason || "Connection issue"}
                      </Typography>
                    </Box>
                  )}

                  {/* Key Press & Qualification Box in Sidebar */}
                  {selectedCall.metadata?.digits_pressed && (
                    <Box sx={{ 
                      p: 1.25, 
                      borderRadius: 1, 
                      bgcolor: selectedCall.metadata.digits_pressed === "1" ? "success.lighter" : "grey.100", 
                      border: "1px solid", 
                      borderColor: selectedCall.metadata.digits_pressed === "1" ? "success.light" : "grey.300" 
                    }}>
                      <Typography variant="caption" color={selectedCall.metadata.digits_pressed === "1" ? "success.dark" : "text.primary"} sx={{ fontWeight: "bold", display: "block" }}>
                        {selectedCall.metadata.digits_pressed === "1" ? "⭐ Lead Qualified (Interested)" : `⌨️ Pressed Key: ${selectedCall.metadata.digits_pressed}`}
                      </Typography>
                      <Typography variant="caption" color="text.secondary" sx={{ display: "block", mt: 0.5, fontSize: "0.7rem" }}>
                        {selectedCall.metadata.digits_pressed === "1" ? "Customer pressed 1. Lead marked as Qualified." : `Customer pressed key ${selectedCall.metadata.digits_pressed}.`}
                      </Typography>
                    </Box>
                  )}

                  <Typography variant="body2"><Box component="span" sx={{ color: "text.secondary", fontWeight: 500 }}>From:</Box> {selectedCall.from_number}</Typography>
                  <Typography variant="body2"><Box component="span" sx={{ color: "text.secondary", fontWeight: 500 }}>To:</Box> {selectedCall.to_number}</Typography>
                  <Typography variant="body2"><Box component="span" sx={{ color: "text.secondary", fontWeight: 500 }}>Provider:</Box> {selectedCall.provider?.label ?? "N/A"}</Typography>
                  
                  <Typography variant="body2">
                    <Box component="span" sx={{ color: "text.secondary", fontWeight: 500 }}>Duration:</Box>{" "}
                    {selectedCall.duration_seconds !== null && selectedCall.duration_seconds !== undefined
                      ? `${selectedCall.duration_seconds}s`
                      : "0s"}
                  </Typography>

                  <Typography variant="body2">
                    <Box component="span" sx={{ color: "text.secondary", fontWeight: 500 }}>Started:</Box>{" "}
                    {selectedCall.started_at ? new Date(selectedCall.started_at).toLocaleString() : "N/A"}
                  </Typography>
                  <Typography variant="body2">
                    <Box component="span" sx={{ color: "text.secondary", fontWeight: 500 }}>Ended:</Box>{" "}
                    {selectedCall.ended_at ? new Date(selectedCall.ended_at).toLocaleString() : "N/A"}
                  </Typography>

                  <MuiButton component={Link} href={`/calls/${selectedCall.id}`} variant="outlined" color="primary" fullWidth sx={{ mt: 1 }}>
                    Open Full Detail
                  </MuiButton>
                </Stack>
              ) : (
                <Typography variant="body2" color="text.secondary">Select a call row to view detail context.</Typography>
              )}
            </Paper>
          </Box>

          {message ? <ErrorState message={message} className="mt-3 text-sm text-rose-700" /> : null}

          <Box sx={{ mt: 2, display: "flex", alignItems: "center", gap: 1 }}>
            <MuiButton
              type="button"
              variant="outlined"
              color="inherit"
              disabled={page <= 1 || loadingHistory}
              onClick={() => void loadHistory(page - 1)}
            >
              Previous
            </MuiButton>
            <Typography variant="body2">
              Page {page} of {lastPage}
            </Typography>
            <MuiButton
              type="button"
              variant="outlined"
              color="inherit"
              disabled={page >= lastPage || loadingHistory}
              onClick={() => void loadHistory(page + 1)}
            >
              Next
            </MuiButton>
          </Box>
        </SectionCard>
      </Box>
    </AppShell>
  );
}
