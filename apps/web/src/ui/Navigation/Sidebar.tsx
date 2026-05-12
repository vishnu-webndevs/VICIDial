"use client";

import {
  Box,
  Drawer,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Typography,
} from "@mui/material";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useMemo } from "react";

const SIDEBAR_WIDTH = 260;

type NavItem = {
  href: string;
  label: string;
  icon: string;
};

type NavGroup = { group: string; items: NavItem[] };

const superAdminNavGroups: NavGroup[] = [
  {
    group: "Platform",
    items: [
      { href: "/super-admin", label: "Dashboard", icon: "bx-crown" },
      { href: "/super-admin/companies", label: "Companies", icon: "bx-building-house" },
      { href: "/super-admin/agency", label: "Agency Listings", icon: "bx-briefcase-alt-2" },
      { href: "/super-admin/plans", label: "Pricing & Packages", icon: "bx-credit-card" },
      { href: "/super-admin/settings", label: "Landing Page CMS", icon: "bx-edit-alt" },
    ],
  },
];

const adminNavGroups: NavGroup[] = [
  {
    group: "",
    items: [
      { href: "/dashboard", label: "Dashboard", icon: "bx-home-circle" },
      { href: "/command-center", label: "Command Center", icon: "bx-radar" },
    ],
  },
  {
    group: "Operations",
    items: [
      { href: "/dialer", label: "Dialer", icon: "bx-phone-call" },
      { href: "/calls", label: "Calls", icon: "bx-phone" },
      { href: "/conversations", label: "Conversations", icon: "bx-message-rounded-dots" },
      { href: "/agents", label: "Agents", icon: "bx-id-card" },
    ],
  },
  {
    group: "Growth",
    items: [
      { href: "/leads", label: "Leads", icon: "bx-user" },
      { href: "/lists", label: "Lists", icon: "bx-list-ul" },
      { href: "/campaigns", label: "Campaigns", icon: "bx-rocket" },
      { href: "/message-reports", label: "Message Reports", icon: "bx-message-square-detail" },
      { href: "/templates", label: "Templates", icon: "bx-notepad" },
    ],
  },
  {
    group: "Insights",
    items: [
      { href: "/analytics", label: "Analytics", icon: "bx-bar-chart-alt-2" },
      { href: "/reports", label: "Reports", icon: "bx-line-chart" },
    ],
  },
  {
    group: "Admin",
    items: [
      { href: "/admin", label: "Admin Dashboard", icon: "bx-layout" },
      { href: "/team", label: "Team", icon: "bx-group" },
      { href: "/user-roles", label: "User Roles", icon: "bx-shield-quarter" },
      { href: "/settings", label: "Settings", icon: "bx-buildings" },
      { href: "/settings/whatsapp-integration", label: "WhatsApp Integration", icon: "bx-chat" },
      { href: "/billing", label: "Billing", icon: "bx-credit-card" },
    ],
  },
  {
    group: "Workspace",
    items: [
      { href: "/onboarding", label: "Onboarding", icon: "bx-flag" },
      { href: "/providers", label: "Providers", icon: "bx-plug" },
      { href: "/help", label: "Help", icon: "bx-help-circle" },
    ],
  },
];

const teamNavGroups: NavGroup[] = [
  {
    group: "",
    items: [
      { href: "/dashboard", label: "Dashboard", icon: "bx-home-circle" },
      { href: "/calls", label: "Calls", icon: "bx-phone" },
      { href: "/conversations", label: "Conversations", icon: "bx-message-rounded-dots" },
      { href: "/message-reports", label: "Message Reports", icon: "bx-message-square-detail" },
    ],
  },
];

function getRoleNavGroups(role: string): NavGroup[] {
  if (role === "platform_super_admin" || role === "super_admin") {
    return superAdminNavGroups;
  }

  if (["admin", "company_owner", "company_admin", "agency"].includes(role)) {
    return adminNavGroups;
  }

  return teamNavGroups;
}

function SneatLogo() {
  return (
    <svg
      width="25"
      viewBox="0 0 25 42"
      version="1.1"
      xmlns="http://www.w3.org/2000/svg"
      xmlnsXlink="http://www.w3.org/1999/xlink"
    >
      <defs>
        <path
          d="M13.7918663,0.358365126 L3.39788168,7.44174259 C0.566865006,9.69408886 -0.379795268,12.4788597 0.557900856,15.7960551 C0.68998853,16.2305145 1.09562888,17.7872135 3.12357076,19.2293357 C3.8146334,19.7207684 5.32369333,20.3834223 7.65075054,21.2172976 L7.59773219,21.2525164 L2.63468769,24.5493413 C0.445452254,26.3002124 0.0884951797,28.5083815 1.56381646,31.1738486 C2.83770406,32.8170431 5.20850219,33.2640127 7.09180128,32.5391577 C8.347334,32.0559211 11.4559176,30.0011079 16.4175519,26.3747182 C18.0338572,24.4997857 18.6973423,22.4544883 18.4080071,20.2388261 C17.963753,17.5346866 16.1776345,15.5799961 13.0496516,14.3747546 L10.9194936,13.4715819 L18.6192054,7.984237 L13.7918663,0.358365126 Z"
          id="path-sneat-1"
        />
        <path
          d="M5.47320593,6.00457225 C4.05321814,8.216144 4.36334763,10.0722806 6.40359441,11.5729822 C8.61520715,12.571656 10.0999176,13.2171421 10.8577257,13.5094407 L15.5088241,14.433041 L18.6192054,7.984237 C15.5364148,3.11535317 13.9273018,0.573395879 13.7918663,0.358365126 C13.5790555,0.511491653 10.8061687,2.3935607 5.47320593,6.00457225 Z"
          id="path-sneat-3"
        />
        <path
          d="M7.50063644,21.2294429 L12.3234468,23.3159332 C14.1688022,24.7579751 14.397098,26.4880487 13.008334,28.506154 C11.6195701,30.5242593 10.3099883,31.790241 9.07958868,32.3040991 C5.78142938,33.4346997 4.13234973,34 4.13234973,34 C4.13234973,34 2.75489982,33.0538207 2.37032616e-14,31.1614621 C-0.55822714,27.8186216 -0.55822714,26.0572515 -4.05231404e-15,25.8773518 C0.83734071,25.6075023 2.77988457,22.8248993 3.3049379,22.52991 C3.65497346,22.3332504 5.05353963,21.8997614 7.50063644,21.2294429 Z"
          id="path-sneat-4"
        />
        <path
          d="M20.6,7.13333333 L25.6,13.8 C26.2627417,14.6836556 26.0836556,15.9372583 25.2,16.6 C24.8538077,16.8596443 24.4327404,17 24,17 L14,17 C12.8954305,17 12,16.1045695 12,15 C12,14.5672596 12.1403557,14.1461923 12.4,13.8 L17.4,7.13333333 C18.0627417,6.24967773 19.3163444,6.07059163 20.2,6.73333333 C20.3516113,6.84704183 20.4862915,6.981722 20.6,7.13333333 Z"
          id="path-sneat-5"
        />
      </defs>
      <g stroke="none" strokeWidth="1" fill="none" fillRule="evenodd">
        <g transform="translate(-27.000000, -15.000000)">
          <g transform="translate(27.000000, 15.000000)">
            <g transform="translate(0.000000, 8.000000)">
              <mask id="sneat-mask-2" fill="white">
                <use xlinkHref="#path-sneat-1" />
              </mask>
              <use fill="#696cff" xlinkHref="#path-sneat-1" />
              <g mask="url(#sneat-mask-2)">
                <use fill="#696cff" xlinkHref="#path-sneat-3" />
                <use fillOpacity="0.2" fill="#FFFFFF" xlinkHref="#path-sneat-3" />
              </g>
              <g mask="url(#sneat-mask-2)">
                <use fill="#696cff" xlinkHref="#path-sneat-4" />
                <use fillOpacity="0.2" fill="#FFFFFF" xlinkHref="#path-sneat-4" />
              </g>
            </g>
            <g transform="translate(19.000000, 11.000000) rotate(-300.000000) translate(-19.000000, -11.000000)">
              <use fill="#696cff" xlinkHref="#path-sneat-5" />
              <use fillOpacity="0.2" fill="#FFFFFF" xlinkHref="#path-sneat-5" />
            </g>
          </g>
        </g>
      </g>
    </svg>
  );
}

function SidebarContent({
  role,
  onNavigate,
}: {
  role: string;
  onNavigate?: () => void;
}) {
  const pathname = usePathname();
  const navGroups = useMemo(() => getRoleNavGroups(role), [role]);

  return (
    <Box
      sx={{
        width: "100%",
        height: "100%",
        background: "#fff",
        display: "flex",
        flexDirection: "column",
      }}
    >
      {/* App Brand */}
      <Box
        sx={{
          height: 64,
          mt: 1.5,
          px: 3,
          display: "flex",
          alignItems: "center",
          gap: 1.5,
        }}
      >
        <SneatLogo />
        <Typography
          variant="h6"
          sx={{
            fontWeight: 700,
            fontSize: "1.375rem",
            letterSpacing: -0.5,
            textTransform: "lowercase",
            color: "#566a7f",
          }}
        >
          WND Dialer
        </Typography>
      </Box>

      {/* Menu inner shadow */}
      <Box
        sx={{
          height: 12,
          background:
            "linear-gradient(rgba(67,89,113,0.04) 40%, rgba(67,89,113,0.01) 95%, transparent)",
        }}
      />

      {/* Navigation */}
      <Box
        sx={{
          flex: 1,
          overflowY: "auto",
          py: 1,
          px: 2,
          "&::-webkit-scrollbar": { width: 4 },
          "&::-webkit-scrollbar-track": { bgcolor: "transparent" },
          "&::-webkit-scrollbar-thumb": {
            bgcolor: "rgba(67,89,113,0.15)",
            borderRadius: 10,
          },
        }}
      >
        {navGroups.map((group) => (
          <Box key={group.group || "main"} sx={{ mb: 0.5 }}>
            {group.group && (
              <Typography
                variant="caption"
                sx={{
                  px: 2,
                  pt: 2.5,
                  pb: 0.5,
                  fontSize: "0.6875rem",
                  fontWeight: 700,
                  textTransform: "uppercase",
                  letterSpacing: "0.4px",
                  color: "#a1acb8",
                  display: "block",
                  position: "relative",
                  "&::before": {
                    content: '""',
                    position: "absolute",
                    left: 16,
                    top: "50%",
                    width: 16,
                    height: 1,
                    bgcolor: "#d9dee3",
                    display: { xs: "none", sm: "none" },
                  },
                }}
              >
                {group.group}
              </Typography>
            )}
            <List sx={{ p: 0 }}>
              {group.items.map((item) => {
                const active =
                  pathname === item.href ||
                  (item.href !== "/" && pathname.startsWith(item.href));
                return (
                  <ListItemButton
                    key={item.href}
                    component={Link}
                    href={item.href}
                    selected={active}
                    onClick={onNavigate}
                    sx={{
                      borderRadius: "0.375rem",
                      px: 2,
                      py: 1,
                      mb: 0.25,
                      position: "relative",
                      color: active ? "#696cff" : "#697a8d",
                      transition: "all 0.3s ease-in-out",
                      "&:hover": {
                        bgcolor: active
                          ? "rgba(105, 108, 255, 0.16)"
                          : "rgba(67, 89, 113, 0.04)",
                        color: active ? "#696cff" : "#566a7f",
                      },
                      "&.Mui-selected": {
                        bgcolor: "rgba(105, 108, 255, 0.16)",
                        color: "#696cff",
                        "&::after": {
                          content: '""',
                          position: "absolute",
                          right: 0,
                          top: "50%",
                          transform: "translateY(-50%)",
                          width: "0.25rem",
                          height: "2.5rem",
                          bgcolor: "#696cff",
                          borderRadius: "0.375rem 0 0 0.375rem",
                        },
                        "&:hover": {
                          bgcolor: "rgba(105, 108, 255, 0.16)",
                        },
                      },
                    }}
                  >
                    <ListItemIcon
                      sx={{
                        minWidth: 0,
                        mr: 1.5,
                        color: "inherit",
                        fontSize: "1.375rem",
                      }}
                    >
                      <i className={`bx ${item.icon}`} />
                    </ListItemIcon>
                    <ListItemText
                      primary={item.label}
                      primaryTypographyProps={{
                        fontSize: "0.9375rem",
                        fontWeight: active ? 600 : 400,
                        color: "inherit",
                      }}
                    />
                  </ListItemButton>
                );
              })}
            </List>
          </Box>
        ))}
      </Box>
    </Box>
  );
}

export function Sidebar({
  open,
  role,
  onClose,
}: {
  open: boolean;
  role: string;
  onClose: () => void;
}) {
  return (
    <>
      <Box
        sx={{
          display: { xs: "none", lg: "block" },
          width: SIDEBAR_WIDTH,
          flexShrink: 0,
        }}
      >
        <Box
          sx={{
            position: "fixed",
            width: SIDEBAR_WIDTH,
            height: "100vh",
            top: 0,
            left: 0,
            boxShadow: "0 0.125rem 0.375rem rgba(161, 172, 184, 0.12)",
            zIndex: 1200,
          }}
        >
          <SidebarContent role={role} />
        </Box>
      </Box>
      <Drawer
        open={open}
        onClose={onClose}
        sx={{
          display: { xs: "block", lg: "none" },
          "& .MuiDrawer-paper": {
            width: { xs: "min(84vw, 320px)", sm: SIDEBAR_WIDTH },
            boxShadow: "0 0.625rem 1.25rem rgba(161, 172, 184, 0.5)",
          },
        }}
      >
        <SidebarContent role={role} onNavigate={onClose} />
      </Drawer>
    </>
  );
}
