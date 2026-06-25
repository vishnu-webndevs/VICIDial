"use client";

import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { Box, MenuItem, Paper, TextField, Typography } from "@/ui";
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, SkeletonLines, ToastMessage, UiButton } from "@/components/ui-primitives";
import {
  createCall,
  dispatchCallNow,
  endCall,
  listAgents,
  upsertAgentSession,
  reportDialerLoopIncident,
  retryCall,
  setCallMuted,
  setCallOnHold,
  getTwilioToken,
  updateAgent,
  uploadCallRecording,
} from "@/lib/product-api";
import { useLiveCalls } from "@/hooks/use-live-calls";
import type { AgentEntity } from "@/types/product";

type AgentCallState = {
  callId: string | null;
  relatedCallIds: string[];
  status: string;
  muted: boolean;
  onHold: boolean;
};

const E164_REGEX = /^\+[1-9]\d{7,14}$/;
const RETRYABLE_STATUSES = new Set(["queued", "failed", "busy", "no_answer", "timeout", "rejected", "canceled"]);
const ENDABLE_STATUSES = new Set(["queued", "ringing", "in_progress", "connected"]);

function normalizePhone(value: string): string {
  const trimmed = value.trim();
  if (!trimmed) return "";
  const compact = trimmed.replace(/[\s\-().]/g, "");
  if (compact.startsWith("+")) return `+${compact.slice(1).replace(/\D+/g, "")}`;
  return `+${compact.replace(/\D+/g, "")}`;
}

export default function DialerPage() {
  const MAX_ACTIONS = 80;
  const LOOP_WINDOW_MS = 120_000;
  const LOOP_MIN_TRANSITIONS = 8;

  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");
  const [submitting, setSubmitting] = useState(false);
  const [ending, setEnding] = useState(false);
  const [activeCall, setActiveCall] = useState<AgentCallState>({
    callId: null, relatedCallIds: [], status: "idle", muted: false, onHold: false,
  });
  const [callStartedAt, setCallStartedAt] = useState<number | null>(null);
  const [elapsedSeconds, setElapsedSeconds] = useState(0);
  const [eventLog, setEventLog] = useState<Array<{ at: string; text: string }>>([]);
  const actionHistoryRef = useRef<Array<{ at: string; type: string; details?: Record<string, unknown> }>>([]);
  const statusTransitionsRef = useRef<Array<{ atMs: number; status: string }>>([]);
  const loopReportedRef = useRef<Record<string, number>>({});
  const lastErrorStackRef = useRef<string>("");

  // Recording state refs
  const mediaRecorderRef = useRef<any>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const audioContextRef = useRef<AudioContext | null>(null);
  const localStreamRef = useRef<MediaStream | null>(null);
  const recordingStartTimeRef = useRef<number>(0);

  // Agent state
  const [agents, setAgents] = useState<AgentEntity[]>([]);
  const [selectedAgentId, setSelectedAgentId] = useState("");
  const [toNumber, setToNumber] = useState("");
  const [callingMethod, setCallingMethod] = useState<"webrtc" | "phone">("phone");
  const [device, setDevice] = useState<any | null>(null);
  const [deviceReady, setDeviceReady] = useState(false);
  const [twilioCall, setTwilioCall] = useState<any | null>(null);

  const isToNumberInvalid = useMemo(() => {
    if (!toNumber) return false;
    const normalized = normalizePhone(toNumber);
    return !E164_REGEX.test(normalized);
  }, [toNumber]);

  function getDialerSessionId(): string {
    if (typeof window === "undefined") return "server-session";
    const key = "wnd_dialer_session_id";
    const existing = localStorage.getItem(key);
    if (existing) return existing;
    const created = `${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
    localStorage.setItem(key, created);
    return created;
  }

  const dialerSessionIdRef = useRef<string>(getDialerSessionId());

  function recordAction(type: string, details?: Record<string, unknown>) {
    const at = new Date().toISOString();
    actionHistoryRef.current = [{ at, type, details }, ...actionHistoryRef.current].slice(0, MAX_ACTIONS);
  }

  function captureErrorStack(error: unknown) {
    if (error instanceof Error) { lastErrorStackRef.current = error.stack ?? error.message; return; }
    lastErrorStackRef.current = typeof error === "string" ? error : "Unknown dialer error";
  }

  // Load active agents for manual outbound calling.
  useEffect(() => {
    void (async () => {
      try {
        const all = await listAgents();
        const active = all.filter((agent) => agent.status === "active");
        setAgents(active);
        const firstDialable = active.find((agent) => Boolean(agent.default_number?.phone_number) && Boolean(agent.destination_number))
          ?? active.find((agent) => Boolean(agent.default_number?.phone_number))
          ?? active[0];
        if (firstDialable) {
          setSelectedAgentId(firstDialable.id);
          setCallingMethod(firstDialable.calling_method ?? "phone");
        }
      } catch { /* silently ignore */ }
    })();
  }, []);

  useEffect(() => {
    if (!selectedAgentId) return;

    let cancelled = false;
    const sendHeartbeat = async (status: "offline" | "available") => {
      try {
        await upsertAgentSession({ agent_id: selectedAgentId, status, capacity: 1 });
      } catch {
      }
    };

    void sendHeartbeat("available");
    const interval = window.setInterval(() => {
      if (cancelled) return;
      void sendHeartbeat("available");
    }, 30_000);

    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
  }, [selectedAgentId]);

  async function handleCallingMethodChange(method: "webrtc" | "phone") {
    if (!selectedAgentId) return;
    try {
      const updated = await updateAgent(selectedAgentId, { calling_method: method });
      setCallingMethod(method);
      setAgents((prev) =>
        prev.map((a) => (a.id === selectedAgentId ? { ...a, calling_method: method } : a))
      );
      setMessage(`Calling method updated to ${method === "webrtc" ? "WebRTC Webphone" : "Bridge Phone"}.`);
      setMessageTone("success");
    } catch (err) {
      setMessage("Failed to update calling method.");
      setMessageTone("error");
    }
  }

  // Twilio WebRTC Device Lifecycle
  useEffect(() => {
    if (typeof window === "undefined") return;
    if (callingMethod !== "webrtc" || !selectedAgentId) {
      if (device) {
        try {
          device.destroy();
        } catch {}
        setDevice(null);
        setDeviceReady(false);
        setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "WebRTC Device unregistered" }, ...prev].slice(0, 20));
      }
      return;
    }

    let activeDevice: any = null;
    let isCancelled = false;

    async function initDevice() {
      try {
        setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Initializing WebRTC Device..." }, ...prev].slice(0, 20));
        const { Device } = await import("@twilio/voice-sdk");
        const tokenData = await getTwilioToken(selectedAgentId);
        if (isCancelled) return;

        const newDevice = new Device(tokenData.token, {
          logLevel: "warn",
          closeProtection: true,
        });

        newDevice.on("registered", () => {
          setDeviceReady(true);
          setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `Webphone registered: ${tokenData.identity}` }, ...prev].slice(0, 20));
        });

        newDevice.on("unregistered", () => {
          setDeviceReady(false);
        });

        newDevice.on("error", (error) => {
          console.error("Twilio device error:", error);
          setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `WebRTC Error: ${error.message}` }, ...prev].slice(0, 20));
        });

        newDevice.on("incoming", (incomingCall) => {
          setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Incoming WebRTC leg received, auto-answering..." }, ...prev].slice(0, 20));
          setTwilioCall(incomingCall);
          incomingCall.accept();

          incomingCall.on("accept", () => {
            setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "WebRTC call connected" }, ...prev].slice(0, 20));
            let callSessionId = "";
            if (incomingCall.customParameters) {
              if (typeof incomingCall.customParameters.get === "function") {
                callSessionId = incomingCall.customParameters.get("call_session_id") || "";
              } else {
                callSessionId = (incomingCall.customParameters as any).call_session_id || "";
              }
            }
            if (callSessionId) {
              void startBrowserRecording(incomingCall, callSessionId);
            }
          });

          incomingCall.on("disconnect", () => {
            setTwilioCall(null);
            setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "WebRTC call disconnected" }, ...prev].slice(0, 20));
            stopBrowserRecording();
          });
        });

        await newDevice.register();
        activeDevice = newDevice;
        setDevice(newDevice);
      } catch (err) {
        console.error("Failed to initialize Twilio Device:", err);
        setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Failed to initialize WebRTC Device" }, ...prev].slice(0, 20));
      }
    }

    void initDevice();

    return () => {
      isCancelled = true;
      if (activeDevice) {
        try {
          activeDevice.destroy();
        } catch {}
      }
    };
  }, [callingMethod, selectedAgentId]);

  const { liveCalls, calls, loading, error, refresh } = useLiveCalls();
  const selectedAgent = useMemo(
    () => agents.find((agent) => agent.id === selectedAgentId) ?? null,
    [agents, selectedAgentId]
  );

  const relatedIds = useMemo(() => {
    if (activeCall.relatedCallIds.length > 0) return activeCall.relatedCallIds;
    if (activeCall.callId) return [activeCall.callId];
    return [];
  }, [activeCall.callId, activeCall.relatedCallIds]);

  const viewedCall = useMemo(() => {
    if (relatedIds.length === 0) return undefined;
    const related = calls.filter((item) => relatedIds.includes(item.id));
    const liveRelated = related.find((item) => ["queued", "ringing", "in_progress"].includes(item.status));
    if (liveRelated) return liveRelated;
    const lastKnown = related.sort((a, b) => (a.created_at < b.created_at ? 1 : -1))[0];
    return lastKnown;
  }, [calls, relatedIds]);

  async function uploadBrowserRecording(audioBlob: Blob, callSessionId: string) {
    try {
      const durationSeconds = recordingStartTimeRef.current > 0 ? (Date.now() - recordingStartTimeRef.current) / 1000 : 0;
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Uploading call recording..." }, ...prev].slice(0, 20));
      await uploadCallRecording(callSessionId, audioBlob, durationSeconds);
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Call recording uploaded successfully" }, ...prev].slice(0, 20));
    } catch (uploadError) {
      console.error("Failed to upload browser recording:", uploadError);
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Failed to upload call recording" }, ...prev].slice(0, 20));
    }
  }

  async function startBrowserRecording(call: any, callSessionId: string) {
    try {
      if (!call || typeof call.getRemoteStream !== "function") {
        console.warn("getRemoteStream is not a function on the Twilio call object.");
        return;
      }
      const remoteStream = call.getRemoteStream();
      if (!remoteStream) {
        console.warn("No remote stream available to record.");
        return;
      }

      const localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      localStreamRef.current = localStream;

      const AudioContextClass = window.AudioContext || (window as any).webkitAudioContext;
      const audioContext = new AudioContextClass();
      audioContextRef.current = audioContext;

      const localSource = audioContext.createMediaStreamSource(localStream);
      const remoteSource = audioContext.createMediaStreamSource(remoteStream);
      const dest = audioContext.createMediaStreamDestination();

      localSource.connect(dest);
      remoteSource.connect(dest);

      const mediaRecorder = new MediaRecorder(dest.stream, { mimeType: "audio/webm" });
      mediaRecorderRef.current = mediaRecorder;
      audioChunksRef.current = [];
      recordingStartTimeRef.current = Date.now();

      mediaRecorder.ondataavailable = (event) => {
        if (event.data && event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      mediaRecorder.onstop = async () => {
        const audioBlob = new Blob(audioChunksRef.current, { type: "audio/webm" });
        if (audioBlob.size > 1000) {
          await uploadBrowserRecording(audioBlob, callSessionId);
        }
        
        if (localStreamRef.current) {
          localStreamRef.current.getTracks().forEach((track) => track.stop());
          localStreamRef.current = null;
        }
        if (audioContextRef.current) {
          audioContextRef.current.close().catch(() => {});
          audioContextRef.current = null;
        }
      };

      mediaRecorder.start();
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Call recording started locally" }, ...prev].slice(0, 20));
    } catch (error) {
      console.error("Failed to start browser call recording:", error);
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `Recording failed to start: ${error instanceof Error ? error.message : String(error)}` }, ...prev].slice(0, 20));
    }
  }

  function stopBrowserRecording() {
    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== "inactive") {
      mediaRecorderRef.current.stop();
      mediaRecorderRef.current = null;
    }
  }

  const hasActiveDialerCall =
    relatedIds.length > 0 &&
    (Boolean(viewedCall && ["queued", "ringing", "in_progress"].includes(viewedCall.status)) ||
      ["queued", "ringing", "in_progress"].includes(activeCall.status));

  async function submitCall(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    recordAction("call.submit_requested");

    if (ending) {
      setMessage("Ending the current call. Please wait a moment.");
      setMessageTone("error");
      return;
    }
    if (hasActiveDialerCall) {
      setMessage("A call is already active. End the current call before starting a new one.");
      setMessageTone("error");
      return;
    }

    const to = normalizePhone(toNumber);
    if (!E164_REGEX.test(to)) {
      setMessage("Destination number is invalid. Use format like +14155552671.");
      setMessageTone("error");
      return;
    }
    if (!selectedAgentId) {
      setMessage("Select an agent first.");
      setMessageTone("error");
      return;
    }
    if (callingMethod === "phone" && !selectedAgent?.default_number?.phone_number) {
      setMessage("Selected agent does not have an active assigned validated number.");
      setMessageTone("error");
      return;
    }

    setSubmitting(true);
    setMessage("");
    setMessageTone("neutral");

    try {
      const customerCall = await createCall({
        to,
        agent_id: selectedAgentId,
        metadata: { dial_mode: "normal" },
      });
      recordAction("call.submit_succeeded", { call_id: customerCall.id, to: customerCall.to_number, mode: callingMethod });

      if (callingMethod === "webrtc") {
        if (!device || !deviceReady) {
          throw new Error("WebRTC webphone is not registered or ready yet.");
        }

        const callConnection = await device.connect({
          params: {
            To: to,
            agent_id: selectedAgentId,
            call_session_id: customerCall.id,
          },
        });

        setTwilioCall(callConnection);

        callConnection.on("accept", () => {
            setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Direct WebRTC call connected" }, ...prev].slice(0, 20));
            void startBrowserRecording(callConnection, customerCall.id);
          });

          callConnection.on("error", (error: any) => {
            console.error("Twilio call connection error:", error);
            setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `Call error: ${error.message || JSON.stringify(error)}` }, ...prev].slice(0, 20));
          });

          callConnection.on("disconnect", () => {
            setTwilioCall(null);
            setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Direct WebRTC call disconnected" }, ...prev].slice(0, 20));
            stopBrowserRecording();
          });

        setActiveCall({
          callId: customerCall.id,
          relatedCallIds: [customerCall.id],
          status: "ringing",
          muted: false,
          onHold: false,
        });
        setCallStartedAt(Date.now());
        setMessage(`Direct outbound call started to ${customerCall.to_number}.`);
        setMessageTone("success");
        setEventLog((prev) => [
          { at: new Date().toLocaleTimeString(), text: `Direct WebRTC to ${customerCall.to_number}` },
          ...prev,
        ].slice(0, 20));
      } else {
        let customerDispatched = customerCall;
        try {
          customerDispatched = await dispatchCallNow(customerCall.id);
          recordAction("call.dispatch_now_succeeded", { call_id: customerCall.id, to: customerCall.to_number });
        } catch (dispatchError) {
          captureErrorStack(dispatchError);
          recordAction("call.dispatch_now_failed", { call_id: customerCall.id, message: dispatchError instanceof Error ? dispatchError.message : "unknown_error" });
        }

        setActiveCall({
          callId: customerDispatched.id,
          relatedCallIds: [customerDispatched.id],
          status: customerDispatched.status,
          muted: customerDispatched.controls?.muted ?? false,
          onHold: customerDispatched.controls?.on_hold ?? false,
        });
        setCallStartedAt(Date.now());
        setMessage(`Outbound call started to ${customerCall.to_number}.`);
        setMessageTone("success");
        setEventLog((prev) => [
          { at: new Date().toLocaleTimeString(), text: `Outbound call to ${customerCall.to_number}` },
          ...prev,
        ].slice(0, 20));
      }

      setToNumber("");
      await refresh();
    } catch (err) {
      captureErrorStack(err);
      recordAction("call.submit_failed", { message: err instanceof Error ? err.message : "unknown_error" });
      setMessage(err instanceof Error ? err.message : "Call initiation failed.");
      setMessageTone("error");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleRetry() {
    if (!viewedCall || !RETRYABLE_STATUSES.has(viewedCall.status)) {
      setMessage("Call cannot be retried in its current state.");
      setMessageTone("error");
      return;
    }
    if (ending) return;
    try {
      recordAction("call.retry_requested", { call_id: viewedCall.id });
      if (viewedCall.status === "queued") {
        const updated = await dispatchCallNow(viewedCall.id);
        setMessage(`Call dispatched for ${updated.to_number}.`);
        setMessageTone("success");
        setActiveCall((prev) => ({
          ...prev,
          callId: updated.id,
          status: updated.status,
          muted: updated.controls?.muted ?? prev.muted,
          onHold: updated.controls?.on_hold ?? prev.onHold,
        }));
        setCallStartedAt(Date.now());
        setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `Dispatched call to ${updated.to_number}` }, ...prev].slice(0, 20));
      } else {
        const retried = await retryCall(viewedCall.id);
        setMessage(`Retry queued for ${retried.to_number}.`);
        setMessageTone("success");
        setActiveCall((prev) => ({
          ...prev,
          callId: retried.id,
          status: retried.status,
          muted: retried.controls?.muted ?? prev.muted,
          onHold: retried.controls?.on_hold ?? prev.onHold,
        }));
        setCallStartedAt(Date.now());
        setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `Retry queued for ${retried.to_number}` }, ...prev].slice(0, 20));
      }
      await refresh();
    } catch (err) {
      captureErrorStack(err);
      setMessage(err instanceof Error ? err.message : "Retry failed.");
      setMessageTone("error");
    }
  }

  async function handleMuteToggle() {
    if (!viewedCall) return;
    if (ending) return;
    try {
      const nextMuted = !activeCall.muted;
      if (callingMethod === "webrtc" && twilioCall) {
        twilioCall.mute(nextMuted);
      }
      const next = await setCallMuted(viewedCall.id, nextMuted);
      setActiveCall((prev) => ({ ...prev, callId: next.id, status: next.status, muted: next.controls?.muted ?? nextMuted }));
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: nextMuted ? "Muted call audio" : "Unmuted call audio" }, ...prev].slice(0, 20));
      await refresh();
    } catch (err) { captureErrorStack(err); setMessage(err instanceof Error ? err.message : "Unable to update mute state."); setMessageTone("error"); }
  }

  async function handleHoldToggle() {
    if (!viewedCall) return;
    if (ending) return;
    try {
      const next = await setCallOnHold(viewedCall.id, !activeCall.onHold);
      setActiveCall((prev) => ({ ...prev, callId: next.id, status: next.status, onHold: next.controls?.on_hold ?? !prev.onHold }));
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: next.controls?.on_hold ? "Placed on hold" : "Resumed" }, ...prev].slice(0, 20));
      await refresh();
    } catch (err) { captureErrorStack(err); setMessage(err instanceof Error ? err.message : "Unable to update hold state."); setMessageTone("error"); }
  }

  async function handleEndCall() {
    if (ending) return;
    if (!viewedCall || !ENDABLE_STATUSES.has(viewedCall.status)) {
      setMessage("Call must be active before it can be ended.");
      setMessageTone("error");
      return;
    }
    try {
      setEnding(true);
      recordAction("call.end_requested", { call_id: viewedCall.id });

      if (callingMethod === "webrtc" && twilioCall) {
        try {
          twilioCall.disconnect();
        } catch {}
      }

      const relatedIds = activeCall.relatedCallIds.length > 0 ? activeCall.relatedCallIds : (activeCall.callId ? [activeCall.callId] : []);
      const endableIds = calls
        .filter((item) => relatedIds.includes(item.id) && ENDABLE_STATUSES.has(item.status))
        .map((item) => item.id);

      if (endableIds.length === 0) {
        const ended = await endCall(viewedCall.id);
        setActiveCall({
          callId: ended.id,
          relatedCallIds: relatedIds,
          status: ended.status,
          muted: ended.controls?.muted ?? false,
          onHold: ended.controls?.on_hold ?? false,
        });
      } else {
        for (const callId of endableIds) {
          try {
            await endCall(callId);
          } catch (err) {
            captureErrorStack(err);
          }
        }
        setActiveCall({ callId: null, relatedCallIds: [], status: "idle", muted: false, onHold: false });
      }
      setMessage("Call ended.");
      setMessageTone("success");
      setCallStartedAt(null);
      setElapsedSeconds(0);
      setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: "Call ended by agent" }, ...prev].slice(0, 20));
      await refresh();
    } catch (err) {
      captureErrorStack(err);
      setMessage(err instanceof Error ? err.message : "Unable to end call.");
      setMessageTone("error");
    } finally {
      setEnding(false);
    }
  }

  useEffect(() => {
    if (!callStartedAt) return;
    const timer = setInterval(() => setElapsedSeconds(Math.floor((Date.now() - callStartedAt) / 1000)), 1000);
    return () => clearInterval(timer);
  }, [callStartedAt]);

  useEffect(() => {
    if (!viewedCall?.status) return;
    recordAction("call.status_changed", { call_id: viewedCall.id, status: viewedCall.status });
    const nowMs = Date.now();
    statusTransitionsRef.current = [
      { atMs: nowMs, status: viewedCall.status },
      ...statusTransitionsRef.current.filter((e) => nowMs - e.atMs <= LOOP_WINDOW_MS),
    ].slice(0, 50);
    setEventLog((prev) => [{ at: new Date().toLocaleTimeString(), text: `Status: ${viewedCall.status}` }, ...prev].slice(0, 20));
  }, [viewedCall?.id, viewedCall?.status]);

  useEffect(() => {
    if (!error) return;
    recordAction("stream.error", { message: error });
  }, [error]);

  useEffect(() => {
    const transitions = statusTransitionsRef.current;
    if (transitions.length < LOOP_MIN_TRANSITIONS) return;
    const statuses = transitions.map((e) => e.status);
    const terminalStatuses = ["completed", "failed", "busy", "no_answer", "timeout", "rejected", "canceled"];
    if (statuses.some((s) => terminalStatuses.includes(s))) return;
    if (Array.from(new Set(statuses)).length > 3) return;
    const signature = statuses.slice(0, LOOP_MIN_TRANSITIONS).join(">");
    if (Date.now() - (loopReportedRef.current[signature] ?? 0) < LOOP_WINDOW_MS) return;
    loopReportedRef.current[signature] = Date.now();
    void reportDialerLoopIncident({
      timestamp: new Date().toISOString(),
      session_id: dialerSessionIdRef.current,
      loop_signature: signature,
      browser: { user_agent: typeof navigator !== "undefined" ? navigator.userAgent : "unknown" },
      error_stack_trace: lastErrorStackRef.current || undefined,
      actions: actionHistoryRef.current.slice(0, 50),
      metadata: { call_id: viewedCall?.id ?? activeCall.callId ?? null, live_call_count: liveCalls.length, current_status: viewedCall?.status ?? activeCall.status, page: "/dialer" },
    }).catch(captureErrorStack);
  }, [activeCall.callId, activeCall.status, liveCalls.length, viewedCall?.id, viewedCall?.status]);

  function formatDuration(totalSeconds: number): string {
    const mins = Math.floor(totalSeconds / 60);
    const secs = totalSeconds % 60;
    return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
  }

  return (
    <AppShell requiredPermissions={["call.initiate"]}>
      <Box sx={{ display: "grid", gap: 2, pb: hasActiveDialerCall ? 14 : 0, gridTemplateColumns: { xs: "1fr", xl: "1.1fr 1fr" } }}>
        <SectionCard title="Agent Calling Interface" subtitle="Select an agent and dial manually.">
          <Box component="form" sx={{ display: "grid", gap: 1.5 }} onSubmit={submitCall}>
            <TextField
              select
              size="medium"
              required
              label="Agent"
              value={selectedAgentId}
              onChange={(e) => {
                const id = e.target.value;
                setSelectedAgentId(id);
                const agent = agents.find((a) => a.id === id);
                if (agent) {
                  setCallingMethod(agent.calling_method ?? "phone");
                }
              }}
              disabled={agents.length === 0}
              helperText={
                agents.length === 0
                  ? "No active agents found."
                  : selectedAgent?.default_number?.phone_number
                    ? `Caller ID: ${selectedAgent.default_number.phone_number}`
                    : "Selected agent has no assigned validated number."
              }
            >
              {agents.map((agent) => (
                <MenuItem key={agent.id} value={agent.id}>
                  {agent.company_number}
                  {agent.default_number?.phone_number ? ` (${agent.default_number.phone_number})` : " (no assigned number)"}
                  {!agent.destination_number ? " (no destination)" : ""}
                </MenuItem>
              ))}
            </TextField>

            <TextField
              select
              size="medium"
              required
              label="Calling Method"
              value={callingMethod}
              onChange={(e) => handleCallingMethodChange(e.target.value as "webrtc" | "phone")}
              disabled={!selectedAgentId}
              helperText={
                callingMethod === "webrtc"
                  ? deviceReady
                    ? "🟢 WebRTC Webphone is ready"
                    : "⏳ Initializing WebRTC Webphone..."
                  : "📞 Bridged to agent destination number"
              }
            >
              <MenuItem value="phone">Bridge Phone (Standard)</MenuItem>
              <MenuItem value="webrtc">WebRTC Webphone (Browser)</MenuItem>
            </TextField>

            <TextField
              required
              type="tel"
              size="medium"
              label="To (Destination Number)"
              placeholder="+14155552671"
              value={toNumber}
              onChange={(e) => {
                const value = e.target.value;
                const sanitized = value.replace(/[^0-9+\s\-()]/g, "");
                setToNumber(sanitized);
              }}
              error={isToNumberInvalid}
              helperText={
                isToNumberInvalid
                  ? "Invalid E.164 format. Must start with + and followed by 8-15 digits (e.g. +919876543210)."
                  : "E.164 format, e.g. +14155552671"
              }
            />

            <Box sx={{ width: "100%" }}>
              <UiButton
                type="submit"
                variant="primary"
                disabled={submitting || ending || hasActiveDialerCall || !selectedAgentId}
                className="w-full"
              >
                {submitting ? "Initiating Call..." : hasActiveDialerCall ? "Call Active" : ending ? "Ending..." : "Start Call"}
              </UiButton>
            </Box>
          </Box>

          {message ? (
            <Box sx={{ mt: 1.5 }}>
              <ToastMessage tone={messageTone} title={messageTone === "error" ? "Action Failed" : "Dialer Update"} message={message} />
            </Box>
          ) : null}
          {error ? <Box sx={{ mt: 1 }}><ToastMessage tone="error" title="Live Stream Error" message={error} /></Box> : null}
        </SectionCard>

        <SectionCard title="Live Call Console" subtitle="Live status, timer, controls, and event history.">
          {loading ? <SkeletonLines rows={5} /> : null}
          {viewedCall ? (
            <Box sx={{ display: "grid", gap: 2 }}>
              <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "repeat(2, 1fr)", md: "repeat(4, 1fr)" } }}>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">Call Status</Typography>
                  <Box sx={{ mt: 0.5 }}><StatusBadge label={viewedCall.status} /></Box>
                </Paper>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">Timer</Typography>
                  <Typography variant="h6" sx={{ mt: 0.5 }}>{formatDuration(elapsedSeconds)}</Typography>
                </Paper>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">Mute</Typography>
                  <Typography variant="body2" sx={{ mt: 0.5, fontWeight: 600 }}>{activeCall.muted ? "Enabled" : "Disabled"}</Typography>
                </Paper>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">Hold</Typography>
                  <Typography variant="body2" sx={{ mt: 0.5, fontWeight: 600 }}>{activeCall.onHold ? "Enabled" : "Disabled"}</Typography>
                </Paper>
              </Box>
              <Typography variant="body2"><Box component="span" sx={{ color: "text.secondary" }}>From:</Box> {viewedCall.from_number}</Typography>
              <Typography variant="body2"><Box component="span" sx={{ color: "text.secondary" }}>To:</Box> {viewedCall.to_number}</Typography>
              <Typography variant="body2"><Box component="span" sx={{ color: "text.secondary" }}>Provider:</Box> {viewedCall.provider?.label ?? "N/A"}</Typography>
              {viewedCall.failure_reason ? (
                <Typography variant="body2" color="error">
                  Failure: {viewedCall.failure_reason}
                </Typography>
              ) : null}
            </Box>
          ) : (
            <EmptyPanel title="No active calls" description="Dial a number from the left panel to start a call." />
          )}

          <Box sx={{ mt: 2, display: "grid", gap: 1, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)" } }}>
            <UiButton type="button" disabled={!viewedCall || ending} onClick={handleMuteToggle}>{activeCall.muted ? "Unmute" : "Mute"}</UiButton>
            <UiButton type="button" disabled={!viewedCall || ending} onClick={handleHoldToggle}>{activeCall.onHold ? "Resume" : "Hold"}</UiButton>
            <UiButton type="button" variant="danger" disabled={!viewedCall || ending} onClick={handleEndCall}>{ending ? "Ending..." : "End Call"}</UiButton>
            <UiButton type="button" disabled={!viewedCall || ending} onClick={handleRetry}>{viewedCall?.status === "queued" ? "Start Queued Call" : "Retry"}</UiButton>
          </Box>

          <Paper variant="outlined" sx={{ mt: 2.5, p: 1.5 }}>
            <Typography variant="caption" sx={{ fontWeight: 700, textTransform: "uppercase", color: "text.secondary" }}>
              Call Event Timeline
            </Typography>
            {eventLog.length === 0 ? (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>No events yet. Initiate a call to start the timeline.</Typography>
            ) : (
              <Box sx={{ mt: 1, maxHeight: 220, overflowY: "auto", display: "grid" }}>
                {eventLog.map((entry, index) => (
                  <Box key={`${entry.at}-${index}`} sx={{ pl: 2, borderLeft: 1, borderColor: "divider", position: "relative" }}>
                    <Box sx={{ position: "absolute", left: -5, top: 10, width: 9, height: 9, borderRadius: "50%", bgcolor: "text.disabled" }} />
                    <Typography variant="caption" color="text.disabled">{entry.at}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ pb: 1 }}>{entry.text}</Typography>
                  </Box>
                ))}
              </Box>
            )}
          </Paper>
        </SectionCard>
      </Box>

      {hasActiveDialerCall ? (
        <Box sx={{ position: "fixed", left: 0, right: 0, bottom: 0, zIndex: 30, borderTop: 1, borderColor: "divider", bgcolor: "background.paper", px: 2, py: 1.5, backdropFilter: "blur(4px)" }}>
          <Box sx={{ mx: "auto", width: "100%", maxWidth: 1400, display: "flex", flexWrap: "wrap", alignItems: "center", justifyContent: "space-between", gap: 1.5 }}>
            <Box sx={{ display: "flex", alignItems: "center", gap: 1.5 }}>
              <StatusBadge label={viewedCall?.status ?? "idle"} />
              <Typography variant="body2" color="text.secondary">
                <Box component="span" sx={{ color: "text.primary", fontWeight: 600 }}>{viewedCall?.to_number ?? "-"}</Box>
                {" "}• {formatDuration(elapsedSeconds)}
              </Typography>
            </Box>
            <Box sx={{ display: "flex", flexWrap: "wrap", gap: 1 }}>
              <UiButton type="button" disabled={!viewedCall || ending} onClick={handleMuteToggle}>{activeCall.muted ? "Unmute" : "Mute"}</UiButton>
              <UiButton type="button" disabled={!viewedCall || ending} onClick={handleHoldToggle}>{activeCall.onHold ? "Resume" : "Hold"}</UiButton>
              <UiButton type="button" disabled={!viewedCall || ending} onClick={handleRetry}>{viewedCall?.status === "queued" ? "Start Queued Call" : "Retry"}</UiButton>
              <UiButton type="button" variant="danger" disabled={!viewedCall || ending} onClick={handleEndCall}>{ending ? "Ending..." : "End Call"}</UiButton>
            </Box>
          </Box>
        </Box>
      ) : null}
    </AppShell>
  );
}
