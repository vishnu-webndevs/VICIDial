"use client";

import {
  AppBar,
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
} from "@mui/material";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { apiRequest } from "@/lib/api";
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
  const [searchFocused, setSearchFocused] = useState(false);
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
          </Box>
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
