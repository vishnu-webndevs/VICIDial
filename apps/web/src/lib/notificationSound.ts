/**
 * Notification sound utility using the Web Audio API.
 * Generates a pleasant, professional notification tone without requiring any external audio files.
 */

let audioContext: AudioContext | null = null;

function getAudioContext(): AudioContext {
  if (!audioContext) {
    audioContext = new (window.AudioContext || (window as any).webkitAudioContext)();
  }
  return audioContext;
}

/**
 * Play a gentle two-tone notification chime.
 * Uses Web Audio API to synthesize the sound — no audio file needed.
 */
export function playNotificationSound(): void {
  try {
    const ctx = getAudioContext();
    if (ctx.state === "suspended") {
      ctx.resume();
    }

    const now = ctx.currentTime;

    // First tone — higher pitch
    const osc1 = ctx.createOscillator();
    const gain1 = ctx.createGain();
    osc1.type = "sine";
    osc1.frequency.setValueAtTime(830, now);
    gain1.gain.setValueAtTime(0.15, now);
    gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.25);
    osc1.connect(gain1);
    gain1.connect(ctx.destination);
    osc1.start(now);
    osc1.stop(now + 0.25);

    // Second tone — slightly lower, delayed
    const osc2 = ctx.createOscillator();
    const gain2 = ctx.createGain();
    osc2.type = "sine";
    osc2.frequency.setValueAtTime(1050, now + 0.12);
    gain2.gain.setValueAtTime(0, now);
    gain2.gain.setValueAtTime(0.12, now + 0.12);
    gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.4);
    osc2.connect(gain2);
    gain2.connect(ctx.destination);
    osc2.start(now + 0.12);
    osc2.stop(now + 0.4);
  } catch {
    // Silently fail — audio is non-critical
  }
}

/** Debounce guard to avoid rapid-fire notification sounds */
let lastPlayedAt = 0;
const MIN_INTERVAL_MS = 3000;

/**
 * Play notification sound with debouncing — won't play more than once every 3 seconds.
 */
export function playNotificationSoundDebounced(): void {
  const now = Date.now();
  if (now - lastPlayedAt < MIN_INTERVAL_MS) {
    return;
  }
  lastPlayedAt = now;
  playNotificationSound();
}
