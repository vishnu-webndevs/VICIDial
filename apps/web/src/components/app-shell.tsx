"use client";

import {
  Alert,
  Box,
  Chip,
  CircularProgress,
  Container,
  Typography,
} from "@/ui";
import { usePathname, useRouter } from "next/navigation";
import { PropsWithChildren, useEffect, useMemo, useState } from "react";
import { DashboardLayout } from "@/ui";
import { fetchSessionProfile } from "@/lib/product-api";
import { LoadingGate } from "@/components/loading-state";
import { ApiError } from "@/lib/api";
import {
  clearSession,
  getSessionStorageState,
  syncTenantFromProfile,
} from "@/lib/auth-session";
import { getCachedSession, getStaleSessionCache, setSessionCache } from "@/lib/session-cache";

type AppShellProps = PropsWithChildren<{
  requiredPermissions?: string[];
  requiredRoles?: string[];
}>;

export function AppShell({
  children,
  requiredPermissions = [],
  requiredRoles = [],
}: AppShellProps) {
  const router = useRouter();
  const pathname = usePathname();
  const [mounted, setMounted] = useState(false);
  const [loading, setLoading] = useState(true);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [isPlatformAdmin, setIsPlatformAdmin] = useState(false);
  const [role, setRole] = useState("");
  const [tenantName, setTenantName] = useState("");

  useEffect(() => {
    setMounted(true);
  }, []);

  useEffect(() => {
    const { token } = getSessionStorageState();
    if (!token) {
      router.replace("/login");
      return;
    }

    let cancelled = false;
    const applyProfile = (profile: Awaited<ReturnType<typeof fetchSessionProfile>>) => {
      if (cancelled) {
        return;
      }
      setPermissions(profile.permissions ?? []);
      setIsPlatformAdmin(Boolean(profile.is_platform_admin));
      setRole(profile.role?.slug ?? "");
      setTenantName(profile.current_tenant?.name ?? "");
      syncTenantFromProfile(profile);
    };

    const hydrateSession = async (redirectOnError: boolean) => {
      try {
        const profile = await fetchSessionProfile();
        setSessionCache(profile);
        applyProfile(profile);
      } catch (error) {
        if (cancelled) {
          return;
        }

        const isAuthError = error instanceof ApiError && error.status === 401;

        if (isAuthError) {
          clearSession();
          if (redirectOnError) {
            router.replace("/login");
          }
        } else {
          console.error("Session hydration failed (non-auth error):", error);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    const cached = getCachedSession();
    if (cached) {
      applyProfile(cached);
      setLoading(false);
      return () => {
        cancelled = true;
      };
    }

    const stale = getStaleSessionCache();
    if (stale) {
      applyProfile(stale);
      setLoading(false);
      void hydrateSession(true);
      return () => {
        cancelled = true;
      };
    }

    void hydrateSession(true);
    return () => {
      cancelled = true;
    };
  }, [router]);

  // Enforce role-based area separation once the session is loaded.
  useEffect(() => {
    if (loading) return;

    const isSuperAdmin =
      isPlatformAdmin ||
      role === "platform_super_admin" ||
      role === "super_admin";

    const onSuperAdminPath = pathname.startsWith("/super-admin");

    if (isSuperAdmin && !onSuperAdminPath) {
      // Super admins always land in /super-admin
      router.replace("/super-admin");
    } else if (!isSuperAdmin && onSuperAdminPath) {
      // Regular users cannot enter /super-admin
      router.replace("/dashboard");
    }
  }, [loading, isPlatformAdmin, role, pathname, router]);

  const can = useMemo(() => {
    if (isPlatformAdmin) {
      return () => true;
    }
    const set = new Set(permissions);
    return (permission?: string) => !permission || set.has(permission);
  }, [isPlatformAdmin, permissions]);

  const hasRoleAccess =
    requiredRoles.length === 0 || isPlatformAdmin || requiredRoles.includes(role);
  const hasPermissionAccess = requiredPermissions.every((permission) => can(permission));
  const hasRouteAccess = hasRoleAccess && hasPermissionAccess;

  const renderedContent = !hasRouteAccess ? (
    <Box sx={{ minHeight: "100vh", bgcolor: "background.default", py: 10 }}>
      <Container maxWidth="lg">
        <Alert severity="error" sx={{ maxWidth: 640 }}>
          <Typography variant="subtitle2">Access denied</Typography>
          You do not have permission to access this module.
        </Alert>
      </Container>
    </Box>
  ) : (
    <DashboardLayout tenantName={tenantName} role={role}>
      <Box sx={{ mx: "auto", width: "100%", maxWidth: 1480 }}>{children}</Box>
    </DashboardLayout>
  );

  return (
    <LoadingGate isLoading={!mounted || loading} label="Loading session..." minDurationMs={0}>
      {mounted ? renderedContent : null}
    </LoadingGate>
  );
}

export function SectionCard({
  title,
  subtitle,
  children,
}: PropsWithChildren<{ title: string; subtitle?: string }>) {
  return (
    <Box
      component="section"
      sx={{
        borderRadius: "0.375rem",
        bgcolor: "#fff",
        p: { xs: 3, md: 4 },
        boxShadow: "0 2px 6px rgba(67, 89, 113, 0.12)",
      }}
    >
      <Box sx={{ mb: 3 }}>
        <Typography
          variant="h6"
          sx={{ fontWeight: 500, color: "#566a7f" }}
        >
          {title}
        </Typography>
        {subtitle ? (
          <Typography
            variant="body2"
            sx={{ mt: 0.75, color: "#697a8d" }}
          >
            {subtitle}
          </Typography>
        ) : null}
      </Box>
      {children}
    </Box>
  );
}

export function StatusBadge({ label, color: overrideColor }: { label: string; color?: "default" | "primary" | "secondary" | "error" | "info" | "success" | "warning" }) {
  const normalized = label.toLowerCase();
  const color = overrideColor || (
    normalized === "completed" || normalized === "connected" || normalized === "active" || normalized === "approved"
      ? "success"
      : normalized === "failed" || normalized === "error" || normalized === "rejected"
      ? "error"
      : normalized === "ringing" || normalized === "queued" || normalized === "scheduled" || normalized === "pending"
      ? "warning"
      : "default"
  );

  return <Chip component="span" label={label} color={color} size="medium" />;
}

type LoadingStateProps = {
  label?: string;
  className?: string;
};

export function LoadingState({ label = "Loading..." }: LoadingStateProps) {
  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: 1.5, py: 1 }}>
      <CircularProgress size={18} />
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
    </Box>
  );
}

type ErrorStateProps = {
  message: string;
  className?: string;
};

export function ErrorState({ message }: ErrorStateProps) {
  return <Alert severity="error">{message}</Alert>;
}

type EmptyStateProps = {
  title?: string;
  description: string;
  className?: string;
};

export function EmptyState({
  title = "No data",
  description,
}: EmptyStateProps) {
  return (
    <Alert severity="info" variant="outlined">
      <Typography variant="subtitle2">{title}</Typography>
      <Typography variant="body2">{description}</Typography>
    </Alert>
  );
}
