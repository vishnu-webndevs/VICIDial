"use client";

import {
  AppBar,
  Box,
  Divider,
  IconButton,
  InputAdornment,
  Menu,
  MenuItem,
  OutlinedInput,
  Toolbar,
} from "@mui/material";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { apiRequest } from "@/lib/api";
import { clearSession, getSessionStorageState } from "@/lib/auth-session";

export function Navbar({
  tenantName,
  onMenuClick,
  mode,
  onToggleMode,
}: {
  tenantName: string;
  onMenuClick: () => void;
  mode: "light" | "dark";
  onToggleMode: () => void;
}) {
  const router = useRouter();
  const [searchFocused, setSearchFocused] = useState(false);
  const [menuAnchor, setMenuAnchor] = useState<null | HTMLElement>(null);
  const [loggingOut, setLoggingOut] = useState(false);

  const menuOpen = Boolean(menuAnchor);

  async function logout(): Promise<void> {
    if (loggingOut) return;
    setLoggingOut(true);
    try {
      const { token, tenantId } = getSessionStorageState();
      if (token) {
        await apiRequest("/auth/logout", { method: "POST", token, tenantId });
      }
    } catch {
    } finally {
      clearSession();
      setMenuAnchor(null);
      setLoggingOut(false);
      router.replace("/login");
    }
  }

  return (
    <AppBar
      position="sticky"
      color="default"
      elevation={0}
      sx={{
        backgroundColor: "rgba(245, 245, 249, 0.95)",
        borderBottom: "none",
        backdropFilter: "blur(10px)",
        boxShadow: "none",
        top: 0,
        zIndex: 9,
      }}
    >
      <Box
        sx={{
          mx: { xs: 0, md: 3 },
          mt: { xs: 0, md: 1.5 },
        }}
      >
        <Toolbar
          sx={{
            justifyContent: "space-between",
            minHeight: "64px !important",
            px: { xs: 3, sm: 4 },
            gap: 2,
            flexWrap: { xs: "wrap", sm: "nowrap" },
            bgcolor: "background.paper",
            borderRadius: { xs: 0, md: "0.375rem" },
            boxShadow:
              "0 0.25rem 0.5rem rgba(161, 172, 184, 0.12)",
          }}
        >
          {/* Left section: Menu toggle + Search */}
          <Box
            sx={{
              display: "flex",
              alignItems: "center",
              gap: 2,
              flex: 1,
              minWidth: 0,
              flexBasis: { xs: "100%", sm: "auto" },
            }}
          >
            <IconButton
              edge="start"
              onClick={onMenuClick}
              sx={{
                display: { lg: "none" },
                color: "#697a8d",
              }}
            >
              <i className="bx bx-menu" style={{ fontSize: "1.5rem" }} />
            </IconButton>

            {/* Search Bar */}
            <OutlinedInput
              placeholder="Search (Ctrl+/)"
              startAdornment={
                <InputAdornment position="start">
                  <i
                    className="bx bx-search"
                    style={{
                      color: "#697a8d",
                      fontSize: "1.375rem",
                    }}
                  />
                </InputAdornment>
              }
              onFocus={() => setSearchFocused(true)}
              onBlur={() => setSearchFocused(false)}
              sx={{
                width: { xs: "100%", sm: 280, md: 320 },
                height: 38,
                borderRadius: "0.375rem",
                fontSize: "0.9375rem",
                "& .MuiOutlinedInput-input": {
                  py: 1,
                  "&::placeholder": {
                    color: "#a1acb8",
                    opacity: 1,
                  },
                },
                "& .MuiOutlinedInput-notchedOutline": {
                  borderColor: searchFocused ? "#696cff" : "#d9dee3",
                },
                "&:hover .MuiOutlinedInput-notchedOutline": {
                  borderColor: searchFocused ? "#696cff" : "#c7cdd4",
                },
              }}
            />
          </Box>

          {/* Right section: Actions */}
          <Box
            sx={{
              display: "flex",
              alignItems: "center",
              gap: 1,
              flexBasis: { xs: "100%", sm: "auto" },
              justifyContent: { xs: "flex-end", sm: "flex-start" },
            }}
          >
            {/* Dark Mode Toggle */}
            <IconButton
              onClick={onToggleMode}
              aria-label="Toggle dark mode"
              sx={{
                color: "#697a8d",
                "&:hover": {
                  bgcolor: "rgba(67, 89, 113, 0.04)",
                },
              }}
            >
              <i
                className={mode === "dark" ? "bx bx-sun" : "bx bx-moon"}
                style={{ fontSize: "1.375rem" }}
              />
            </IconButton>

            {/* Notification bell */}
            <IconButton
              sx={{
                color: "#697a8d",
                "&:hover": {
                  bgcolor: "rgba(67, 89, 113, 0.04)",
                },
              }}
            >
              <i className="bx bx-bell" style={{ fontSize: "1.375rem" }} />
            </IconButton>

            {/* User avatar */}
            <Box
              sx={{
                ml: 1,
                width: 40,
                height: 40,
                borderRadius: "50%",
                bgcolor: "#696cff",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                color: "#fff",
                fontSize: "0.875rem",
                fontWeight: 600,
                cursor: "pointer",
              }}
              onClick={(event) => setMenuAnchor(event.currentTarget)}
            >
              {tenantName ? tenantName.charAt(0).toUpperCase() : "U"}
            </Box>

            <Menu
              anchorEl={menuAnchor}
              open={menuOpen}
              onClose={() => setMenuAnchor(null)}
              anchorOrigin={{ vertical: "bottom", horizontal: "right" }}
              transformOrigin={{ vertical: "top", horizontal: "right" }}
              PaperProps={{ sx: { minWidth: 200 } }}
            >
              <MenuItem disabled>{tenantName || "Account"}</MenuItem>
              <Divider />
              <MenuItem onClick={logout} disabled={loggingOut}>
                {loggingOut ? "Logging out..." : "Logout"}
              </MenuItem>
            </Menu>
          </Box>
        </Toolbar>
      </Box>
    </AppBar>
  );
}
