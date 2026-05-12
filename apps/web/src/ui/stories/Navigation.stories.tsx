"use client";

import { Navbar, Sidebar } from "@/ui";

const meta = {
  title: "UI/Navigation",
};

export default meta;

export const SidebarDesktop = {
  render: () => <Sidebar open={false} role="admin" onClose={() => {}} />,
};

export const SidebarMobileDrawer = {
  render: () => <Sidebar open role="admin" onClose={() => {}} />,
};

export const NavbarDefault = {
  render: () => (
    <Navbar tenantName="Acme Tenant" mode="light" onMenuClick={() => {}} onToggleMode={() => {}} />
  ),
};
