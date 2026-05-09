"use client";

import { FormEvent, useEffect, useState } from "react";
import { AppShell, EmptyState, ErrorState, LoadingState, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import {
  Alert,
  Box,
  Button,
  FormTextField,
  MuiButton,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
} from "@/ui";

type ApiToken = {
  id: number;
  name: string;
  abilities: string[];
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string;
};

export default function ApiTokensPage() {
  const [tokens, setTokens] = useState<ApiToken[]>([]);
  const [name, setName] = useState("");
  const [abilities, setAbilities] = useState("call.view,call.initiate");
  const [expiresInDays, setExpiresInDays] = useState(30);
  const [createdToken, setCreatedToken] = useState("");
  const [message, setMessage] = useState("");

  async function loadTokens() {
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<{ data: ApiToken[] }>("/api-tokens", { token, tenantId });
      setTokens(response.data ?? []);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load API tokens.");
    }
  }

  async function createToken(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    setCreatedToken("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      const response = await apiRequest<{ data: { token: string } }>("/api-tokens", {
        method: "POST",
        token,
        tenantId,
        body: {
          name,
          abilities: abilities.split(",").map((value) => value.trim()).filter(Boolean),
          expires_in_days: expiresInDays,
        },
      });
      setCreatedToken(response.data.token);
      setName("");
      setMessage("API token created. Copy the secret token now.");
      await loadTokens();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to create token.");
    }
  }

  async function revokeToken(id: number) {
    setMessage("");
    try {
      const token = localStorage.getItem("wnd_token");
      const tenantId = localStorage.getItem("wnd_tenant_id");
      await apiRequest(`/api-tokens/${id}`, { method: "DELETE", token, tenantId });
      setMessage("Token revoked.");
      await loadTokens();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to revoke token.");
    }
  }

  useEffect(() => {
    const bootstrap = async () => {
      try {
        const token = localStorage.getItem("wnd_token");
        const tenantId = localStorage.getItem("wnd_tenant_id");
        const response = await apiRequest<{ data: ApiToken[] }>("/api-tokens", { token, tenantId });
        setTokens(response.data ?? []);
      } catch (error) {
        setMessage(error instanceof Error ? error.message : "Failed to load API tokens.");
      }
    };
    void bootstrap();
  }, []);

  return (
    <AppShell requiredPermissions={["api_token.view"]}>
      <Stack spacing={4}>
        <SectionCard title="Create API Token" subtitle="Generate tenant-scoped API credentials for integrations.">
          <Box
            component="form"
            onSubmit={createToken}
            sx={{ display: "grid", gap: 2, gridTemplateColumns: { xs: "1fr", md: "repeat(3, 1fr)" } }}
          >
            <FormTextField
              value={name}
              onChange={(event) => setName(event.target.value)}
              label="Token Name"
              placeholder="Token name"
              required
            />
            <FormTextField
              value={abilities}
              onChange={(event) => setAbilities(event.target.value)}
              label="Abilities"
              placeholder="Comma-separated abilities"
            />
            <FormTextField
              type="number"
              value={expiresInDays}
              inputProps={{ min: 1, max: 365 }}
              onChange={(event) => setExpiresInDays(Number(event.target.value))}
              label="Expires In (Days)"
            />
            <Button type="submit" sx={{ gridColumn: { md: "1 / -1" } }}>
              Generate Token
            </Button>
          </Box>
          {createdToken ? (
            <Alert severity="warning" sx={{ mt: 3, fontFamily: "monospace", wordBreak: "break-all" }}>
              {createdToken}
            </Alert>
          ) : null}
        </SectionCard>

        <SectionCard title="Token Inventory" subtitle="Review and revoke issued API tokens.">
          <MuiButton type="button" variant="outlined" onClick={() => void loadTokens()}>
            Refresh
          </MuiButton>
          <TableContainer component={Paper} variant="outlined" sx={{ mt: 3 }}>
            <Table>
              <TableHead>
                <TableRow>
                  <TableCell>Name</TableCell>
                  <TableCell>Abilities</TableCell>
                  <TableCell>Last Used</TableCell>
                  <TableCell>Expires</TableCell>
                  <TableCell align="right">Action</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {tokens.map((token) => (
                  <TableRow hover key={token.id}>
                    <TableCell>{token.name}</TableCell>
                    <TableCell>{token.abilities.join(", ")}</TableCell>
                    <TableCell>{token.last_used_at ? new Date(token.last_used_at).toLocaleString() : "-"}</TableCell>
                    <TableCell>{token.expires_at ? new Date(token.expires_at).toLocaleString() : "Never"}</TableCell>
                    <TableCell align="right">
                      <MuiButton
                        type="button"
                        color="error"
                        variant="outlined"
                        size="medium"
                        onClick={() => void revokeToken(token.id)}
                      >
                        Revoke
                      </MuiButton>
                    </TableCell>
                  </TableRow>
                ))}
                {tokens.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5}>
                      <EmptyState title="No tokens" description="No API tokens created for this tenant." />
                    </TableCell>
                  </TableRow>
                ) : null}
              </TableBody>
            </Table>
          </TableContainer>
          {message ? <Box sx={{ mt: 2 }}><ErrorState message={message} /></Box> : null}
          {tokens.length > 0 ? null : <Box sx={{ mt: 2 }}><LoadingState label="Create a token to start integrations." /></Box>}
        </SectionCard>
      </Stack>
    </AppShell>
  );
}
