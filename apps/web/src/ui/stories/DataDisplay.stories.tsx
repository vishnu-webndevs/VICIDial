"use client";

import { DataTable } from "@/ui";

const rows = [
  { id: "1", name: "Emma Carter", status: "qualified" },
  { id: "2", name: "Liam Ortiz", status: "contacted" },
  { id: "3", name: "Noah Bennett", status: "new" },
];

const meta = {
  title: "UI/DataDisplay/DataTable",
  component: DataTable,
};

export default meta;

export const Default = {
  render: () => (
    <DataTable
      rows={rows}
      rowKey={(row) => row.id}
      columns={[
        { key: "name", label: "Lead" },
        { key: "status", label: "Status" },
      ]}
    />
  ),
};
