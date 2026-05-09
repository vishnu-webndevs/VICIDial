"use client";

import { useEffect, useState } from "react";
import { AppShell, SectionCard } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { getTenantContext } from "@/lib/tenant-context";

type PlanItem = { id: string; name: string; slug: string };
type CompanyItem = {
  id: string;
  name: string;
  status: string;
  plan: PlanItem | null;
  usage: Record<string, number>;
};

export default function SuperAdminCompaniesPage() {
  const [companies, setCompanies] = useState<CompanyItem[]>([]);
  const [plans, setPlans] = useState<PlanItem[]>([]);
  const [message, setMessage] = useState("");

  async function loadData() {
    const { token, tenantId } = getTenantContext();
    const [companiesRes, plansRes] = await Promise.all([
      apiRequest<{ data: CompanyItem[] }>("/super-admin/companies", { token, tenantId }),
      apiRequest<{ data: Array<{ id: string; name: string; slug: string; is_active: boolean }> }>("/super-admin/plans", {
        token,
        tenantId,
      }),
    ]);
    setCompanies(companiesRes.data ?? []);
    setPlans((plansRes.data ?? []).filter((plan) => plan.is_active).map((plan) => ({
      id: plan.id,
      name: plan.name,
      slug: plan.slug,
    })));
  }

  useEffect(() => {
    void loadData();
  }, []);

  async function onChangePlan(companyId: string, planId: string) {
    try {
      const { token, tenantId } = getTenantContext();
      await apiRequest(`/super-admin/companies/${companyId}/plan`, {
        method: "PUT",
        token,
        tenantId,
        body: { plan_id: planId, billing_cycle: "monthly" },
      });
      setMessage("Company plan updated.");
      await loadData();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to change plan.");
    }
  }

  return (
    <AppShell requiredRoles={["platform_super_admin", "super_admin"]}>
      <SectionCard title="Company Plans" subtitle="Assign plans and monitor plan usage by company.">
        {message ? <p className="mb-2 text-sm text-slate-600">{message}</p> : null}
        <div className="overflow-x-auto">
          <table className="min-w-full border-collapse text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-slate-500">
                <th className="py-2">Company</th>
                <th className="py-2">Plan</th>
                <th className="py-2">Usage</th>
                <th className="py-2">Change Plan</th>
              </tr>
            </thead>
            <tbody>
              {companies.map((company) => (
                <tr key={company.id} className="border-b border-slate-100">
                  <td className="py-2 pr-3">
                    <p className="font-medium text-slate-900">{company.name}</p>
                    <p className="text-xs text-slate-500">{company.status}</p>
                  </td>
                  <td className="py-2 pr-3">{company.plan?.name ?? "N/A"}</td>
                  <td className="py-2 pr-3">
                    {Object.entries(company.usage ?? {}).map(([key, value]) => (
                      <p key={key} className="text-xs text-slate-600">
                        {key}: {value}
                      </p>
                    ))}
                  </td>
                  <td className="py-2">
                    <select
                      className="rounded border border-slate-300 px-2 py-1 text-xs"
                      defaultValue={company.plan?.id ?? ""}
                      onChange={(event) => void onChangePlan(company.id, event.target.value)}
                    >
                      {plans.map((plan) => (
                        <option key={plan.id} value={plan.id}>
                          {plan.name}
                        </option>
                      ))}
                    </select>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </SectionCard>
    </AppShell>
  );
}
