"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { apiRequest } from "@/lib/api";
import { getSessionStorageState } from "@/lib/auth-session";
import { playNotificationSoundDebounced } from "@/lib/notificationSound";

type NotificationItem = {
  id: string;
  title: string;
  message: string;
  type: string;
  read_at: string | null;
  created_at: string;
  metadata?: Record<string, unknown>;
};

type NotificationState = {
  unreadCount: number;
  recentNotifications: NotificationItem[];
  loading: boolean;
};

const POLL_INTERVAL_MS = 15_000; // 15 seconds

export function useNotifications() {
  const [state, setState] = useState<NotificationState>({
    unreadCount: 0,
    recentNotifications: [],
    loading: false,
  });

  const prevUnreadCountRef = useRef(0);
  const mountedRef = useRef(true);

  const fetchUnread = useCallback(async () => {
    try {
      const { token, tenantId } = getSessionStorageState();
      if (!token) return;

      const response = await apiRequest<{
        data: NotificationItem[];
        meta?: {
          unread_count?: number;
          pagination?: { total?: number };
        };
      }>("/notifications?unread_only=1&per_page=5", { token, tenantId });

      if (!mountedRef.current) return;

      const unreadCount =
        response.meta?.unread_count ??
        response.meta?.pagination?.total ??
        response.data.length;

      // Play sound if unread count increased (i.e. new notification arrived)
      if (unreadCount > prevUnreadCountRef.current && prevUnreadCountRef.current >= 0) {
        playNotificationSoundDebounced();
      }
      prevUnreadCountRef.current = unreadCount;

      setState({
        unreadCount,
        recentNotifications: response.data ?? [],
        loading: false,
      });
    } catch {
      // Silently ignore — polling errors shouldn't disrupt the UI
    }
  }, []);

  const markAsRead = useCallback(async (id: string) => {
    try {
      const { token, tenantId } = getSessionStorageState();
      if (!token) return;

      await apiRequest(`/notifications/${id}/read`, {
        method: "PATCH",
        token,
        tenantId,
      });

      // Refresh after marking read
      void fetchUnread();
    } catch {
      // ignore
    }
  }, [fetchUnread]);

  useEffect(() => {
    mountedRef.current = true;

    // Initial fetch (skip sound on first load by setting prevCount to -1 temporarily)
    prevUnreadCountRef.current = -1;
    void fetchUnread().then(() => {
      // After first fetch, set prevCount to current so next delta triggers sound
      prevUnreadCountRef.current = state.unreadCount;
    });

    const interval = setInterval(fetchUnread, POLL_INTERVAL_MS);

    return () => {
      mountedRef.current = false;
      clearInterval(interval);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fetchUnread]);

  return {
    unreadCount: state.unreadCount,
    recentNotifications: state.recentNotifications,
    loading: state.loading,
    markAsRead,
    refresh: fetchUnread,
  };
}
