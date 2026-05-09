"use client";

import { Button as MuiButton, ButtonProps } from "@mui/material";

export function Button(props: ButtonProps) {
  return (
    <MuiButton
      variant="contained"
      {...props}
      sx={{
        textTransform: "none",
        boxShadow: "0 0.125rem 0.25rem rgba(105, 108, 255, 0.4)",
        borderRadius: "0.375rem",
        fontWeight: 500,
        fontSize: "0.9375rem",
        lineHeight: 1.53,
        py: 1,
        "&:hover": {
          boxShadow: "0 0.25rem 0.5rem rgba(105, 108, 255, 0.5)",
        },
        ...((props.sx as object) || {}),
      }}
    />
  );
}
