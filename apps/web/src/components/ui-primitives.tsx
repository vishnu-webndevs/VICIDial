"use client";

import {
  Alert,
  Box,
  MuiButton,
  MuiCard,
  CardContent,
  Chip as MuiChipBase,
  Skeleton,
  Typography,
} from "@/ui";
import { PropsWithChildren } from "react";

type ButtonVariant = "primary" | "secondary" | "danger" | "ghost";

export function UiButton({
  variant = "secondary",
  className = "",
  children,
  ...props
}: PropsWithChildren<{
  variant?: ButtonVariant;
  className?: string;
} & React.ButtonHTMLAttributes<HTMLButtonElement>>) {
  const muiVariant: React.ComponentProps<typeof MuiButton>["variant"] = variant === "ghost" ? "text" : "contained";
  const muiColor: React.ComponentProps<typeof MuiButton>["color"] =
    variant === "danger" ? "error" : variant === "secondary" ? "secondary" : "primary";
  return (
    <MuiButton
      {...props}
      variant={muiVariant}
      color={muiColor}
      className={className}
      size="medium"
    >
      {children}
    </MuiButton>
  );
}

export function KpiCard({
  label,
  value,
  hint,
}: {
  label: string;
  value: string | number;
  hint?: string;
}) {
  return (
    <MuiCard
      elevation={0}
      sx={{
        boxShadow: "0 2px 6px rgba(67, 89, 113, 0.12)",
        borderRadius: "0.375rem",
        border: "none",
      }}
    >
      <CardContent>
        <Typography
          variant="caption"
          sx={{
            textTransform: "uppercase",
            fontWeight: 600,
            color: "#697a8d",
            letterSpacing: "0.4px",
          }}
        >
          {label}
        </Typography>
        <Typography
          variant="h4"
          sx={{ mt: 1, color: "#566a7f", fontWeight: 500 }}
        >
          {value}
        </Typography>
        {hint ? (
          <Typography
            variant="body2"
            sx={{ mt: 1.5, color: "#a1acb8" }}
          >
            {hint}
          </Typography>
        ) : null}
      </CardContent>
    </MuiCard>
  );
}

export function Chip({
  active,
  onClick,
  children,
}: PropsWithChildren<{ active?: boolean; onClick?: () => void }>) {
  return (
    <MuiChipBase
      component="button"
      clickable
      onClick={onClick}
      color={active ? "primary" : "default"}
      label={children}
      size="medium"
      sx={{ borderRadius: 999 }}
    />
  );
}

export function ToastMessage({
  tone = "neutral",
  title,
  message,
}: {
  tone?: "neutral" | "success" | "error";
  title?: string;
  message: string;
}) {
  return (
    <Alert severity={tone === "success" ? "success" : tone === "error" ? "error" : "info"} variant="outlined">
      {title ? <Typography variant="subtitle2">{title}</Typography> : null}
      <Typography variant="body2">{message}</Typography>
    </Alert>
  );
}

export function SkeletonBlock({ className = "h-24 w-full" }: { className?: string }) {
  return (
    <Box className={className}>
      <Skeleton variant="rounded" height="100%" width="100%" />
    </Box>
  );
}

export function SkeletonLines({ rows = 4 }: { rows?: number }) {
  return (
    <Box sx={{ display: "grid", gap: 1 }}>
      {Array.from({ length: rows }).map((_, index) => (
        <Skeleton
          key={`line-${index}`}
          variant="rounded"
          height={12}
          width={index === rows - 1 ? "66%" : "100%"}
        />
      ))}
    </Box>
  );
}

export function EmptyPanel({ title, description }: { title: string; description: string }) {
  return (
    <Alert severity="info" variant="outlined">
      <Typography variant="subtitle2">{title}</Typography>
      <Typography variant="body2">{description}</Typography>
    </Alert>
  );
}
