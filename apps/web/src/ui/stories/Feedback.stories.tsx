"use client";

import { useState } from "react";
import { Button, Snackbar } from "@/ui";

const meta = {
  title: "UI/Feedback/Snackbar",
};

export default meta;

function SnackbarExample() {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button onClick={() => setOpen(true)}>Show Snackbar</Button>
      <Snackbar open={open} onClose={() => setOpen(false)} severity="success" message="Saved successfully" />
    </>
  );
}

export const Success = {
  render: () => <SnackbarExample />,
};
