"use client";

import {
  AppBar,
  Badge,
  Box,
  Divider,
  IconButton,
  InputAdornment,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Menu,
  MenuItem,
  OutlinedInput,
  TextField,
  Toolbar,
  Typography,
} from "@mui/material";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { apiRequest } from "@/lib/api";
import { useNotifications } from "@/hooks/useNotifications";
import {
  clearSession,
  getSessionStorageState,
  saveSession,
} from "@/lib/auth-session";

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
  const { unreadCount } = useNotifications();
  const [searchFocused, setSearchFocused] = useState(false);
  const [mobileSearchOpen, setMobileSearchOpen] = useState(false);
  const [menuAnchor, setMenuAnchor] = useState<null | HTMLElement>(null);
  const [loggingOut, setLoggingOut] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [profileLoading, setProfileLoading] = useState(false);
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileMessage, setProfileMessage] = useState("");
  const [profile, setProfile] = useState({
    first_name: "",
    last_name: "",
    email: "",
    current_password: "",
    password: "",
    password_confirmation: "",
  });

  const menuOpen = Boolean(menuAnchor);

  const wantsSensitiveUpdate = useMemo(() => {
    const password = profile.password.trim();
    const email = profile.email.trim();
    return password !== "" || email !== "";
  }, [profile.email, profile.password]);

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

  useEffect(() => {
    if (!profileOpen) {
      return;
    }

    let cancelled = false;
    const load = async () => {
      setProfileLoading(true);
      setProfileMessage("");
      try {
        const { token, tenantId } = getSessionStorageState();
        const response = await apiRequest<{ data: { first_name: string; last_name: string; email: string } }>(
          "/auth/me",
          { token, tenantId }
        );
        if (cancelled) return;
        setProfile((prev) => ({
          ...prev,
          first_name: response.data.first_name ?? "",
          last_name: response.data.last_name ?? "",
          email: response.data.email ?? "",
          current_password: "",
          password: "",
          password_confirmation: "",
        }));
      } catch (e) {
        if (cancelled) return;
        setProfileMessage(e instanceof Error ? e.message : "Failed to load profile.");
      } finally {
        if (!cancelled) setProfileLoading(false);
      }
    };

    void load();
    return () => {
      cancelled = true;
    };
  }, [profileOpen]);

  async function saveProfile(): Promise<void> {
    if (profileSaving) return;
    setProfileSaving(true);
    setProfileMessage("");
    try {
      const { token, tenantId } = getSessionStorageState();
      const payload: Record<string, unknown> = {
        first_name: profile.first_name,
        last_name: profile.last_name,
        email: profile.email,
      };

      const password = profile.password.trim();
      if (password !== "") {
        payload.current_password = profile.current_password;
        payload.password = password;
        payload.password_confirmation = profile.password_confirmation;
      }

      const response = await apiRequest<{ data: { user: { email: string; first_name: string; last_name: string }; token?: string | null } }>(
        "/auth/me",
        { method: "PATCH", token, tenantId, body: payload }
      );

      const newToken = response.data.token;
      if (newToken) {
        saveSession(newToken, tenantId);
      }

      setProfileOpen(false);
      setMenuAnchor(null);
    } catch (e) {
      setProfileMessage(e instanceof Error ? e.message : "Profile update failed.");
    } finally {
      setProfileSaving(false);
    }
  }

  return (
    <AppBar
      position="sticky"
      color="default"
      elevation={0}
      sx={{
        backgroundColor: "transparent",
        backgroundImage: "none",
        borderBottom: "none",
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
            alignItems: "center",
            minHeight: "64px !important",
            px: { xs: 2.5, sm: 3.5, md: 4 },
            py: 1,
            gap: { xs: 1, sm: 2 },
            flexWrap: "nowrap",
            bgcolor: mode === "dark" ? "rgba(30, 41, 59, 0.85)" : "rgba(255, 255, 255, 0.9)",
            backdropFilter: "blur(16px)",
            borderRadius: { xs: 0, md: "12px" },
            border: "1px solid",
            borderColor: mode === "dark" ? "rgba(255, 255, 255, 0.08)" : "rgba(226, 232, 240, 0.8)",
            boxShadow:
              mode === "dark"
                ? "0 4px 20px rgba(0, 0, 0, 0.3)"
                : "0 4px 20px rgba(161, 172, 184, 0.12)",
          }}
        >
          {/* Mobile Overlay Search Bar (when mobileSearchOpen is true) */}
          {mobileSearchOpen ? (
            <Box
              sx={{
                display: { xs: "flex", md: "none" },
                alignItems: "center",
                gap: 1,
                width: "100%",
                px: 0.5,
              }}
            >
              <OutlinedInput
                autoFocus
                placeholder="Search..."
                startAdornment={
                  <InputAdornment position="start">
                    <i className="bx bx-search" style={{ color: "#6366f1", fontSize: "1.25rem" }} />
                  </InputAdornment>
                }
                sx={{
                  flex: 1,
                  height: 40,
                  borderRadius: "10px",
                  fontSize: "0.875rem",
                  bgcolor: mode === "dark" ? "rgba(15, 23, 42, 0.6)" : "#f8fafc",
                }}
              />
              <IconButton size="small" onClick={() => setMobileSearchOpen(false)}>
                <i className="bx bx-x" style={{ fontSize: "1.5rem", color: "#64748b" }} />
              </IconButton>
            </Box>
          ) : (
            <>
              {/* Left section: Menu toggle + Desktop Search + Mobile Title */}
              <Box
                sx={{
                  display: "flex",
                  alignItems: "center",
                  gap: { xs: 1, sm: 2 },
                  flex: 1,
                  minWidth: 0,
                }}
              >
                <IconButton
                  edge="start"
                  onClick={onMenuClick}
                  sx={{
                    display: { md: "none" },
                    color: "#64748b",
                    ml: { xs: 0.5, sm: 1 },
                    p: { xs: 0.75, sm: 1 },
                    "&:hover": { color: "#6366f1", bgcolor: "rgba(99, 102, 241, 0.08)" },
                  }}
                >
                  <i className="bx bx-menu" style={{ fontSize: "1.5rem" }} />
                </IconButton>

                {/* Mobile Logo Title */}
                <Box
                  sx={{
                    display: { xs: "flex", md: "none" },
                    alignItems: "center",
                    gap: 1,
                  }}
                >
                  <img src="/favicon.svg" alt="Logo" style={{ width: 26, height: 26, borderRadius: 6 }} />
                  <Typography variant="subtitle2" sx={{ fontWeight: 800, color: "#1e293b", fontSize: "0.9rem" }}>
                    WND<span style={{ color: "#6366f1" }}>Dialer</span>
                  </Typography>
                </Box>

                {/* Desktop Search Bar */}
                <OutlinedInput
                  placeholder="Search (Ctrl+/)"
                  startAdornment={
                    <InputAdornment position="start">
                      <i
                        className="bx bx-search"
                        style={{
                          color: "#64748b",
                          fontSize: "1.25rem",
                        }}
                      />
                    </InputAdornment>
                  }
                  endAdornment={
                    <InputAdornment position="end">
                      <Box
                        sx={{
                          px: 0.75,
                          py: 0.25,
                          borderRadius: "4px",
                          bgcolor: mode === "dark" ? "rgba(255,255,255,0.08)" : "#f1f5f9",
                          color: "#94a3b8",
                          fontSize: "0.7rem",
                          fontWeight: 700,
                          border: "1px solid",
                          borderColor: mode === "dark" ? "rgba(255,255,255,0.12)" : "#cbd5e1",
                        }}
                      >
                        ⌘K
                      </Box>
                    </InputAdornment>
                  }
                  onFocus={() => setSearchFocused(true)}
                  onBlur={() => setSearchFocused(false)}
                  sx={{
                    display: { xs: "none", md: "flex" },
                    width: { sm: 260, md: 320 },
                    height: 38,
                    borderRadius: "10px",
                    fontSize: "0.875rem",
                    bgcolor: mode === "dark" ? "rgba(15, 23, 42, 0.4)" : "#f8fafc",
                    "& .MuiOutlinedInput-input": {
                      py: 0.75,
                      px: 1,
                      "&::placeholder": {
                        color: "#94a3b8",
                        opacity: 1,
                      },
                    },
                    "& .MuiOutlinedInput-notchedOutline": {
                      borderColor: searchFocused ? "#6366f1" : (mode === "dark" ? "rgba(255, 255, 255, 0.12)" : "#e2e8f0"),
                    },
                    "&:hover .MuiOutlinedInput-notchedOutline": {
                      borderColor: searchFocused ? "#6366f1" : (mode === "dark" ? "rgba(255, 255, 255, 0.2)" : "#cbd5e1"),
                    },
                  }}
                />
              </Box>

              {/* Right section: Actions */}
              <Box
                sx={{
                  display: "flex",
                  alignItems: "center",
                  gap: { xs: 0.5, sm: 1 },
                }}
              >
                {/* Mobile Search Icon Button */}
                <IconButton
                  onClick={() => setMobileSearchOpen(true)}
                  aria-label="Open mobile search"
                  sx={{
                    display: { xs: "flex", md: "none" },
                    color: "#64748b",
                    p: { xs: 0.75, sm: 1 },
                    "&:hover": { color: "#6366f1", bgcolor: "rgba(99, 102, 241, 0.08)" },
                  }}
                >
                  <i className="bx bx-search" style={{ fontSize: "1.35rem" }} />
                </IconButton>

                {/* Dark Mode Toggle */}
                <IconButton
                  onClick={onToggleMode}
                  aria-label="Toggle dark mode"
                  sx={{
                    color: "#64748b",
                    p: { xs: 0.75, sm: 1 },
                    "&:hover": {
                      color: "#6366f1",
                      bgcolor: mode === "dark" ? "rgba(255, 255, 255, 0.05)" : "rgba(99, 102, 241, 0.08)",
                    },
                  }}
                >
                  <i
                    className={mode === "dark" ? "bx bx-sun" : "bx bx-moon"}
                    style={{ fontSize: "1.35rem" }}
                  />
                </IconButton>

                {/* Notification bell */}
                <IconButton
                  onClick={() => router.push("/notifications")}
                  sx={{
                    color: "#64748b",
                    position: "relative",
                    p: { xs: 0.75, sm: 1 },
                    "&:hover": {
                      color: "#6366f1",
                      bgcolor: mode === "dark" ? "rgba(255, 255, 255, 0.05)" : "rgba(99, 102, 241, 0.08)",
                    },
                    ...(unreadCount > 0 ? {
                      animation: "bellPulse 2s ease-in-out infinite",
                      "@keyframes bellPulse": {
                        "0%, 100%": { transform: "scale(1)" },
                        "50%": { transform: "scale(1.1)" },
                      },
                    } : {}),
                  }}
                >
                  <Badge
                    badgeContent={unreadCount}
                    color="error"
                    max={99}
                    sx={{
                      "& .MuiBadge-badge": {
                        fontSize: "0.65rem",
                        minWidth: 18,
                        height: 18,
                        fontWeight: 700,
                      },
                    }}
                  >
                    <i className="bx bx-bell" style={{ fontSize: "1.35rem" }} />
                  </Badge>
                </IconButton>

                {/* User avatar */}
                <Box
                  sx={{
                    ml: { xs: 0.5, sm: 1 },
                    width: { xs: 34, sm: 40 },
                    height: { xs: 34, sm: 40 },
                    borderRadius: "50%",
                    background: "linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)",
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    color: "#fff",
                    fontSize: { xs: "0.8rem", sm: "0.875rem" },
                    fontWeight: 700,
                    cursor: "pointer",
                    boxShadow: "0 2px 8px rgba(99, 102, 241, 0.3)",
                    transition: "transform 0.2s, box-shadow 0.2s",
                    "&:hover": {
                      transform: "scale(1.05)",
                      boxShadow: "0 4px 14px rgba(99, 102, 241, 0.45)",
                    },
                  }}
                  onClick={(event) => setMenuAnchor(event.currentTarget)}
                >
                  {tenantName ? tenantName.charAt(0).toUpperCase() : "U"}
                </Box>
              </Box>
            </>
          )}

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
              <MenuItem
                onClick={() => {
                  setMenuAnchor(null);
                  setProfileOpen(true);
                }}
              >
                Edit Profile
              </MenuItem>
              <MenuItem onClick={logout} disabled={loggingOut}>
                {loggingOut ? "Logging out..." : "Logout"}
              </MenuItem>
            </Menu>
        </Toolbar>
      </Box>

      <Dialog
        open={profileOpen}
        onClose={() => setProfileOpen(false)}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>Edit Profile</DialogTitle>
        <DialogContent sx={{ pt: 1, display: "flex", flexDirection: "column", gap: 2 }}>
          <TextField
            label="First Name"
            value={profile.first_name}
            onChange={(e) => setProfile((p) => ({ ...p, first_name: e.target.value }))}
            disabled={profileLoading || profileSaving}
            fullWidth
          />
          <TextField
            label="Last Name"
            value={profile.last_name}
            onChange={(e) => setProfile((p) => ({ ...p, last_name: e.target.value }))}
            disabled={profileLoading || profileSaving}
            fullWidth
          />
          <TextField
            label="Email"
            value={profile.email}
            onChange={(e) => setProfile((p) => ({ ...p, email: e.target.value }))}
            disabled={profileLoading || profileSaving}
            fullWidth
          />
          <Divider />
          <TextField
            label="Current Password"
            type="password"
            value={profile.current_password}
            onChange={(e) => setProfile((p) => ({ ...p, current_password: e.target.value }))}
            disabled={profileLoading || profileSaving}
            fullWidth
            required={wantsSensitiveUpdate}
          />
          <TextField
            label="New Password"
            type="password"
            value={profile.password}
            onChange={(e) => setProfile((p) => ({ ...p, password: e.target.value }))}
            disabled={profileLoading || profileSaving}
            fullWidth
          />
          <TextField
            label="Confirm New Password"
            type="password"
            value={profile.password_confirmation}
            onChange={(e) => setProfile((p) => ({ ...p, password_confirmation: e.target.value }))}
            disabled={profileLoading || profileSaving}
            fullWidth
          />
          {profileMessage ? (
            <Box sx={{ color: "error.main", fontSize: "0.875rem" }}>{profileMessage}</Box>
          ) : null}
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <IconButton onClick={() => setProfileOpen(false)} disabled={profileSaving}>
            <i className="bx bx-x" style={{ fontSize: "1.25rem" }} />
          </IconButton>
          <Box sx={{ flex: 1 }} />
          <IconButton onClick={saveProfile} disabled={profileSaving || profileLoading}>
            <i className="bx bx-save" style={{ fontSize: "1.25rem" }} />
          </IconButton>
        </DialogActions>
      </Dialog>
    </AppBar>
  );
}
