import Metadata from "next";

export const metadata = {
  title: "Privacy Policy | WND Dialer",
  description: "Privacy Policy and Data Protection Terms for WND Dialer and Meta WhatsApp Integration.",
};

export default function PrivacyPolicyPage() {
  return (
    <div style={{ backgroundColor: "#f8fafc", minHeight: "100vh", fontFamily: "system-ui, -apple-system, sans-serif" }}>
      <header style={{ backgroundColor: "#ffffff", borderBottom: "1px solid #e2e8f0", padding: "1.25rem 2rem" }}>
        <div style={{ maxWidth: "1000px", margin: "0 auto", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <a href="/" style={{ fontSize: "1.25rem", fontWeight: "700", color: "#4f46e5", textDecoration: "none" }}>
            WND Dialer
          </a>
          <a href="/login" style={{ color: "#475569", textDecoration: "none", fontSize: "0.9rem", fontWeight: "500" }}>
            Back to Application
          </a>
        </div>
      </header>

      <main style={{ maxWidth: "900px", margin: "2.5rem auto", padding: "0 1.5rem" }}>
        <div style={{ backgroundColor: "#ffffff", borderRadius: "12px", border: "1px solid #e2e8f0", padding: "2.5rem", boxShadow: "0 1px 3px rgba(0,0,0,0.05)" }}>
          <h1 style={{ fontSize: "2rem", fontWeight: "800", color: "#0f172a", marginBottom: "0.5rem" }}>
            Privacy Policy
          </h1>
          <p style={{ color: "#64748b", fontSize: "0.9rem", marginBottom: "2rem" }}>
            Last Updated: September 24, 2026
          </p>

          <section style={{ marginBottom: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              1. Introduction
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6" }}>
              Welcome to <strong>WND Dialer</strong> (&quot;Company&quot;, &quot;we&quot;, &quot;our&quot;, or &quot;us&quot;). We respect your privacy and are committed to protecting your personal data and customer communication records. This Privacy Policy explains how we collect, use, store, and process your information when you access our multi-tenant communication platform, mobile integrations, and Meta WhatsApp Business API services.
            </p>
          </section>

          <section style={{ marginBottom: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              2. Information We Collect
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6", marginBottom: "0.75rem" }}>
              We collect information necessary to deliver cloud auto-dialing, messaging, customer relationship management (CRM), and messaging analytics services:
            </p>
            <ul style={{ color: "#334155", lineHeight: "1.6", paddingLeft: "1.5rem" }}>
              <li><strong>Account Credentials:</strong> Name, business email, phone number, password hash, and company details.</li>
              <li><strong>Lead & Customer Data:</strong> Phone numbers, customer names, lead notes, custom fields, and conversation status uploaded by tenants.</li>
              <li><strong>Communication Logs:</strong> Call durations, SMS and Meta WhatsApp message content, timestamps, delivery receipts, and error codes.</li>
              <li><strong>Meta Integration Data:</strong> WhatsApp Business Account ID (WABA ID), Phone Number ID, OAuth access tokens, and webhook event payloads when you connect Meta Cloud API.</li>
            </ul>
          </section>

          <section style={{ marginBottom: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              3. How We Use Your Information
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6", marginBottom: "0.75rem" }}>
              We process your data strictly for legitimate operational purposes:
            </p>
            <ul style={{ color: "#334155", lineHeight: "1.6", paddingLeft: "1.5rem" }}>
              <li>To provide, operate, and maintain WND Dialer communication services.</li>
              <li>To dispatch outbound calls, SMS, and official Meta WhatsApp messages requested by authorized users.</li>
              <li>To process incoming webhooks and display real-time message threads in your unified Inbox.</li>
              <li>To compute billing usage metrics, campaign analytics, and enforce quota limits.</li>
              <li>To ensure system security, prevent fraud, and comply with telecom regulatory rules.</li>
            </ul>
          </section>

          <section style={{ marginBottom: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              4. Meta WhatsApp Platform Data Usage
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6" }}>
              When you connect Meta WhatsApp Cloud API via Embedded Signup or API credentials, your WABA ID, Phone Number ID, and message logs are processed solely to send and receive WhatsApp messages on your behalf. We store API tokens in encrypted form and do not sell, transfer, or use Meta user data for independent profiling or advertising purposes.
            </p>
          </section>

          <section style={{ marginBottom: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              5. Data Protection, Security & Retention
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6" }}>
              We employ industry-standard security controls including AES-256 encryption at rest for access tokens, TLS 1.3 encryption in transit, strict tenant isolation via database scoping, and role-based access control (RBAC). Data retention rules automatically redact message body contents and sensitive metadata according to configurable tenant retention policies.
            </p>
          </section>

          <section style={{ marginBottom: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              6. Data Deletion & Rights (DSR)
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6" }}>
              Users and data subjects have the right to request data access, correction, export, or deletion. You can initiate a Data Subject Request (DSR) directly from your account settings or contact support to request permanent deletion of your tenant account and associated Meta integration tokens.
            </p>
          </section>

          <section style={{ marginBottom: "1rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: "700", color: "#1e293b", marginBottom: "0.75rem" }}>
              7. Contact Us
            </h2>
            <p style={{ color: "#334155", lineHeight: "1.6" }}>
              If you have questions or concerns about this Privacy Policy or data privacy compliance, please contact our privacy compliance team at:
            </p>
            <p style={{ color: "#4f46e5", fontWeight: "600", marginTop: "0.5rem" }}>
              Email: support@wnd-dialer.webndevs.com
            </p>
          </section>
        </div>

        <footer style={{ textAlign: "center", margin: "2rem 0", color: "#94a3b8", fontSize: "0.85rem" }}>
          &copy; 2026 WND Dialer. All rights reserved.
        </footer>
      </main>
    </div>
  );
}
