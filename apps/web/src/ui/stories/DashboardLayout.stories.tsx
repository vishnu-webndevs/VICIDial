"use client";

import { DashboardLayout } from "@/ui";

const meta = {
  title: "UI/Layout/DashboardLayout",
  component: DashboardLayout,
};

export default meta;

export const Default = {
  args: {
    tenantName: "Acme Tenant",
    children: <div>Dashboard content area</div>,
  },
};
