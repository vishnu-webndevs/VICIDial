"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Box, MenuItem, MuiButton, Paper, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from "@/ui";
import { AppShell, LoadingState, SectionCard } from "@/components/app-shell";
import { EmptyPanel, ToastMessage } from "@/components/ui-primitives";
import { attachLeadsToList, createLeadList, detachLeadsFromList, getLeadImportJob, importLeadsFromFile, listLeadLists, listLeads } from "@/lib/product-api";
import type { Lead, LeadList, LeadImportStatus } from "@/types/product";

export default function ListsPage() {
  const [lists, setLists] = useState<LeadList[]>([]);
  const [leads, setLeads] = useState<Lead[]>([]);
  const [selectedListId, setSelectedListId] = useState("");
  const [selectedLeadIds, setSelectedLeadIds] = useState<string[]>([]);
  const [mode, setMode] = useState<"add" | "remove">("add");
  const [listName, setListName] = useState("");
  const [listDescription, setListDescription] = useState("");
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");
  const [importState, setImportState] = useState<LeadImportStatus | null>(null);
  const [importing, setImporting] = useState(false);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [listData, leadData] = await Promise.all([
        listLeadLists(),
        mode === "remove" && selectedListId ? listLeads({ listId: selectedListId }) : listLeads(),
      ]);
      setLists(listData);
      setLeads(leadData);
      if (!selectedListId && listData.length > 0) {
        setSelectedListId(listData[0].id);
      }
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to load lists data.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }, [mode, selectedListId]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  // Sync selectedLeadIds with leads currently in the selected list
  useEffect(() => {
    if (selectedListId && leads.length > 0) {
      if (mode === "remove") {
        setSelectedLeadIds(leads.map((l) => l.id));
      } else {
        const initiallyAttached = leads
          .filter((lead) => lead.lists?.some((l) => l.id === selectedListId))
          .map((lead) => lead.id);
        setSelectedLeadIds(initiallyAttached);
      }
    } else {
      setSelectedLeadIds([]);
    }
  }, [selectedListId, leads, mode]);

  const initialAttachedIds = useMemo(() => {
    if (!selectedListId || leads.length === 0) return [];
    if (mode === "remove") {
      return leads.map((l) => l.id);
    }
    return leads
      .filter((lead) => lead.lists?.some((l) => l.id === selectedListId))
      .map((lead) => lead.id);
  }, [leads, selectedListId, mode]);

  const toAttach = useMemo(() => {
    return selectedLeadIds.filter((id) => !initialAttachedIds.includes(id));
  }, [selectedLeadIds, initialAttachedIds]);

  const toDetach = useMemo(() => {
    return initialAttachedIds.filter((id) => !selectedLeadIds.includes(id));
  }, [selectedLeadIds, initialAttachedIds]);

  const hasChanges = toAttach.length > 0 || toDetach.length > 0;

  async function onCreateList(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    try {
      await createLeadList({ name: listName.trim(), description: listDescription.trim() || undefined });
      setListName("");
      setListDescription("");
      setMessage("Lead list created.");
      setMessageTone("success");
      await loadData();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to create lead list.");
      setMessageTone("error");
    }
  }

  async function onSaveListLeads() {
    if (!selectedListId) return;
    setMessage("");
    try {
      let attachMsg = "";
      let detachMsg = "";

      if (toAttach.length > 0) {
        const response = await attachLeadsToList(selectedListId, toAttach);
        attachMsg = `${response.attached_count} leads attached`;
      }
      if (toDetach.length > 0) {
        const response = await detachLeadsFromList(selectedListId, toDetach);
        detachMsg = `${response.detached_count} leads removed`;
      }

      const msg = [attachMsg, detachMsg].filter(Boolean).join(" and ") + ".";
      setMessage(msg || "No changes saved.");
      setMessageTone("success");
      await loadData();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed to save lead list changes.");
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

    setImporting(true);
    setMessage("");
    try {
      const createdJob = await importLeadsFromFile(file);
      let current = await getLeadImportJob(createdJob.job_id);
      setImportState(current);
      let pollCount = 0;
      while (["queued", "processing"].includes(current.status) && pollCount < 120) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
        current = await getLeadImportJob(createdJob.job_id);
        setImportState(current);
        pollCount += 1;
      }
      if (current.status === "completed") {
        setMessage(`Import completed: ${current.successful_rows} success, ${current.failed_rows} failed.`);
        setMessageTone("success");
        event.currentTarget.reset();
        await loadData();
      } else {
        setMessage("Import is still processing or failed. Review status panel.");
        setMessageTone("neutral");
      }
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "CSV import failed.");
      setMessageTone("error");
    } finally {
      setImporting(false);
    }
  }

  const selectedList = lists.find((item) => item.id === selectedListId) ?? null;
  const visibleLeads = useMemo(() => leads.slice(0, 150), [leads]);

  return (
    <AppShell requiredPermissions={["call.view"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}
      <Box sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", xl: "1fr 1.7fr" } }}>
        <SectionCard title="Lead Lists" subtitle="Create and manage list containers for campaign assignment.">
          <Box component="form" onSubmit={onCreateList} sx={{ display: "grid", gap: 1.25 }}>
            <TextField
              required
              size="medium"
              value={listName}
              onChange={(event) => setListName(event.target.value)}
              placeholder="List name"
            />
            <TextField
              size="medium"
              value={listDescription}
              onChange={(event) => setListDescription(event.target.value)}
              placeholder="Description"
            />
            <MuiButton type="submit" variant="contained">Create List</MuiButton>
          </Box>

          <TextField
            select
            size="medium"
            value={selectedListId}
            onChange={(event) => setSelectedListId(event.target.value)}
            sx={{ mt: 1.5, width: "100%" }}
          >
            <MenuItem value="">Select list</MenuItem>
            {lists.map((item) => (
              <MenuItem key={item.id} value={item.id}>
                {item.name} ({item.leads_count ?? 0})
              </MenuItem>
            ))}
          </TextField>

          {selectedList ? (
            <Paper variant="outlined" sx={{ mt: 1.5, p: 1.5, bgcolor: "action.hover" }}>
              <Typography variant="body2" sx={{ fontWeight: 600 }}>{selectedList.name}</Typography>
              <Typography variant="caption" color="text.secondary">{selectedList.description || "No description"}</Typography>
            </Paper>
          ) : null}

          <Box component="form" onSubmit={handleImport} sx={{ mt: 1.5, display: "grid", gap: 1 }}>
            <Typography variant="subtitle2">Bulk Import CSV</Typography>
            <Box component="input" name="csv_file" type="file" accept=".csv,text/csv" />
            <MuiButton type="submit" variant="outlined" disabled={importing}>
              {importing ? "Importing..." : "Import Leads"}
            </MuiButton>
          </Box>
          {importState ? (
            <Typography variant="caption" color="text.secondary" sx={{ mt: 1 }}>
              Import status: {importState.status} ({importState.processed_rows}/{importState.total_rows})
            </Typography>
          ) : null}
        </SectionCard>

        <SectionCard
          title="Manage List Leads"
          subtitle={mode === "remove" ? "Remove leads from selected list." : "Attach existing leads into selected lead list."}
        >
          {loading ? (
            <LoadingState label="Loading lists and leads..." />
          ) : !selectedListId ? (
            <EmptyPanel title="Select a list first" description="Choose a lead list to add or remove leads." />
          ) : (
            <>
              <Stack direction="row" spacing={1} sx={{ mb: 1.5 }}>
                <MuiButton
                  variant={mode === "add" ? "contained" : "outlined"}
                  onClick={() => {
                    setSelectedLeadIds([]);
                    setMode("add");
                  }}
                >
                  Add Leads
                </MuiButton>
                <MuiButton
                  variant={mode === "remove" ? "contained" : "outlined"}
                  onClick={() => {
                    setSelectedLeadIds([]);
                    setMode("remove");
                  }}
                >
                  Remove Leads
                </MuiButton>
              </Stack>

              {visibleLeads.length === 0 ? (
                <EmptyPanel
                  title={mode === "remove" ? "No leads in this list" : "No leads available"}
                  description={mode === "remove" ? "This lead list has no leads attached yet." : "Create or import leads first to populate list membership."}
                />
              ) : (
                <>
                  <Paper variant="outlined" sx={{ overflowX: "auto" }}>
                    <Table size="medium" sx={{ minWidth: 920 }}>
                      <TableHead>
                        <TableRow sx={{ bgcolor: "action.hover" }}>
                          <TableCell sx={{ width: 40 }} />
                          <TableCell>Name</TableCell>
                          <TableCell>Phone</TableCell>
                          <TableCell>Status</TableCell>
                          <TableCell>Owner</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {visibleLeads.map((lead) => {
                          const selected = selectedLeadIds.includes(lead.id);
                          return (
                            <TableRow key={lead.id} hover onClick={() => {
                              setSelectedLeadIds((prev) => selected ? prev.filter((id) => id !== lead.id) : [...prev, lead.id]);
                            }} sx={{ cursor: "pointer", bgcolor: selected ? "action.selected" : "inherit" }}>
                              <TableCell>
                                <Box
                                  sx={{
                                    width: 18,
                                    height: 18,
                                    borderRadius: 0.5,
                                    border: 2,
                                    borderColor: selected ? "primary.main" : "divider",
                                    bgcolor: selected ? "primary.main" : "transparent",
                                    display: "grid",
                                    placeItems: "center",
                                    color: "#fff",
                                    fontSize: "0.7rem",
                                    fontWeight: 700,
                                  }}
                                >
                                  {selected ? "✓" : ""}
                                </Box>
                              </TableCell>
                              <TableCell>{lead.full_name}</TableCell>
                              <TableCell>{lead.phone}</TableCell>
                              <TableCell>{lead.status}</TableCell>
                              <TableCell>{lead.owner_agent}</TableCell>
                            </TableRow>
                          );
                        })}
                      </TableBody>
                    </Table>
                  </Paper>
                  <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
                    <MuiButton
                      variant="contained"
                      color={toDetach.length > 0 && toAttach.length === 0 ? "error" : "primary"}
                      onClick={() => void onSaveListLeads()}
                      disabled={!selectedListId || !hasChanges}
                    >
                      {toAttach.length > 0 && toDetach.length > 0
                        ? `Save Changes (Attach ${toAttach.length}, Remove ${toDetach.length})`
                        : toAttach.length > 0
                        ? `Attach ${toAttach.length} Lead${toAttach.length > 1 ? "s" : ""}`
                        : toDetach.length > 0
                        ? `Remove ${toDetach.length} Lead${toDetach.length > 1 ? "s" : ""}`
                        : "Save Changes"}
                    </MuiButton>
                    <MuiButton
                      variant="outlined"
                      onClick={() => setSelectedLeadIds(initialAttachedIds)}
                      disabled={!hasChanges}
                    >
                      Clear
                    </MuiButton>
                  </Stack>
                </>
              )}
            </>
          )}
        </SectionCard>
      </Box>
    </AppShell>
  );
}
