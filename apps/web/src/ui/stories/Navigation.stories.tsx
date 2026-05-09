"use client";

import { Navbar, Sidebar } from "@/ui";

const meta = {
  title: "UI/Navigation",
};

export default meta;

export const SidebarDesktop = {
  render: () => <Sidebar open={false} onClose={() => {}} />,
};

export const SidebarMobileDrawer = {
  render: () => <Sidebar open onClose={() => {}} />,
};

export const NavbarDefault = {
  render: () => (
    <Navbar tenantName="Acme Tenant" mode="light" onMenuClick={() => {}} onToggleMode={() => {}} />
  ),
};
