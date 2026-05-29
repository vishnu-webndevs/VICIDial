"use client";

import { useState } from "react";
import { useServerInsertedHTML } from "next/navigation";
import createCache from "@emotion/cache";
import { CacheProvider } from "@emotion/react";

/**
 * Emotion cache registry for Next.js App Router.
 *
 * Ensures Emotion-generated <style> tags are flushed into the server-rendered
 * HTML so the client hydration tree matches the server tree exactly.
 * Without this, MUI/Emotion styles are injected only at runtime, causing
 * React hydration-mismatch warnings.
 *
 * Reference: https://mui.com/material-ui/integrations/nextjs/#app-router
 */
export default function EmotionRegistry({ children }: { children: React.ReactNode }) {
  const [cache] = useState(() => {
    const c = createCache({ key: "mui" });
    c.compat = true;
    return c;
  });

  useServerInsertedHTML(() => {
    const entries = (cache as any).sheet?.tags;
    if (!entries || entries.length === 0) return null;

    // Collect all style names that have been inserted
    const names = Object.keys((cache as any).inserted);
    if (names.length === 0) return null;

    let styles = "";
    for (const name of names) {
      const val = (cache as any).inserted[name];
      if (typeof val === "string") {
        styles += val;
      }
    }

    return (
      <style
        key={cache.key}
        data-emotion={`${cache.key} ${names.join(" ")}`}
        dangerouslySetInnerHTML={{ __html: styles }}
      />
    );
  });

  return <CacheProvider value={cache}>{children}</CacheProvider>;
}
