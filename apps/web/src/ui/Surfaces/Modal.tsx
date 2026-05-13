"use client";

import { Box, Modal as MuiModal, Typography } from "@mui/material";
import { ReactNode } from "react";
import IconButton from "@mui/material/IconButton";

export function Modal({
  open,
  onClose,
  title,
  children,
}: {
  open: boolean;
  onClose: () => void;
  title?: string;
  children: ReactNode;
}) {
  return (
    <MuiModal open={open} onClose={onClose}>
      <Box
        sx={{
          position: "absolute",
          top: "50%",
          left: "50%",
          transform: "translate(-50%, -50%)",
          maxWidth: { xs: "calc(100% - 1.5rem)", sm: 560, md: 720 },
          width: "calc(100% - 3rem)",
          maxHeight: "calc(100vh - 3rem)",
          p: { xs: 2.5, sm: 3.5, md: 4 },
          borderRadius: "0.375rem",
          bgcolor: "background.paper",
          boxShadow: "0 0.25rem 1rem rgba(161, 172, 184, 0.45)",
          outline: "none",
          overflowY: "auto",
        }}
      >
        <IconButton
          aria-label="Close"
          onClick={onClose}
          sx={{
            position: "absolute",
            top: { xs: 10, sm: 14 },
            right: { xs: 10, sm: 14 },
            color: "#697a8d",
          }}
        >
          <i className="bx bx-x" style={{ fontSize: "1.6rem" }} />
        </IconButton>

        {title ? (
          <Typography
            variant="h5"
            sx={{
              mb: 3,
              pr: 5,
              fontWeight: 500,
              color: "#566a7f",
            }}
          >
            {title}
          </Typography>
        ) : null}
        {children}
      </Box>
    </MuiModal>
  );
}
