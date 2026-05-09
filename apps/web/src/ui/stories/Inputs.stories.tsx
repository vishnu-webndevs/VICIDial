"use client";

import { useState } from "react";
import { Button, DatePicker, FormSelect, FormTextField } from "@/ui";

const meta = {
  title: "UI/Inputs",
};

export default meta;

function DatePickerExample() {
  const [value, setValue] = useState<string | null>(new Date().toISOString());
  return <DatePicker label="Follow-up Date" value={value} onChange={setValue} />;
}

export const TextField = {
  render: () => <FormTextField label="Full Name" placeholder="Enter full name" />,
};

export const Select = {
  render: () => (
    <FormSelect
      label="Status"
      defaultValue="new"
      options={[
        { label: "New", value: "new" },
        { label: "Contacted", value: "contacted" },
      ]}
    />
  ),
};

export const DatePickerField = {
  render: () => <DatePickerExample />,
};

export const ButtonPrimary = {
  render: () => <Button>Primary Action</Button>,
};
