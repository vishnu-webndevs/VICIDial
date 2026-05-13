import type { NextConfig } from "next";
import bundleAnalyzer from "@next/bundle-analyzer";

const apiBaseUrl =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000/api/v1";
const boxiconsCdn = "https://unpkg.com";
const stripeJsOrigin = "https://js.stripe.com";
const stripeApiOrigin = "https://api.stripe.com";
const stripeHooksOrigin = "https://hooks.stripe.com";
let apiOrigin = "http://localhost:8000";
const isDevelopment = process.env.NODE_ENV !== "production";
const scriptSrcDirectives = isDevelopment
  ? `'self' 'unsafe-inline' 'unsafe-eval' ${stripeJsOrigin}`
  : `'self' 'unsafe-inline' ${stripeJsOrigin}`;

try {
  apiOrigin = new URL(apiBaseUrl).origin;
} catch {
  apiOrigin = "http://localhost:8000";
}

const connectSrcOrigins = new Set<string>([
  "'self'",
  apiOrigin,
  stripeApiOrigin,
  "https:",
  "wss:",
]);

if (isDevelopment) {
  connectSrcOrigins.add("http://localhost:8000");
  connectSrcOrigins.add("http://127.0.0.1:8000");
}

const nextConfig: NextConfig = {
  poweredByHeader: false,
  reactStrictMode: false,
  async rewrites() {
    const internalApiOrigin = process.env.INTERNAL_API_ORIGIN ?? "http://api:8088";
    return [
      { source: "/api/:path*", destination: `${internalApiOrigin}/api/:path*` },
      { source: "/storage/:path*", destination: `${internalApiOrigin}/storage/:path*` },
    ];
  },
  async headers() {
    return [
      {
        source: "/(.*)",
        headers: [
          { key: "X-Frame-Options", value: "DENY" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=()",
          },
          {
            key: "Content-Security-Policy",
            value:
              `default-src 'self'; img-src 'self' data: https:; font-src 'self' data: ${boxiconsCdn}; style-src 'self' 'unsafe-inline' ${boxiconsCdn}; script-src ${scriptSrcDirectives}; connect-src ${Array.from(connectSrcOrigins).join(" ")}; frame-src 'self' ${stripeJsOrigin} ${stripeHooksOrigin};`,
          },
        ],
      },
    ];
  },
};

const withBundleAnalyzer = bundleAnalyzer({
  enabled: process.env.ANALYZE === "true",
});

export default withBundleAnalyzer(nextConfig);
