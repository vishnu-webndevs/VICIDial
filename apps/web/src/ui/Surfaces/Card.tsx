"use client";

import { Card as MuiCard, CardContent, CardHeader } from "@mui/material";
import { ReactNode } from "react";

export function Card({
  title,
  subtitle,
  children,
}: {
  title?: ReactNode;
  subtitle?: ReactNode;
  children: ReactNode;
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
      {title ? (
        <CardHeader
          title={title}
          subheader={subtitle}
          titleTypographyProps={{
            variant: "h5",
            fontWeight: 500,
            color: "#566a7f",
            fontSize: "1.125rem",
          }}
          subheaderTypographyProps={{
            variant: "body2",
            color: "#697a8d",
          }}
        />
      ) : null}
      <CardContent>{children}</CardContent>
    </MuiCard>
  );
}
