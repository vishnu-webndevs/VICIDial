"use client";

import { useState } from "react";
import { Button, Card, Modal } from "@/ui";

const meta = {
  title: "UI/Surfaces",
};

export default meta;

function ModalExample() {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button onClick={() => setOpen(true)}>Open Modal</Button>
      <Modal open={open} onClose={() => setOpen(false)} title="Modal Title">
        Modal body content
      </Modal>
    </>
  );
}

export const CardDefault = {
  render: () => <Card title="Card Title">Card body content</Card>,
};

export const ModalStates = {
  render: () => <ModalExample />,
};
