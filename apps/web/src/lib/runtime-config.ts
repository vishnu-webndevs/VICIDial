const DEFAULT_API_BASE_URL = "http://localhost:8000/api/v1";

function isLocalBrowser(): boolean {
  if (typeof window === "undefined") {
    return false;
  }
  const host = window.location.hostname;
  return host === "localhost" || host === "127.0.0.1";
}

function isNgrokUrl(value: string): boolean {
  return /ngrok-free\.(app|dev)/i.test(value);
}

function resolveApiBaseUrl(): string {
  const raw = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (!raw) {
    return DEFAULT_API_BASE_URL;
  }
  if (isLocalBrowser() && isNgrokUrl(raw)) {
    return DEFAULT_API_BASE_URL;
  }
  return raw;
}

export const API_BASE_URL = resolveApiBaseUrl();
