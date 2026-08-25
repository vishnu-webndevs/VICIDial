"use client";

import { useEffect, useState } from "react";
import {
  Box,
  Checkbox,
  FormControlLabel,
  MenuItem,
  Modal,
  MuiButton,
  Paper,
  Stack,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@/ui";
import { AppShell, SectionCard, StatusBadge } from "@/components/app-shell";
import { EmptyPanel, KpiCard, ToastMessage } from "@/components/ui-primitives";
import Link from "next/link";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

type PlanFeature = {
  id: string;
  key: string;
  type: "limit" | "boolean" | "text";
  value: string;
  label: string | null;
};

type PlanRow = {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  price_monthly: number;
  price_yearly: number;
  is_active: boolean;
  is_public: boolean;
  sort_order: number;
  tenant_plans_count?: number;
  features: PlanFeature[];
};

type PlanForm = {
  name: string;
  slug: string;
  description: string;
  price_monthly: string;
  price_yearly: string;
  is_active: boolean;
  is_public: boolean;
};

type FeatureForm = {
  key: string;
  type: "limit" | "boolean" | "text";
  value: string;
  label: string;
};

const defaultPlanForm: PlanForm = {
  name: "",
  slug: "",
  description: "",
  price_monthly: "0",
  price_yearly: "0",
  is_active: true,
  is_public: true,
};

const defaultFeatureForm: FeatureForm = {
  key: "",
  type: "limit",
  value: "0",
  label: "",
};

type PopupState = "create-plan" | "edit-plan" | "add-feature" | "edit-feature" | null;

export default function SuperAdminPlansPage() {
  const [plans, setPlans] = useState<PlanRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState("");
  const [messageTone, setMessageTone] = useState<"neutral" | "success" | "error">("neutral");

  const [popup, setPopup] = useState<PopupState>(null);
  const [selectedPlan, setSelectedPlan] = useState<PlanRow | null>(null);
  const [selectedFeature, setSelectedFeature] = useState<PlanFeature | null>(null);

  const [planForm, setPlanForm] = useState<PlanForm>(defaultPlanForm);
  const [featureForm, setFeatureForm] = useState<FeatureForm>(defaultFeatureForm);

  const [saving, setSaving] = useState(false);
  const [deletingPlanId, setDeletingPlanId] = useState<string | null>(null);
  const [deletingFeatureKey, setDeletingFeatureKey] = useState<string | null>(null);
  const [confirmDeletePlanId, setConfirmDeletePlanId] = useState<string | null>(null);
  const [reordering, setReordering] = useState(false);

  async function loadPlans() {
    setLoading(true);
    try {
      const { token, tenantId } = getTenantContext();
      const response = await apiRequest<{ data: PlanRow[] }>("/super-admin/plans", { token, tenantId });
      setPlans(response.data ?? []);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to load plans.");
      setMessageTone("error");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadPlans();
  }, []);

  // ── Plan helpers ────────────────────────────────────────────────────────────

  function openCreatePlan() {
    setPlanForm(defaultPlanForm);
    setPopup("create-plan");
  }

  function openEditPlan(plan: PlanRow) {
    setSelectedPlan(plan);
    setPlanForm({
      name: plan.name ?? "",
      slug: plan.slug ?? "",
      description: plan.description ?? "",
      price_monthly: String(plan.price_monthly ?? 0),
      price_yearly: String(plan.price_yearly ?? 0),
      is_active: plan.is_active,
      is_public: plan.is_public,
    });
    setPopup("edit-plan");
  }

  async function savePlan() {
    if (!planForm.name.trim() || !planForm.slug.trim()) {
      setMessage("Name and slug are required.");
      setMessageTone("error");
      return;
    }
    setSaving(true);
    try {
      const { token, tenantId } = getTenantContext();
      const isEdit = popup === "edit-plan" && selectedPlan;
      await apiRequest(isEdit ? `/super-admin/plans/${selectedPlan.id}` : "/super-admin/plans", {
        method: isEdit ? "PUT" : "POST",
        token,
        tenantId,
        body: {
          name: planForm.name.trim(),
          slug: planForm.slug.trim(),
          description: planForm.description.trim() || null,
          price_monthly: Number(planForm.price_monthly) || 0,
          price_yearly: Number(planForm.price_yearly) || 0,
          is_active: planForm.is_active,
          is_public: planForm.is_public,
        },
      });
      setMessage(isEdit ? "Plan updated." : "Plan created.");
      setMessageTone("success");
      setPopup(null);
      await loadPlans();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to save plan.");
      setMessageTone("error");
    } finally {
      setSaving(false);
    }
  }

  async function deletePlan(plan: PlanRow) {
    setDeletingPlanId(plan.id);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/super-admin/plans/${plan.id}`, { method: "DELETE", token, tenantId });
      setMessage("Plan deleted.");
      setMessageTone("success");
      setConfirmDeletePlanId(null);
      await loadPlans();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to delete plan.");
      setMessageTone("error");
    } finally {
      setDeletingPlanId(null);
    }
  }

  async function reorder(planId: string, direction: "up" | "down") {
    const index = plans.findIndex((item) => item.id === planId);
    if (index < 0) return;
    const targetIndex = direction === "up" ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= plans.length) return;

    const reordered = [...plans];
    const [current] = reordered.splice(index, 1);
    reordered.splice(targetIndex, 0, current);
    setPlans(reordered);
    setReordering(true);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest("/super-admin/plans/reorder", {
        method: "PUT",
        token,
        tenantId,
        body: { plan_ids: reordered.map((item) => item.id) },
      });
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to reorder.");
      setMessageTone("error");
      await loadPlans();
    } finally {
      setReordering(false);
    }
  }

  // ── Feature helpers ─────────────────────────────────────────────────────────

  function openAddFeature(plan: PlanRow) {
    setSelectedPlan(plan);
    setSelectedFeature(null);
    setFeatureForm(defaultFeatureForm);
    setPopup("add-feature");
  }

  function openEditFeature(plan: PlanRow, feature: PlanFeature) {
    setSelectedPlan(plan);
    setSelectedFeature(feature);
    setFeatureForm({
      key: feature.key,
      type: feature.type,
      value: feature.value,
      label: feature.label ?? "",
    });
    setPopup("edit-feature");
  }

  async function saveFeature() {
    if (!selectedPlan) return;
    if (!featureForm.key.trim()) {
      setMessage("Feature key is required.");
      setMessageTone("error");
      return;
    }
    setSaving(true);
    try {
      const { token, tenantId } = getTenantContext();
      const isEdit = popup === "edit-feature" && selectedFeature;
      await apiRequest(
        isEdit
          ? `/super-admin/plans/${selectedPlan.id}/features/${selectedFeature.id}`
          : `/super-admin/plans/${selectedPlan.id}/features`,
        {
          method: isEdit ? "PUT" : "POST",
          token,
          tenantId,
          body: {
            key: featureForm.key.trim(),
            type: featureForm.type,
            value: featureForm.value.trim(),
            label: featureForm.label.trim() || featureForm.key.trim(),
          },
        }
      );
      setMessage(isEdit ? "Feature updated." : "Feature added.");
      setMessageTone("success");
      setPopup(null);
      await loadPlans();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to save feature.");
      setMessageTone("error");
    } finally {
      setSaving(false);
    }
  }

  async function deleteFeature(plan: PlanRow, feature: PlanFeature) {
    setDeletingFeatureKey(`${plan.id}:${feature.id}`);
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/super-admin/plans/${plan.id}/features/${feature.id}`, {
        method: "DELETE",
        token,
        tenantId,
      });
      setMessage("Feature removed.");
      setMessageTone("success");
      await loadPlans();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to delete feature.");
      setMessageTone("error");
    } finally {
      setDeletingFeatureKey(null);
    }
  }

  // ── Stats ───────────────────────────────────────────────────────────────────

  const totalActive = plans.filter((p) => p.is_active).length;
  const totalPublic = plans.filter((p) => p.is_public).length;
  const totalCompanies = plans.reduce((sum, p) => sum + Number(p.tenant_plans_count ?? 0), 0);

  return (
    <AppShell requiredRoles={["platform_super_admin", "super_admin"]}>
      {message ? <ToastMessage tone={messageTone} message={message} /> : null}

      <Box sx={{ display: "grid", gap: 2.5 }}>
        {/* Header */}
        <Box sx={{ display: "flex", flexDirection: { xs: "column", sm: "row" }, justifyContent: "space-between", alignItems: { xs: "stretch", sm: "center" }, gap: 1.5 }}>
          <Box>
            <Typography variant="h5" sx={{ fontWeight: 700, color: "#1e293b" }}>
              Subscription Tier & Pricing Governance
            </Typography>
            <Typography variant="body2" sx={{ color: "#64748b", mt: 0.5 }}>
              Create and manage subscription packages, pricing tiers, and feature limits.
            </Typography>
          </Box>
          <MuiButton variant="contained" onClick={openCreatePlan} sx={{ bgcolor: "#6366f1" }}>
            New Plan
          </MuiButton>
        </Box>

        {/* Navigation Quick Links */}
        <Paper variant="outlined" sx={{ p: 1.5, display: "flex", gap: 1, flexWrap: "wrap", bgcolor: "#ffffff", borderRadius: 2 }}>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin">Overview</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/companies">Companies</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/agency">Agencies</MuiButton>
          <MuiButton size="small" variant="contained" sx={{ bgcolor: "#6366f1" }}>Plans</MuiButton>
          <MuiButton size="small" variant="text" component={Link} href="/super-admin/settings">Settings</MuiButton>
        </Paper>

        {/* KPI row */}
        <Box sx={{ display: "grid", gap: 1.5, gridTemplateColumns: { xs: "1fr", sm: "repeat(2, 1fr)", lg: "repeat(4, 1fr)" } }}>
          <KpiCard label="Total Plans" value={plans.length} />
          <KpiCard label="Active Plans" value={totalActive} />
          <KpiCard label="Public Tier" value={totalPublic} />
          <KpiCard label="Subscribed Companies" value={totalCompanies} />
        </Box>

        <SectionCard title="Pricing Tier Matrix" subtitle="Configure feature caps and monthly/yearly subscription pricing.">
          {loading ? (
            <Typography variant="body2" color="text.secondary">Loading plans…</Typography>
          ) : plans.length === 0 ? (
            <EmptyPanel title="No plans yet" description="Click New Plan to create your first pricing plan." />
          ) : (
            <Box sx={{ display: "grid", gap: 2.5, gridTemplateColumns: { xs: "1fr", md: "repeat(2, 1fr)", lg: "repeat(3, 1fr)" } }}>
              {plans.map((plan, index) => (
                <Paper
                  key={plan.id}
                  variant="outlined"
                  sx={{
                    p: 2.5,
                    borderRadius: 3,
                    display: "flex",
                    flexDirection: "column",
                    justifyContent: "space-between",
                    bgcolor: "#ffffff",
                    boxShadow: "0 4px 12px rgba(0,0,0,0.03)",
                    border: "1px solid #e2e8f0",
                    transition: "transform 0.2s, box-shadow 0.2s",
                    "&:hover": {
                      boxShadow: "0 8px 24px rgba(99, 102, 241, 0.12)",
                      borderColor: "#6366f1",
                    },
                  }}
                >
                  {/* Header */}
                  <Box>
                    <Box sx={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 1, mb: 1 }}>
                      <Box>
                        <Typography variant="h6" sx={{ fontWeight: 700, color: "#0f172a" }}>
                          {plan.name}
                        </Typography>
                        <Typography variant="caption" sx={{ color: "#64748b", fontFamily: "monospace" }}>
                          {plan.slug}
                        </Typography>
                      </Box>
                      <Box sx={{ display: "flex", gap: 0.5, flexWrap: "wrap", justifyContent: "flex-end" }}>
                        <StatusBadge label={plan.is_active ? "active" : "inactive"} />
                        <StatusBadge label={plan.is_public ? "public" : "hidden"} />
                      </Box>
                    </Box>

                    {plan.description ? (
                      <Typography variant="body2" sx={{ color: "#64748b", mb: 2, minHeight: 40 }}>
                        {plan.description}
                      </Typography>
                    ) : (
                      <Box sx={{ mb: 2, minHeight: 40 }} />
                    )}

                    {/* Pricing Box */}
                    <Box sx={{ py: 1.5, px: 2, bgcolor: "#f8fafc", borderRadius: 2, mb: 2, border: "1px solid #f1f5f9" }}>
                      <Typography variant="h4" sx={{ fontWeight: 800, color: "#1e293b" }}>
                        ${Number(plan.price_monthly ?? 0).toFixed(2)}
                        <Typography component="span" variant="body2" sx={{ color: "#64748b", ml: 0.5 }}>/mo</Typography>
                      </Typography>
                      <Typography variant="caption" sx={{ color: "#64748b", display: "block", mt: 0.5 }}>
                        ${Number(plan.price_yearly ?? 0).toFixed(2)}/yr • {Number(plan.tenant_plans_count ?? 0)} companies active
                      </Typography>
                    </Box>

                    {/* Features List */}
                    <Box sx={{ mb: 2 }}>
                      <Typography variant="subtitle2" sx={{ fontWeight: 700, color: "#334155", mb: 1 }}>
                        Included Features & Limits
                      </Typography>
                      <Stack spacing={1}>
                        {plan.features.length === 0 ? (
                          <Typography variant="caption" sx={{ color: "#94a3b8", fontStyle: "italic" }}>
                            No features configured yet.
                          </Typography>
                        ) : (
                          plan.features.map((feature) => (
                            <Box
                              key={feature.id}
                              sx={{
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "space-between",
                                p: 1,
                                borderRadius: 1.5,
                                bgcolor: "#f8fafc",
                                border: "1px solid #e2e8f0",
                              }}
                            >
                              <Box sx={{ minWidth: 0, mr: 1 }}>
                                <Typography variant="body2" sx={{ fontWeight: 600, color: "#1e293b", fontSize: "0.85rem" }}>
                                  ✓ {feature.label ?? feature.key}
                                </Typography>
                                <Typography variant="caption" sx={{ color: "#64748b", display: "block" }}>
                                  {feature.key} = <strong>{feature.value === "-1" ? "unlimited" : feature.value}</strong> ({feature.type})
                                </Typography>
                              </Box>
                              <Stack direction="row" spacing={0.5} flexShrink={0}>
                                <MuiButton
                                  size="small"
                                  variant="text"
                                  sx={{ minWidth: 0, px: 0.75, py: 0.25, fontSize: 11 }}
                                  onClick={() => openEditFeature(plan, feature)}
                                >
                                  Edit
                                </MuiButton>
                                <MuiButton
                                  size="small"
                                  variant="text"
                                  color="error"
                                  sx={{ minWidth: 0, px: 0.75, py: 0.25, fontSize: 11 }}
                                  disabled={deletingFeatureKey === `${plan.id}:${feature.id}`}
                                  onClick={() => void deleteFeature(plan, feature)}
                                >
                                  {deletingFeatureKey === `${plan.id}:${feature.id}` ? "…" : "Remove"}
                                </MuiButton>
                              </Stack>
                            </Box>
                          ))
                        )}
                        <MuiButton
                          size="small"
                          variant="outlined"
                          onClick={() => openAddFeature(plan)}
                          sx={{ mt: 1, alignSelf: "flex-start", borderRadius: 1.5 }}
                        >
                          + Add Feature Limit
                        </MuiButton>
                      </Stack>
                    </Box>
                  </Box>

                  {/* Card Footer Actions */}
                  <Box sx={{ pt: 2, borderTop: "1px solid #e2e8f0", display: "flex", justifyContent: "space-between", alignItems: "center", gap: 1 }}>
                    <Stack direction="row" spacing={0.5}>
                      <MuiButton
                        size="small"
                        variant="outlined"
                        disabled={index === 0 || reordering}
                        onClick={() => void reorder(plan.id, "up")}
                        sx={{ minWidth: 32, px: 1 }}
                      >
                        ↑
                      </MuiButton>
                      <MuiButton
                        size="small"
                        variant="outlined"
                        disabled={index === plans.length - 1 || reordering}
                        onClick={() => void reorder(plan.id, "down")}
                        sx={{ minWidth: 32, px: 1 }}
                      >
                        ↓
                      </MuiButton>
                    </Stack>

                    <Stack direction="row" spacing={1}>
                      <MuiButton
                        size="small"
                        variant="contained"
                        onClick={() => openEditPlan(plan)}
                        sx={{ bgcolor: "#6366f1" }}
                      >
                        Edit Plan
                      </MuiButton>
                      {confirmDeletePlanId === plan.id ? (
                        <Stack direction="row" spacing={0.5}>
                          <MuiButton
                            size="small"
                            variant="contained"
                            color="error"
                            disabled={deletingPlanId === plan.id}
                            onClick={() => void deletePlan(plan)}
                          >
                            {deletingPlanId === plan.id ? "Deleting…" : "Confirm"}
                          </MuiButton>
                          <MuiButton
                            size="small"
                            variant="outlined"
                            onClick={() => setConfirmDeletePlanId(null)}
                          >
                            Cancel
                          </MuiButton>
                        </Stack>
                      ) : (
                        <MuiButton
                          size="small"
                          variant="outlined"
                          color="error"
                          disabled={Number(plan.tenant_plans_count ?? 0) > 0}
                          onClick={() => setConfirmDeletePlanId(plan.id)}
                        >
                          Delete
                        </MuiButton>
                      )}
                    </Stack>
                  </Box>
                </Paper>
              ))}
            </Box>
          )}
        </SectionCard>
      </Box>

      {/* ── Create / Edit Plan Modal ─────────────────────────────────────────── */}
      <Modal
        open={popup === "create-plan" || popup === "edit-plan"}
        onClose={() => setPopup(null)}
        title={popup === "edit-plan" ? `Edit Plan — ${selectedPlan?.name ?? ""}` : "New Plan"}
      >
        <Box sx={{ display: "grid", gap: 1.5 }}>
          <TextField
            label="Plan Name"
            size="medium"
            required
            value={planForm.name}
            onChange={(e) => {
              const name = e.target.value;
              setPlanForm((prev) => ({
                ...prev,
                name,
                slug: popup === "create-plan"
                  ? name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "")
                  : prev.slug,
              }));
            }}
          />
          <TextField
            label="Slug"
            size="medium"
            required
            value={planForm.slug}
            onChange={(e) => setPlanForm((prev) => ({ ...prev, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, "") }))}
            helperText="URL-friendly identifier, e.g. starter-plan"
          />
          <TextField
            label="Description (optional)"
            size="medium"
            multiline
            rows={2}
            value={planForm.description}
            onChange={(e) => setPlanForm((prev) => ({ ...prev, description: e.target.value }))}
          />
          <Stack direction="row" spacing={1.5}>
            <TextField
              label="Monthly Price ($)"
              size="medium"
              type="number"
              value={planForm.price_monthly}
              onChange={(e) => setPlanForm((prev) => ({ ...prev, price_monthly: e.target.value }))}
              inputProps={{ min: 0, step: 0.01 }}
              fullWidth
            />
            <TextField
              label="Yearly Price ($)"
              size="medium"
              type="number"
              value={planForm.price_yearly}
              onChange={(e) => setPlanForm((prev) => ({ ...prev, price_yearly: e.target.value }))}
              inputProps={{ min: 0, step: 0.01 }}
              fullWidth
            />
          </Stack>
          <Stack direction="row" spacing={2}>
            <FormControlLabel
              control={
                <Switch
                  checked={planForm.is_active}
                  onChange={(e) => setPlanForm((prev) => ({ ...prev, is_active: e.target.checked }))}
                />
              }
              label="Active"
            />
            <FormControlLabel
              control={
                <Switch
                  checked={planForm.is_public}
                  onChange={(e) => setPlanForm((prev) => ({ ...prev, is_public: e.target.checked }))}
                />
              }
              label="Visible to customers"
            />
          </Stack>
          <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
            <MuiButton variant="outlined" onClick={() => setPopup(null)}>Cancel</MuiButton>
            <MuiButton variant="contained" disabled={saving} onClick={() => void savePlan()}>
              {saving ? "Saving…" : popup === "edit-plan" ? "Save Changes" : "Create Plan"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>

      {/* ── Add / Edit Feature Modal ─────────────────────────────────────────── */}
      <Modal
        open={popup === "add-feature" || popup === "edit-feature"}
        onClose={() => setPopup(null)}
        title={
          popup === "edit-feature"
            ? `Edit Feature — ${selectedFeature?.key ?? ""}`
            : `Add Feature — ${selectedPlan?.name ?? ""}`
        }
      >
        <Box sx={{ display: "grid", gap: 1.5 }}>
          <TextField
            label="Feature Key"
            size="medium"
            required
            value={featureForm.key}
            disabled={popup === "edit-feature"}
            onChange={(e) => setFeatureForm((prev) => ({ ...prev, key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_") }))}
            helperText="Snake_case identifier, e.g. max_agents, call_recording, custom_domain"
          />
          <TextField
            select
            label="Type"
            size="medium"
            value={featureForm.type}
            disabled={popup === "edit-feature"}
            onChange={(e) => {
              const type = e.target.value as FeatureForm["type"];
              setFeatureForm((prev) => ({
                ...prev,
                type,
                value: type === "boolean" ? "true" : type === "limit" ? "0" : "",
              }));
            }}
          >
            <MenuItem value="limit">Limit — numeric cap (use -1 for unlimited)</MenuItem>
            <MenuItem value="boolean">Boolean — feature on/off</MenuItem>
            <MenuItem value="text">Text — free-form value</MenuItem>
          </TextField>

          {featureForm.type === "boolean" ? (
            <FormControlLabel
              control={
                <Checkbox
                  checked={featureForm.value === "true"}
                  onChange={(e) => setFeatureForm((prev) => ({ ...prev, value: e.target.checked ? "true" : "false" }))}
                />
              }
              label="Enabled for this plan"
            />
          ) : (
            <TextField
              label={featureForm.type === "limit" ? "Value (use -1 for unlimited)" : "Value"}
              size="medium"
              value={featureForm.value}
              onChange={(e) => setFeatureForm((prev) => ({ ...prev, value: e.target.value }))}
              type={featureForm.type === "limit" ? "number" : "text"}
              inputProps={featureForm.type === "limit" ? { min: -1 } : undefined}
            />
          )}

          <TextField
            label="Display Label (optional)"
            size="medium"
            value={featureForm.label}
            onChange={(e) => setFeatureForm((prev) => ({ ...prev, label: e.target.value }))}
            helperText="Human-readable label shown on pricing pages, e.g. Monthly Calls"
          />

          <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
            <MuiButton variant="outlined" onClick={() => setPopup(null)}>Cancel</MuiButton>
            <MuiButton variant="contained" disabled={saving} onClick={() => void saveFeature()}>
              {saving ? "Saving…" : popup === "edit-feature" ? "Save Changes" : "Add Feature"}
            </MuiButton>
          </Stack>
        </Box>
      </Modal>
    </AppShell>
  );
}
