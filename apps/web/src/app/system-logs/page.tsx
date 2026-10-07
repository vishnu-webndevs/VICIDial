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
              p: 2.5, 
              bgcolor: "#1b1d22", 
              color: "#e2e8f0", 
              fontFamily: "'Fira Code', 'Consolas', 'Courier New', monospace", 
              minHeight: "75vh",
              height: "calc(100vh - 180px)", 
              overflowY: "auto",
              whiteSpace: "pre-wrap",
              wordBreak: "break-word",
              fontSize: "0.875rem",
              lineHeight: 1.6,
              borderRadius: 2,
              border: "1px solid",
              borderColor: "rgba(255, 255, 255, 0.1)",
              boxShadow: "0 8px 32px rgba(0, 0, 0, 0.2)",
              "&::-webkit-scrollbar": {
                width: 10,
              },
              "&::-webkit-scrollbar-track": {
                bgcolor: "#121316",
              },
              "&::-webkit-scrollbar-thumb": {
                bgcolor: "#334155",
                borderRadius: 4,
                "&:hover": { bgcolor: "#475569" },
              },
            }}
          >
            {loading && !logs ? (
              <Box sx={{ display: "flex", justifyContent: "center", alignItems: "center", minHeight: "60vh" }}>
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
