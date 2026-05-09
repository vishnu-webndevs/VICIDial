import { API_BASE_URL } from "@/lib/runtime-config";

function validateApiBaseUrl(baseUrl: string): void {
  const normalized = baseUrl.toLowerCase();
  const isSandboxLike = normalized.includes("/sandbox/") || normalized.includes("/mock/");

  if (process.env.NODE_ENV === "production" && isSandboxLike) {
    throw new Error("Production build cannot call sandbox/mock API endpoints.");
  }
}

type HttpMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

type ApiRequestOptions = {
  method?: HttpMethod;
  token?: string | null;
  tenantId?: string | null;
  body?: unknown;
  extraHeaders?: Record<string, string>;
};

export async function apiRequest<T>(
  path: string,
  options: ApiRequestOptions = {}
): Promise<T> {
  validateApiBaseUrl(API_BASE_URL);
  const { method = "GET", token, tenantId, body, extraHeaders = {} } = options;
  const headers: Record<string, string> = {
    ...extraHeaders,
  };
  const isNgrokApi = /ngrok-free\.(app|dev)/i.test(API_BASE_URL);

  if (isNgrokApi) {
    headers["ngrok-skip-browser-warning"] = "true";
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  if (tenantId) {
    headers["X-Tenant-Id"] = tenantId;
  }

  const isFormData = typeof FormData !== "undefined" && body instanceof FormData;
  if (!isFormData) {
    headers["Content-Type"] = "application/json";
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body: body ? (isFormData ? (body as FormData) : JSON.stringify(body)) : undefined,
  });

  const contentType = response.headers.get("content-type");
  const responseBody = contentType?.includes("application/json")
    ? await response.json()
    : null;

  if (!response.ok) {
    const usageLimitErrorCode = typeof responseBody?.error === "string" ? responseBody.error : null;
    const message =
      responseBody?.error?.message ??
      responseBody?.message ??
      `Request failed with status ${response.status}`;
    if (response.status === 403 && usageLimitErrorCode === "usage_limit_reached") {
      const featureKey = String(responseBody?.feature_key ?? "feature");
      throw new Error(`Usage limit reached for ${featureKey}. Upgrade your plan to continue.`);
    }
    throw new Error(message);
  }

  return responseBody as T;
}
