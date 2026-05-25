"use client";

import { useEffect, useState } from "react";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { Box, Button, MuiButton, Paper, Stack, Typography } from "@/ui";
import { useRouter } from "next/navigation";

type Notification = {
  id: string;
  title: string;
  message: string;
  type: string;
  read_at: string | null;
  created_at: string;
  metadata?: {
    thread_id?: string;
    message_id?: string;
    channel?: string;
    from?: string;
  } | null;
};

export default function NotificationsPage() {
  const router = useRouter();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");

  async function loadNotifications(unreadOnly = false) {
    setLoading(true);
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<{ data: Notification[] }>(
        `/notifications?per_page=50${unreadOnly ? "&unread_only=1" : ""}`,
        { token, tenantId }
      );
      setNotifications(response.data ?? []);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load notifications.");
    } finally {
      setLoading(false);
    }
  }

  async function markRead(id: string) {
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest(`/notifications/${id}/read`, {
        method: "PATCH",
        token,
        tenantId,
      });
      await loadNotifications(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to mark notification as read.");
    }
  }

  async function handleNotificationClick(item: Notification) {
    if (!item.read_at) {
      await markRead(item.id);
    }
    const threadId = item.metadata?.thread_id;
    const channel = item.metadata?.channel;
    if (threadId && channel) {
      router.push(`/conversations?thread_id=${threadId}&channel=${channel}`);
    }
  }

  async function markAllAsRead() {
    setLoading(true);
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest(`/notifications/read-all`, {
        method: "PATCH",
        token,
        tenantId,
      });
      await loadNotifications(false);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to mark all notifications as read.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadNotifications(false);
  }, []);

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      <SectionCard title="Notification Center" subtitle="View tenant activity notifications and mark read.">
        <Stack direction="row" spacing={1.5} justifyContent="space-between" alignItems="center">
          <Stack direction="row" spacing={1.5}>
            <MuiButton
              type="button"
              variant="outlined"
              onClick={() => void loadNotifications(false)}
              disabled={loading}
            >
              All
            </MuiButton>
            <MuiButton
              type="button"
              variant="outlined"
              onClick={() => void loadNotifications(true)}
              disabled={loading}
            >
              Unread
            </MuiButton>
          </Stack>
          {notifications.some((n) => !n.read_at) && (
            <MuiButton
              type="button"
              variant="contained"
              color="primary"
              onClick={() => void markAllAsRead()}
              disabled={loading}
            >
              Mark all as read
            </MuiButton>
          )}
        </Stack>
        <Stack spacing={2} sx={{ mt: 3 }}>
          {loading ? <LoadingState label="Loading notifications..." /> : null}
          {notifications.map((item) => (
            <Paper 
              key={item.id} 
              variant="outlined" 
              onClick={() => void handleNotificationClick(item)}
              sx={{ 
                p: 3, 
                cursor: 'pointer',
                transition: 'all 0.2s ease-in-out',
                bgcolor: item.read_at ? '#ffffff' : 'rgba(105, 108, 255, 0.03)',
                '&:hover': {
                  borderColor: 'rgba(105, 108, 255, 0.4)',
                  boxShadow: '0 2px 12px rgba(105, 108, 255, 0.08)',
                  bgcolor: item.read_at ? 'rgba(105, 108, 255, 0.01)' : 'rgba(105, 108, 255, 0.05)',
                }
              }}
            >
              <Stack direction="row" justifyContent="space-between" alignItems="flex-start" spacing={2}>
                <Typography variant="subtitle2">{item.title}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {new Date(item.created_at).toLocaleString()}
                </Typography>
              </Stack>
              <Typography variant="body2" color="text.secondary" sx={{ mt: 1.25 }}>
                {item.message}
              </Typography>
              <Stack direction="row" spacing={1.5} alignItems="center" sx={{ mt: 2 }}>
                <Typography variant="caption" color="text.secondary">{item.type}</Typography>
                <Typography variant="caption" color="text.secondary">{item.read_at ? "Read" : "Unread"}</Typography>
                {!item.read_at ? (
                  <Button
                    type="button"
                    size="medium"
                    onClick={(e) => {
                      e.stopPropagation();
                      void markRead(item.id);
                    }}
                  >
                    Mark Read
                  </Button>
                ) : null}
              </Stack>
            </Paper>
          ))}
          {notifications.length === 0 ? (
            <EmptyState title="No notifications" description="No notifications are available for this tenant." />
          ) : null}
        </Stack>
        {message ? <Box sx={{ mt: 2 }}><ErrorState message={message} /></Box> : null}
      </SectionCard>
    </AppShell>
  );
}
