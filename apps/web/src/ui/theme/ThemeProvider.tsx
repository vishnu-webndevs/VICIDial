"use client";

import { CssBaseline } from "@mui/material";
import { Experimental_CssVarsProvider as CssVarsProvider } from "@mui/material/styles";
import { ReactNode, useEffect, useMemo, useState } from "react";
import { createUiTheme } from "./index";
import EmotionRegistry from "./EmotionRegistry";

const MODE_KEY = "wnd_ui_mode";

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [mounted, setMounted] = useState(false);
  const [mode, setMode] = useState<"light" | "dark">("light");

  useEffect(() => {
    setMounted(true);
    const readMode = () => {
      const stored = localStorage.getItem(MODE_KEY);
      setMode(stored === "dark" ? "dark" : "light");
    };
    readMode();
    const handleStorage = () => readMode();
    window.addEventListener("storage", handleStorage);
    window.addEventListener("wnd-ui-mode-change", handleStorage);
    return () => {
      window.removeEventListener("storage", handleStorage);
      window.removeEventListener("wnd-ui-mode-change", handleStorage);
    };
  }, []);

  const theme = useMemo(() => createUiTheme(mode), [mode]);

  return (
    <EmotionRegistry>
      <CssVarsProvider theme={theme} defaultMode="light">
        <CssBaseline />
        {children}
      </CssVarsProvider>
    </EmotionRegistry>
  );
}
