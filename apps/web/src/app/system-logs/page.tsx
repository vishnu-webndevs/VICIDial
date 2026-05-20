"use client";

import { useEffect, useState } from "react";
import { Box, Button, CircularProgress, Typography, Paper } from "@mui/material";
import { AppShell } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

export default function SystemLogsPage() {
  const [logs, setLogs] = useState<string>("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const fetchLogs = async () => {
    setLoading(true);
    setError("");
    try {
      const { token, tenantId } = getTenantContext();
      const data = await apiRequest<{ logs: string }>("/system/logs", {
        token,
        tenantId,
      });
      setLogs(data.logs || "No logs available.");
    } catch (err: any) {
      setError(err.message || "An error occurred while fetching logs.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs();
  }, []);

  return (
    <AppShell>
      <Box sx={{ p: { xs: 2, md: 4 }, maxWidth: 1200, mx: "auto" }}>
        <Box sx={{ display: "flex", justifyContent: "space-between", mb: 3, alignItems: "center" }}>
          <Typography variant="h4" fontWeight="bold">
            System Logs
          </Typography>
          <Button 
            variant="contained" 
            onClick={fetchLogs} 
            disabled={loading}
            startIcon={loading ? <CircularProgress size={20} color="inherit" /> : <i className="bx bx-refresh" />}
          >
            Refresh Logs
          </Button>
        </Box>

        {error ? (
          <Paper sx={{ p: 3, bgcolor: "#ffebee", color: "#c62828" }}>
            <Typography>{error}</Typography>
          </Paper>
        ) : (
          <Paper 
            sx={{ 
              p: 2, 
              bgcolor: "#1e1e1e", 
              color: "#d4d4d4", 
              fontFamily: "monospace", 
              height: "calc(100vh - 250px)", 
              overflowY: "auto",
              whiteSpace: "pre-wrap",
              fontSize: "0.875rem"
            }}
          >
            {loading && !logs ? (
              <Box sx={{ display: "flex", justifyContent: "center", alignItems: "center", height: "100%" }}>
                <CircularProgress color="inherit" />
              </Box>
            ) : (
              logs
            )}
          </Paper>
        )}
      </Box>
    </AppShell>
  );
}
