import type { SessionProfile } from "@/lib/product-api";

const SESSION_TTL_MS = 60_000;

let cachedSession: { data: SessionProfile; fetchedAt: number } | null = null;

export function setSessionCache(data: SessionProfile): void {
  cachedSession = { data, fetchedAt: Date.now() };
}

export function getCachedSession(): SessionProfile | null {
  if (!cachedSession) {
    return null;
  }
  if (Date.now() - cachedSession.fetchedAt > SESSION_TTL_MS) {
    return null;
  }
  return cachedSession.data;
}

export function getStaleSessionCache(): SessionProfile | null {
  return cachedSession?.data ?? null;
}

export function clearSessionCache(): void {
  cachedSession = null;
}
