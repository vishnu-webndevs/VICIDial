"use client";

import { Box } from "@/ui";
import { ReactNode, useState } from "react";
import { Navbar } from "@/ui/Navigation/Navbar";
import { Sidebar } from "@/ui/Navigation/Sidebar";

const MODE_KEY = "wnd_ui_mode";

export function DashboardLayout({
  tenantName,
  role,
  children,
}: {
  tenantName: string;
  role?: string;
  children: ReactNode;
}) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [mode, setMode] = useState<"light" | "dark">(() => {
    if (typeof window === "undefined") {
      return "light";
    }
    const stored = localStorage.getItem(MODE_KEY);
    return stored === "dark" ? "dark" : "light";
  });

  const toggleMode = () => {
    setMode((prev) => {
      const next = prev === "light" ? "dark" : "light";
      localStorage.setItem(MODE_KEY, next);
      window.dispatchEvent(new Event("wnd-ui-mode-change"));
      return next;
    });
  };

  return (
    <Box
      sx={{
        minHeight: "100vh",
        display: "flex",
        bgcolor: "#f5f5f9",
      }}
    >
      <Sidebar
        open={sidebarOpen}
        role={role ?? ""}
        onClose={() => setSidebarOpen(false)}
      />
      <Box
        sx={{
          minWidth: 0,
          flex: 1,
          display: "flex",
          flexDirection: "column",
          bgcolor: "#f5f5f9",
        }}
      >
        <Navbar
          tenantName={tenantName}
          onMenuClick={() => setSidebarOpen((prev) => !prev)}
          mode={mode}
          onToggleMode={toggleMode}
        />
        <Box
          component="main"
          sx={{
            px: { xs: 3, md: 4 },
            py: { xs: 3, md: 4 },
            flex: 1,
            overflow: "auto",
          }}
        >
          {children}
        </Box>
        {/* Sneat Footer */}
        <Box
          component="footer"
          sx={{
            py: 2.5,
            px: { xs: 3, md: 4 },
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            flexWrap: "wrap",
            gap: 1,
            color: "#697a8d",
            fontSize: "0.8125rem",
          }}
        >
          <Box>
            &copy; {new Date().getFullYear()} WND Dialer
          </Box>
        </Box>
      </Box>
    </Box>
  );
}
