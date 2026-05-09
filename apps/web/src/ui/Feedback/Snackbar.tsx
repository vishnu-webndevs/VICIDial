"use client";

import { Alert, Snackbar as MuiSnackbar } from "@mui/material";

export function Snackbar({
  open,
  onClose,
  message,
  severity = "info",
}: {
  open: boolean;
  onClose: () => void;
  message: string;
  severity?: "success" | "error" | "warning" | "info";
}) {
  return (
    <MuiSnackbar open={open} autoHideDuration={3000} onClose={onClose}>
      <Alert severity={severity} onClose={onClose} variant="filled">
        {message}
      </Alert>
    </MuiSnackbar>
  );
}
