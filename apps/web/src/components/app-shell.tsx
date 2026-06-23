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
import { isOnboardingComplete } from "@/lib/onboarding";
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
  const [tenantId, setTenantId] = useState<string | null>(null);
  const [trialExpired, setTrialExpired] = useState(false);

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
      setTenantId(profile.current_tenant?.id ?? null);
      setTrialExpired(Boolean(profile.trial_expired));
      syncTenantFromProfile(profile);
    };

    const hydrateSession = async (redirectOnError: boolean) => {
      const MAX_RETRIES = 3;
      let lastError: unknown = null;

      try {
        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
          if (cancelled) return;
          try {
            const profile = await fetchSessionProfile();
            setSessionCache(profile);
            applyProfile(profile);
            return; // success — exit early (finally will run)
          } catch (error) {
            lastError = error;
            if (cancelled) return;

            const isAuthError =
              (error instanceof ApiError ||
                (error && typeof error === "object" && "status" in error)) &&
              [401, 403].includes((error as { status: number }).status);

            if (isAuthError) {
              clearSession();
              if (redirectOnError) {
                router.replace("/login");
              }
              return; // auth errors are definitive — don't retry
            }

            // Transient network error — retry with backoff
            if (attempt < MAX_RETRIES) {
              const delayMs = 500 * Math.pow(2, attempt); // 500, 1000, 2000
              await new Promise((r) => setTimeout(r, delayMs));
            }
          }
        }

        // All retries exhausted
        if (!cancelled) {
          console.error("Session hydration failed after retries:", lastError);
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

  // Enforce role-based area separation and onboarding gate once the session is loaded.
  useEffect(() => {
    if (loading) return;

    const isSuperAdmin =
      isPlatformAdmin ||
      role === "platform_super_admin" ||
      role === "super_admin";

    const onSuperAdminPath = pathname.startsWith("/super-admin");

    // Check plan/trial expiration first for non-platform/super admins
    if (!isSuperAdmin && tenantId && trialExpired) {
      if (pathname !== "/billing") {
        router.replace("/billing");
        return;
      }
      return;
    }

    // Enforce onboarding check for standard tenant users
    if (!isSuperAdmin && tenantId) {
      const onboardingDone = isOnboardingComplete(tenantId);
      if (!onboardingDone && pathname !== "/onboarding") {
        router.replace("/onboarding");
        return;
      }
    }

    if (isSuperAdmin && !onSuperAdminPath) {
      // Super admins always land in /super-admin
      router.replace("/super-admin");
    } else if (!isSuperAdmin && onSuperAdminPath) {
      // Regular users cannot enter /super-admin
      router.replace("/dashboard");
    }
  }, [loading, isPlatformAdmin, role, tenantId, pathname, router, trialExpired]);

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
  const normalized = label.replace(/[_-]/g, " ").toLowerCase();
  const color = overrideColor || (
    normalized === "completed" || normalized === "connected" || normalized === "active" || normalized === "approved"
      ? "success"
      : normalized === "failed" || normalized === "error" || normalized === "rejected" || normalized === "busy"
      ? "error"
      : normalized === "ringing" || normalized === "queued" || normalized === "scheduled" || normalized === "pending" || normalized === "no answer" || normalized === "timeout"
      ? "warning"
      : "default"
  );

  const displayLabel = label
    .replace(/[_-]/g, " ")
    .split(" ")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");

  return <Chip component="span" label={displayLabel} color={color} size="medium" />;
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
