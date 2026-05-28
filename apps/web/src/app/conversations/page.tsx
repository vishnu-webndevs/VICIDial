"use client";

import { FormEvent, useCallback, useEffect, useMemo, useRef, useState, Suspense } from "react";
import { Box, MenuItem, MuiButton, Paper, TextField, Typography } from "@/ui";
import { Avatar, IconButton, Divider, Menu } from "@mui/material";
import { AppShell, LoadingState, StatusBadge } from "@/components/app-shell";
import { ToastMessage } from "@/components/ui-primitives";
import { listInboxThreads, listTeamMembers, sendInboxThreadMessage, updateInboxThread, listInboxThreadMessages, deleteInboxThread, clearInboxThreadMessages, markThreadNotificationsAsRead } from "@/lib/product-api";
import { playNotificationSoundDebounced } from "@/lib/notificationSound";
import { useSearchParams } from "next/navigation";
import type { MessageThread, TeamMember } from "@/types/product";

function ConversationsContent() {
  const searchParams = useSearchParams();
  const queryThreadId = searchParams.get("thread_id") ?? "";
  const queryChannel = searchParams.get("channel") ?? "";

  const [channel, setChannel] = useState<"sms" | "whatsapp">(() => {
    return (queryChannel === "sms" || queryChannel === "whatsapp") ? queryChannel : "whatsapp";
  });
  const [threads, setThreads] = useState<MessageThread[]>([]);
  const [teamMembers, setTeamMembers] = useState<TeamMember[]>([]);
  const [selectedThreadId, setSelectedThreadId] = useState(queryThreadId);
  const [outboundBody, setOutboundBody] = useState("");

  const [loading, setLoading] = useState(true);
  const [loadingMembers, setLoadingMembers] = useState(true);
  const [loadingMessages, setLoadingMessages] = useState(false);
  const [sending, setSending] = useState(false);

  const [messages, setMessages] = useState<any[]>([]);
  const [messagesPage, setMessagesPage] = useState(1);
  const [hasMoreMessages, setHasMoreMessages] = useState(true);
  const [loadingOlder, setLoadingOlder] = useState(false);
  const [messageToast, setMessageToast] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");

  const [menuAnchorEl, setMenuAnchorEl] = useState<null | HTMLElement>(null);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollContainerRef = useRef<HTMLDivElement>(null);
  const isInitialLoad = useRef(true);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    if (!loadingMessages) {
      scrollToBottom();
    }
  }, [messages, loadingMessages]);

  const loadThreads = useCallback(async () => {
    setLoading(true);
    try {
      const response = await listInboxThreads(channel, { per_page: 50 });
      setThreads(response.data);

      if (isInitialLoad.current && queryThreadId) {
        isInitialLoad.current = false;
        const hasQueryThread = response.data.some(t => t.id === queryThreadId);
        if (hasQueryThread) {
          setSelectedThreadId(queryThreadId);
          return;
        }
      }

      if (response.data.length > 0) {
        const currentThreadInNewList = response.data.some(t => t.id === selectedThreadId);
        if (!currentThreadInNewList) {
          setSelectedThreadId(response.data[0].id);
        }
      } else {
        setSelectedThreadId("");
      }
    } catch (err) {
      setMessageToast(err instanceof Error ? err.message : "Failed to load inbox threads.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }, [channel, queryThreadId, selectedThreadId]);

  useEffect(() => {
    setOutboundBody("");
    void loadThreads();
  }, [channel]);

  useEffect(() => {
    let mounted = true;
    void (async () => {
      setLoadingMembers(true);
      try {
        const result = await listTeamMembers({ per_page: 100 });
        if (mounted) {
          setTeamMembers(result.data ?? []);
        }
      } catch (err) {
        // ignore
      } finally {
        if (mounted) setLoadingMembers(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (!selectedThreadId) {
      setMessages([]);
      setMessagesPage(1);
      setHasMoreMessages(true);
      return;
    }
    let mounted = true;
    void (async () => {
      setLoadingMessages(true);
      setMessagesPage(1);
      try {
        const res = await listInboxThreadMessages(selectedThreadId, { per_page: 10, page: 1 });
        if (mounted) {
          setMessages(res.data);
          setHasMoreMessages(res.data.length === 10);
          scrollToBottom();
          try {
            await markThreadNotificationsAsRead(selectedThreadId);
          } catch (e) {
            // ignore
          }
        }
      } catch (err) {
        if (mounted) {
          setMessageToast(err instanceof Error ? err.message : "Failed to load messages.");
          setMessageTone("error");
        }
      } finally {
        if (mounted) setLoadingMessages(false);
      }
    })();
    return () => { mounted = false };
  }, [selectedThreadId]);

  // Silent background polling for new messages & threads list to provide real-time updates
  useEffect(() => {
    if (!selectedThreadId) {
      // If no thread is selected, we still poll threads list silently
      const threadsInterval = setInterval(async () => {
        try {
          const response = await listInboxThreads(channel, { per_page: 50 });
          setThreads(prev => {
            const prevSign = JSON.stringify(prev.map(t => ({ id: t.id, last_message_at: t.last_message_at, unread_count: t.unread_count, latest_msg_id: t.latest_message?.id, latest_msg_status: t.latest_message?.status })));
            const nextSign = JSON.stringify(response.data.map(t => ({ id: t.id, last_message_at: t.last_message_at, unread_count: t.unread_count, latest_msg_id: t.latest_message?.id, latest_msg_status: t.latest_message?.status })));
            if (prevSign !== nextSign) {
              return response.data;
            }
            return prev;
          });
        } catch (err) {
          // ignore
        }
      }, 15000);
      return () => clearInterval(threadsInterval);
    }

    const interval = setInterval(async () => {
      try {
        // Poll messages silently (always page 1 to get latest)
        const res = await listInboxThreadMessages(selectedThreadId, { per_page: 10, page: 1 });
        setMessages(prev => {
          // Only update if message list changed (e.g. new messages, or status changes)
          const prevSign = JSON.stringify(prev.map(m => ({ id: m.id, status: m.status })));
          const nextSign = JSON.stringify(res.data.map(m => ({ id: m.id, status: m.status })));
          if (prevSign !== nextSign) {
            // Check if we got a new message at the end
            const hadNewMessage = res.data.length > prev.length;
            if (hadNewMessage) {
              // Check if the new message is inbound (user reply)
              const latestMsg = res.data[res.data.length - 1];
              if (latestMsg && latestMsg.direction === 'inbound') {
                playNotificationSoundDebounced();
                setMessageToast(`New message received: ${(latestMsg.body || '').slice(0, 80)}`);
                setMessageTone('neutral');
                // Flash document title
                const originalTitle = document.title;
                document.title = '🔔 New Message!';
                setTimeout(() => { document.title = originalTitle; }, 3000);
              }
              // Mark notifications for this thread as read immediately since it is open
              void markThreadNotificationsAsRead(selectedThreadId);
              setTimeout(scrollToBottom, 100);
            }
            // We need to merge them carefully. For now, since we prepend older messages, 
            // if prev > res.data, we might be on page 2+. Just append new messages to the end.
            if (prev.length >= 10) {
              const updatedPrev = prev.map(pMsg => {
                const updatedMsg = res.data.find(nm => nm.id === pMsg.id);
                return updatedMsg ? updatedMsg : pMsg;
              });
              const newMessages = res.data.filter(nm => !prev.some(pm => pm.id === nm.id));
              return [...updatedPrev, ...newMessages];
            }
            return res.data;
          }
          return prev;
        });

        // Poll threads list
        const response = await listInboxThreads(channel, { per_page: 50 });
        setThreads(prev => {
          const sanitizedData = response.data.map(t => 
            t.id === selectedThreadId ? { ...t, unread_count: 0 } : t
          );
          const prevSign = JSON.stringify(prev.map(t => ({ id: t.id, last_message_at: t.last_message_at, status: t.status, unread_count: t.unread_count, latest_msg_id: t.latest_message?.id, latest_msg_status: t.latest_message?.status })));
          const nextSign = JSON.stringify(sanitizedData.map(t => ({ id: t.id, last_message_at: t.last_message_at, status: t.status, unread_count: t.unread_count, latest_msg_id: t.latest_message?.id, latest_msg_status: t.latest_message?.status })));
          if (prevSign !== nextSign) {
            return sanitizedData;
          }
          return prev;
        });
      } catch (err) {
        // ignore
      }
    }, 7000); // 7 seconds polling

    return () => clearInterval(interval);
  }, [selectedThreadId, channel]);

  async function onSend(event?: FormEvent<HTMLFormElement>) {
    if (event) event.preventDefault();
    if (!selectedThreadId || !outboundBody.trim()) return;
    setSending(true);
    setMessageToast("");

    // Optimistic UI update
    const tempMsg = {
      id: "temp-" + Date.now(),
      direction: "outbound",
      body: outboundBody.trim(),
      sent_at: new Date().toISOString(),
      status: "sending"
    };
    setMessages(prev => [...prev, tempMsg]);
    const bodyToSend = outboundBody.trim();
    setOutboundBody("");

    try {
      await sendInboxThreadMessage(selectedThreadId, bodyToSend);
      // Reload messages to get actual DB record
      const res = await listInboxThreadMessages(selectedThreadId, { per_page: 50 });
      setMessages(res.data);
      scrollToBottom();

      // Also reload threads to update "last message" snippet
      void loadThreads();
    } catch (err) {
      setMessageToast(err instanceof Error ? err.message : "Failed to send message.");
      setMessageTone("error");
      // Remove temp message on error
      setMessages(prev => prev.filter(m => m.id !== tempMsg.id));
      setOutboundBody(bodyToSend);
    } finally {
      setSending(false);
    }
  }

  async function handleClearChat() {
    setMenuAnchorEl(null);
    if (!selectedThreadId) return;
    if (!window.confirm("Are you sure you want to clear this chat? All messages will be deleted, but the chat will remain in your list.")) return;
    try {
      await clearInboxThreadMessages(selectedThreadId);
      setMessages([]);
      setMessageToast("Chat cleared successfully.");
      setMessageTone("success");
      void loadThreads();
    } catch (err) {
      setMessageToast(err instanceof Error ? err.message : "Failed to clear chat.");
      setMessageTone("error");
    }
  }

  async function handleDeleteChat() {
    setMenuAnchorEl(null);
    if (!selectedThreadId) return;
    if (!window.confirm("Are you sure you want to delete this chat? This action cannot be undone.")) return;
    try {
      await deleteInboxThread(selectedThreadId);
      setSelectedThreadId("");
      setMessages([]);
      setMessageToast("Chat deleted successfully.");
      setMessageTone("success");
      void loadThreads();
    } catch (err) {
      setMessageToast(err instanceof Error ? err.message : "Failed to delete chat.");
      setMessageTone("error");
    }
  }

  const selectedThread = useMemo(
    () => threads.find((item) => item.id === selectedThreadId) ?? null,
    [threads, selectedThreadId]
  );

  const loadOlderMessages = async () => {
    if (loadingOlder || !hasMoreMessages || !selectedThreadId) return;
    setLoadingOlder(true);
    
    const container = scrollContainerRef.current;
    const oldScrollHeight = container ? container.scrollHeight : 0;

    try {
      const nextPage = messagesPage + 1;
      const res = await listInboxThreadMessages(selectedThreadId, { per_page: 10, page: nextPage });
      setMessages(prev => {
        const newMessages = res.data.filter(nm => !prev.some(pm => pm.id === nm.id));
        return [...newMessages, ...prev]; // Prepend older messages
      });
      setMessagesPage(nextPage);
      setHasMoreMessages(res.data.length === 10);
      
      // Preserve scroll position
      setTimeout(() => {
        if (scrollContainerRef.current) {
          scrollContainerRef.current.scrollTop = scrollContainerRef.current.scrollHeight - oldScrollHeight;
        }
      }, 0);
    } catch (e) {
      setMessageToast("Failed to load older messages.");
      setMessageTone("error");
    } finally {
      setLoadingOlder(false);
    }
  };

  const handleScroll = (e: React.UIEvent<HTMLDivElement>) => {
    if (e.currentTarget.scrollTop === 0) {
      void loadOlderMessages();
    }
  };  return (
    <AppShell requiredPermissions={["call.view"]}>
      {messageToast ? <ToastMessage tone={messageTone} message={messageToast} /> : null}

      <Paper sx={{ display: 'flex', height: 'calc(100vh - 110px)', overflow: 'hidden', borderRadius: 3, boxShadow: '0 4px 20px rgba(0,0,0,0.05)' }}>

        {/* Left Sidebar: Threads List */}
        <Box sx={{ width: { xs: '100%', md: 380 }, display: { xs: selectedThreadId ? 'none' : 'flex', md: 'flex' }, flexDirection: 'column', borderRight: 1, borderColor: 'divider', bgcolor: '#ffffff' }}>

          {/* Header */}
          <Box sx={{ p: 2, bgcolor: '#f8fafc', display: 'flex', gap: 1, alignItems: 'center', borderBottom: '1px solid', borderColor: 'divider' }}>
            <Avatar sx={{ background: channel === 'whatsapp' ? 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)' : 'linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%)', width: 40, height: 40 }}>
              <i className={`bx ${channel === 'whatsapp' ? 'bxl-whatsapp' : 'bx-message-square-detail'}`} style={{ fontSize: 24 }} />
            </Avatar>
            <TextField
              select
              size="small"
              value={channel}
              onChange={(e) => setChannel(e.target.value as "sms" | "whatsapp")}
              sx={{ flex: 1, '& .MuiOutlinedInput-root': { bgcolor: '#fff', borderRadius: 2 } }}
            >
              <MenuItem value="sms">SMS Inbox</MenuItem>
              <MenuItem value="whatsapp">WND whatsapp</MenuItem>
            </TextField>
            <IconButton onClick={loadThreads} disabled={loading} size="small" sx={{ bgcolor: '#fff', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
              <i className="bx bx-refresh" />
            </IconButton>
          </Box>

          {/* Search Bar (Visual Only for now) */}
          <Box sx={{ p: 1.5, borderBottom: 1, borderColor: 'divider' }}>
            <TextField
              size="small"
              fullWidth
              placeholder="Search or start new chat"
              sx={{ '& .MuiOutlinedInput-root': { bgcolor: '#f8fafc', borderRadius: 2 } }}
            />
          </Box>

          {/* Chat List */}
          <Box sx={{ flex: 1, overflowY: 'auto', '&::-webkit-scrollbar': { width: '6px' }, '&::-webkit-scrollbar-thumb': { bgcolor: 'rgba(0,0,0,0.1)', borderRadius: '10px' } }}>
            {loading ? (
              <Box sx={{ p: 4, display: 'flex', justifyContent: 'center' }}><LoadingState label="Loading chats..." /></Box>
            ) : threads.length === 0 ? (
              <Box sx={{ p: 4, textAlign: 'center', color: 'text.secondary' }}>No conversations found.</Box>
            ) : (
              threads.map(thread => (
                <Box
                  key={thread.id}
                  onClick={() => {
                    setSelectedThreadId(thread.id);
                    setThreads(prev => prev.map(t => t.id === thread.id ? { ...t, unread_count: 0 } : t));
                    void markThreadNotificationsAsRead(thread.id);
                  }}
                  sx={{
                    p: 2,
                    pl: selectedThreadId === thread.id ? 1.5 : 2,
                    display: 'flex',
                    gap: 2,
                    cursor: 'pointer',
                    bgcolor: selectedThreadId === thread.id ? '#eef2ff' : 'transparent',
                    borderLeft: selectedThreadId === thread.id ? '4px solid #6366f1' : '4px solid transparent',
                    '&:hover': { bgcolor: '#f8fafc' },
                    borderBottom: '1px solid #f1f5f9',
                    transition: 'all 0.15s ease'
                  }}
                >
                  <Avatar sx={{ bgcolor: '#dfe3e8', color: '#5c6c75' }}>
                    <i className="bx bx-user" />
                  </Avatar>
                  <Box sx={{ flex: 1, overflow: 'hidden' }}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.5 }}>
                      <Typography variant="subtitle2" sx={{ fontWeight: selectedThreadId === thread.id ? 600 : 500 }} noWrap>
                        {thread.contact?.display_name || thread.lead?.full_name || thread.counterparty_number}
                      </Typography>
                      <Typography variant="caption" sx={{ color: 'text.secondary', whiteSpace: 'nowrap', ml: 1 }}>
                        {thread.last_message_at ? new Date(thread.last_message_at).toLocaleDateString([], { month: 'short', day: 'numeric' }) : ''}
                      </Typography>
                    </Box>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <Box sx={{ display: 'flex', alignItems: 'center', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flex: 1, color: 'text.secondary' }}>
                        {thread.latest_message ? (
                          <>
                            {thread.latest_message.direction === 'outbound' && (
                              <i
                                className={`bx ${thread.latest_message.status === 'read' || thread.latest_message.status === 'delivered'
                                    ? 'bx-check-double'
                                    : thread.latest_message.status === 'sending'
                                      ? 'bx-time-five'
                                      : 'bx-check'
                                  }`}
                                style={{
                                  fontSize: '1.1rem',
                                  marginRight: 4,
                                  flexShrink: 0,
                                  color: thread.latest_message.status === 'read' ? '#6366f1' : 'rgba(17,27,33,0.4)',
                                }}
                              />
                            )}
                            <Typography
                              variant="body2"
                              sx={{
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                                color: 'inherit',
                                fontSize: '0.875rem',
                              }}
                            >
                              {thread.latest_message.body || (thread.latest_message.media && thread.latest_message.media.length > 0 ? '📷 Attachment' : '')}
                            </Typography>
                          </>
                        ) : (
                          <Typography
                            variant="body2"
                            sx={{
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              color: 'inherit',
                              fontSize: '0.875rem',
                            }}
                          >
                            {thread.lead_id ? `Lead: ${thread.lead_id.substring(0, 8)}...` : 'New interaction'}
                          </Typography>
                        )}
                      </Box>
                      {thread.unread_count !== undefined && thread.unread_count > 0 && (
                        <Box
                          sx={{
                            minWidth: 18,
                            height: 18,
                            borderRadius: '50%',
                            bgcolor: '#6366f1',
                            color: '#ffffff',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: '0.72rem',
                            fontWeight: 'bold',
                            px: 0.5,
                            ml: 1,
                            flexShrink: 0
                          }}
                        >
                          {thread.unread_count}
                        </Box>
                      )}
                    </Box>
                  </Box>
                </Box>
              ))
            )}
          </Box>
        </Box>

        {/* Right Sidebar: Chat Area */}
        <Box sx={{
          flex: 1,
          display: { xs: selectedThreadId ? 'none' : 'flex', md: 'flex' },
          flexDirection: 'column',
          background: 'linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%)',
          position: 'relative'
        }}>
          {selectedThreadId && selectedThread ? (
            <>
              {/* Chat Header */}
              <Box sx={{ p: 2, bgcolor: '#ffffff', display: 'flex', alignItems: 'center', gap: 2, zIndex: 1, borderBottom: 1, borderColor: 'divider', boxShadow: '0 2px 8px rgba(0,0,0,0.02)' }}>
                <IconButton sx={{ display: { xs: 'flex', md: 'none' } }} onClick={() => setSelectedThreadId("")}>
                  <i className="bx bx-arrow-back" />
                </IconButton>
                <Avatar sx={{ bgcolor: '#dfe3e8', color: '#5c6c75' }}>
                  <i className="bx bx-user" />
                </Avatar>
                <Box sx={{ flex: 1 }}>
                  <Typography variant="h6" sx={{ fontWeight: 600, color: '#1e293b', mb: 0.5 }}>
                    {selectedThread.contact?.display_name || selectedThread.lead?.full_name || selectedThread.counterparty_number}
                  </Typography>
                  <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                    Status: {selectedThread.status?.toUpperCase() ?? 'OPEN'} | Channel: {selectedThread.channel.toUpperCase() === 'WHATSAPP' ? 'WND WHATSAPP' : selectedThread.channel.toUpperCase()}
                  </Typography>
                </Box>
                <Box sx={{ display: 'flex', gap: 1 }}>
                  <StatusBadge label={selectedThread.priority ?? 'normal'} />
                  <IconButton onClick={(e) => setMenuAnchorEl(e.currentTarget)}>
                    <i className="bx bx-dots-vertical-rounded" />
                  </IconButton>
                  <Menu
                    anchorEl={menuAnchorEl}
                    open={Boolean(menuAnchorEl)}
                    onClose={() => setMenuAnchorEl(null)}
                    transformOrigin={{ horizontal: 'right', vertical: 'top' }}
                    anchorOrigin={{ horizontal: 'right', vertical: 'bottom' }}
                  >
                    <MenuItem onClick={handleClearChat}>Clear Chat</MenuItem>
                    <MenuItem onClick={handleDeleteChat} sx={{ color: 'error.main' }}>Delete Chat</MenuItem>
                  </Menu>
                </Box>
              </Box>

              {/* Chat Messages */}
              <Box 
                ref={scrollContainerRef}
                onScroll={handleScroll}
                sx={{ flex: 1, overflowY: 'auto', p: { xs: 2, md: 4 }, display: 'flex', flexDirection: 'column', gap: 1.5, zIndex: 1 }}
              >
                {loadingMessages ? (
                  <Box sx={{ display: 'flex', justifyContent: 'center', my: 4 }}><LoadingState /></Box>
                ) : messages.length === 0 ? (
                  <Box sx={{ display: 'flex', justifyContent: 'center', mt: 10 }}>
                    <Box sx={{ bgcolor: '#eef2ff', color: '#3730a3', border: '1px solid #c7d2fe', px: 3, py: 1.5, borderRadius: 2, fontSize: '0.875rem', display: 'flex', alignItems: 'center' }}>
                      <i className="bx bx-shield-quarter" style={{ marginRight: 8, fontSize: 18 }} />
                      Messages are end-to-end encrypted. No one outside of this chat, not even WND whatsapp, can read or listen to them.
                    </Box>
                  </Box>
                ) : (
                  <>
                    {loadingOlder && (
                      <Box sx={{ display: 'flex', justifyContent: 'center', mb: 2 }}>
                        <i className="bx bx-loader-alt bx-spin" style={{ color: '#6366f1', fontSize: 24 }} />
                      </Box>
                    )}
                    {messages.map((msg, index) => {
                    const isOutbound = msg.direction === 'outbound';
                    const showDate = index === 0 || new Date(msg.sent_at).toDateString() !== new Date(messages[index - 1].sent_at).toDateString();

                    return (
                      <Box key={msg.id} sx={{ display: 'flex', flexDirection: 'column', width: '100%' }}>
                        {showDate && (
                          <Box sx={{ display: 'flex', justifyContent: 'center', my: 2 }}>
                            <Box sx={{ bgcolor: '#fff', px: 2, py: 0.5, borderRadius: 2, fontSize: '0.75rem', color: 'text.secondary', boxShadow: '0 1px 0.5px rgba(11,20,26,.13)' }}>
                              {new Date(msg.sent_at).toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })}
                            </Box>
                          </Box>
                        )}
                        <Box sx={{
                          alignSelf: isOutbound ? 'flex-end' : 'flex-start',
                          maxWidth: { xs: '85%', md: '65%' },
                          background: isOutbound ? 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)' : '#ffffff',
                          p: 1.5,
                          pt: 1,
                          borderRadius: isOutbound ? '16px 16px 4px 16px' : '16px 16px 16px 4px',
                          border: isOutbound ? 'none' : '1px solid #e2e8f0',
                          boxShadow: '0 2px 5px rgba(0, 0, 0, 0.03)',
                          position: 'relative'
                        }}>
                          {msg.body ? (
                            <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', fontSize: '0.9rem', color: isOutbound ? '#ffffff' : '#1e293b', lineHeight: 1.5 }}>
                              {msg.body}
                            </Typography>
                          ) : null}

                          {msg.media && Array.isArray(msg.media) && msg.media.length > 0 && (
                            <Box sx={{ mt: msg.body ? 1 : 0, display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
                              {msg.media.map((m: any, i: number) => (
                                <Box key={i} sx={{ position: 'relative', borderRadius: 1, overflow: 'hidden' }}>
                                  {/* If it's a URL or contains URL */}
                                  <img src={typeof m === 'string' ? m : (m.url || m.link || '')} alt="media attachment" style={{ maxWidth: 240, maxHeight: 240, objectFit: 'cover', display: 'block' }} onError={(e) => { e.currentTarget.style.display = 'none'; }} />
                                </Box>
                              ))}
                            </Box>
                          )}

                          <Box sx={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', mt: 0.5, gap: 0.5 }}>
                            <Typography variant="caption" sx={{ color: isOutbound ? 'rgba(255,255,255,0.76)' : 'text.secondary', fontSize: '0.65rem' }}>
                              {new Date(msg.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            </Typography>
                            {isOutbound && (
                              <i
                                className={`bx ${msg.status === 'read' || msg.status === 'delivered' ? 'bx-check-double' : (msg.status === 'sending' ? 'bx-time-five' : 'bx-check')}`}
                                style={{ fontSize: '1rem', color: msg.status === 'read' ? '#a5f3fc' : 'rgba(255,255,255,0.6)' }}
                              />
                            )}
                          </Box>
                        </Box>
                      </Box>
                    );
                  })}
                  </>
                )}
                <div ref={messagesEndRef} />
              </Box>

              {/* Message Input Footer */}
              <Box
                component="form"
                onSubmit={onSend}
                sx={{
                  p: 2,
                  bgcolor: '#ffffff',
                  display: 'flex',
                  gap: 2,
                  alignItems: 'flex-end',
                  zIndex: 1,
                  borderTop: '1px solid #e2e8f0'
                }}
              >
                <IconButton sx={{ color: '#6366f1' }}>
                  <i className="bx bx-smile" />
                </IconButton>
                <IconButton sx={{ color: '#64748b' }}>
                  <i className="bx bx-paperclip" />
                </IconButton>
                <TextField
                  fullWidth
                  multiline
                  maxRows={5}
                  placeholder="Type a message"
                  value={outboundBody}
                  onChange={e => setOutboundBody(e.target.value)}
                  sx={{
                    bgcolor: '#f1f5f9',
                    borderRadius: 3,
                    '& .MuiOutlinedInput-root': {
                      borderRadius: 3,
                      py: 1.5,
                      border: '1px solid #e2e8f0',
                      '& fieldset': { border: 'none' }
                    }
                  }}
                  onKeyDown={e => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                      e.preventDefault();
                      onSend();
                    }
                  }}
                />
                <IconButton
                  type="submit"
                  disabled={sending || !outboundBody.trim()}
                  sx={{
                    color: outboundBody.trim() ? '#6366f1' : '#94a3b8',
                    transition: 'all 0.2s',
                    transform: outboundBody.trim() ? 'scale(1.1)' : 'scale(1)'
                  }}
                >
                  <i className={sending ? "bx bx-loader-alt bx-spin" : "bx bxs-send"} style={{ fontSize: 24 }} />
                </IconButton>
              </Box>
            </>
          ) : (
            <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', flexDirection: 'column', color: 'text.secondary', bgcolor: '#f8fafc', zIndex: 1 }}>
              <Avatar sx={{ width: 120, height: 120, background: 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)', mb: 4, boxShadow: '0 8px 24px rgba(99, 102, 241, 0.15)' }}>
                <i className="bx bx-message-rounded-dots" style={{ fontSize: 56, color: '#ffffff' }} />
              </Avatar>
              <Typography variant="h5" sx={{ fontWeight: 600, color: '#1e293b', mb: 2 }}>WND whatsapp</Typography>
              <Typography variant="body1" sx={{ color: '#64748b', textAlign: 'center', maxWidth: 400 }}>
                Send and receive messages seamlessly with WND whatsapp. Select a conversation from the left to start messaging.
              </Typography>
              <Box sx={{ display: 'flex', gap: 1, mt: 4, color: '#64748b', alignItems: 'center' }}>
                <i className="bx bx-shield-quarter" />
                <Typography variant="caption">End-to-end encrypted messaging protected by WND whatsapp</Typography>
              </Box>
            </Box>
          )}
        </Box>

      </Paper>
    </AppShell>
  );
}

export default function ConversationsPage() {
  return (
    <Suspense fallback={<div>Loading...</div>}>
      <ConversationsContent />
    </Suspense>
  );
}