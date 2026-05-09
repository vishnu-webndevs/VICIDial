"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";

type SearchResult = {
  id: string;
  type: string;
  label: string;
  route: string;
  meta?: Record<string, unknown>;
};

export default function SearchPage() {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");

  async function runSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!query.trim()) {
      setResults([]);
      setMessage("Enter a search term.");
      return;
    }

    setLoading(true);
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<{ data: SearchResult[] }>(
        `/search?q=${encodeURIComponent(query)}&limit=20`,
        { token, tenantId }
      );
      setResults(response.data ?? []);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Search request failed.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <AppShell requiredPermissions={["tenant.view"]}>
      <SectionCard title="Global Search" subtitle="Search calls, team members, and invoices across tenant data.">
        <form className="flex gap-2" onSubmit={runSearch}>
          <input
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Search by phone, member name, or invoice"
            className="flex-1 rounded-md border border-slate-300 p-2 text-sm"
          />
          <button
            type="submit"
            className="rounded-md bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-60"
            disabled={loading}
          >
            {loading ? "Searching..." : "Search"}
          </button>
        </form>
        <div className="mt-3 grid gap-2">
          {loading ? <LoadingState label="Searching across tenant data..." /> : null}
          {results.map((result) => (
            <div key={`${result.type}-${result.id}`} className="rounded-md border border-slate-200 p-3 text-sm">
              <p className="font-medium">{result.label}</p>
              <p className="text-xs text-slate-500">Type: {result.type}</p>
              <Link className="text-sm text-slate-700 underline" href={result.route}>
                Open
              </Link>
            </div>
          ))}
          {results.length === 0 && !loading ? (
            <EmptyState title="No results" description="No search results yet. Try a phone number, member name, or invoice number." />
          ) : null}
        </div>
        {message ? <ErrorState message={message} className="mt-2 text-sm text-rose-700" /> : null}
      </SectionCard>
    </AppShell>
  );
}
