<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadList;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\UsageMeter;
use App\Services\AuditLogger;
use App\Services\PlanQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PlanQuotaService $planQuotaService,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $ownerRole = Role::query()->firstOrCreate(
            ['slug' => 'company_owner'],
            [
                'name' => 'Company Owner',
                'description' => 'Company Owner role',
                'is_platform_role' => false,
                'hierarchy_level' => 5,
            ]
        );
        $this->ensureCompanyOwnerRolePermissions($ownerRole);
        $plan = Plan::query()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN slug = 'starter' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->first();
        if (! $plan) {
            $plan = Plan::query()->where('slug', 'starter')->first();
            if ($plan) {
                if (! $plan->is_active) {
                    $plan->forceFill(['is_active' => true])->save();
                }
            } else {
                $plan = Plan::query()->create([
                    'slug' => 'starter',
                    'name' => 'Starter',
                    'description' => 'Auto-created default starter plan.',
                    'billing_cycle' => 'monthly',
                    'price_monthly' => 49.00,
                    'price_yearly' => 470.40,
                    'monthly_price_cents' => 4900,
                    'yearly_price_cents' => 47040,
                    'trial_days' => 14,
                    'api_quota_monthly' => 50000,
                    'call_minutes_monthly' => 1000,
                    'webhook_events_monthly' => 10000,
                    'is_active' => true,
                    'is_public' => true,
                    'sort_order' => 1,
                ]);
            }
        }

        $result = DB::transaction(function () use ($validated, $ownerRole, $plan) {
            $tenant = Tenant::query()->create([
                'name' => $validated['company_name'],
                'slug' => Str::slug($validated['company_name']).'-'.Str::lower(Str::random(6)),
                'status' => 'active',
            ]);

            TenantSetting::query()->create([
                'tenant_id' => $tenant->id,
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $user = User::query()->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => Str::lower($validated['email']),
                'password' => Hash::make($validated['password']),
            ]);

            $membership = Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $ownerRole->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            LeadList::query()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Default List',
                'description' => 'Auto-created during company registration.',
                'is_active' => true,
            ]);

            $periodStart = now()->startOfDay();
            $periodEnd = now()->addMonthNoOverflow()->endOfDay();
            $subscription = Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => $plan->trial_days > 0 ? 'trialing' : 'active',
                'billing_cycle' => 'monthly',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
            ]);

            $meters = [
                ['meter_type' => 'api_requests', 'limit_units' => $plan->api_quota_monthly],
                ['meter_type' => 'call_minutes', 'limit_units' => $plan->call_minutes_monthly],
                ['meter_type' => 'webhook_events', 'limit_units' => $plan->webhook_events_monthly],
            ];

            foreach ($meters as $meter) {
                UsageMeter::query()->create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'meter_type' => $meter['meter_type'],
                    'consumed_units' => 0,
                    'limit_units' => $meter['limit_units'],
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]);
            }

            if ($this->hasTable('tenant_plans')) {
                TenantPlan::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => 'monthly',
                    'started_at' => now(),
                    'status' => 'active',
                ]);
            }

            return compact('tenant', 'user', 'membership', 'subscription');
        });

        $token = $result['user']->createToken('auth-token')->plainTextToken;
        $this->auditLogger->log(
            action: 'auth.register',
            resourceType: 'user',
            resourceId: $result['user']->id,
            tenantId: $result['tenant']->id,
            actorId: $result['user']->id,
            newValues: ['role' => 'company_owner'],
            request: $request
        );

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $result['user']->id,
                    'email' => $result['user']->email,
                    'first_name' => $result['user']->first_name,
                    'last_name' => $result['user']->last_name,
                ],
                'tenant' => [
                    'id' => $result['tenant']->id,
                    'name' => $result['tenant']->name,
                    'slug' => $result['tenant']->slug,
                    'status' => $result['tenant']->status,
                ],
                'membership' => [
                    'id' => $result['membership']->id,
                    'status' => $result['membership']->status,
                    'role' => 'company_owner',
                ],
                'subscription' => [
                    'id' => $result['subscription']->id,
                    'status' => $result['subscription']->status,
                    'billing_cycle' => $result['subscription']->billing_cycle,
                    'plan' => $plan->slug,
                ],
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', Str::lower($validated['email']))->first();
        
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
                'error' => 'invalid_credentials'
            ], 200);
        }


        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;
        $this->auditLogger->log(
            action: 'auth.login',
            resourceType: 'user',
            resourceId: $user->id,
            tenantId: null,
            actorId: $user->id,
            request: $request
        );

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'last_login_at' => optional($user->last_login_at)->toISOString(),
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->auditLogger->log(
            action: 'auth.logout',
            resourceType: 'user',
            resourceId: $user?->id,
            tenantId: null,
            actorId: $user?->id,
            request: $request
        );
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([], 204);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink([
            'email' => Str::lower($validated['email']),
        ]);

        return response()->json([
            'data' => [
                'status' => __($status),
            ],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => (string) $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password) use ($request) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
                $this->auditLogger->log(
                    action: 'auth.password_reset',
                    resourceType: 'user',
                    resourceId: $user->id,
                    tenantId: null,
                    actorId: $user->id,
                    request: $request
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'error' => [
                    'code' => 'AUTH_PASSWORD_RESET_FAILED',
                    'message' => __($status),
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'status' => __($status),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $request->attributes->get('membership');
        $tenant = $request->attributes->get('tenant');

        $permissions = $membership?->role?->permissions?->pluck('slug')->values() ?? collect();
        $plan = null;
        $usage = [];
        $trialExpired = false;

        if ($tenant) {
            $plan = $this->planQuotaService->resolveActivePlan($tenant);
            $usage = $this->planQuotaService->usageSnapshot($tenant->id, $plan);

            if ($user && !$user->is_platform_admin) {
                $subscription = Subscription::query()
                    ->where('tenant_id', $tenant->id)
                    ->latest()
                    ->first();
                if ($subscription) {
                    if ($subscription->status === 'trialing' && $subscription->trial_ends_at && $subscription->trial_ends_at->isPast()) {
                        $trialExpired = true;
                    } elseif (in_array($subscription->status, ['canceled', 'unpaid', 'past_due'], true)) {
                        $trialExpired = true;
                    }
                }
            }
        }

        return response()->json([
            'data' => [
                'id' => $user?->id,
                'email' => $user?->email,
                'first_name' => $user?->first_name,
                'last_name' => $user?->last_name,
                'is_platform_admin' => (bool) $user?->is_platform_admin,
                'current_tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                ] : null,
                'role' => $membership?->role ? [
                    'slug' => $membership->role->slug,
                    'name' => $membership->role->name,
                ] : null,
                'permissions' => $permissions,
                'plan' => $plan ? [
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'features' => $plan->featureMap()
                        ->mapWithKeys(fn (PlanFeature $feature) => [
                            $feature->key => [
                                'value' => $feature->type === 'boolean'
                                    ? filter_var($feature->value, FILTER_VALIDATE_BOOLEAN)
                                    : ((int) $feature->value == $feature->value ? (int) $feature->value : $feature->value),
                                'label' => $feature->label ?? $feature->key,
                                'type' => $feature->type,
                            ],
                        ])
                        ->all(),
                ] : null,
                'usage' => $usage,
                'trial_expired' => $trialExpired,
            ],
        ]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ], 401);
        }

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255'],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:128'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        $wantsPasswordChange = array_key_exists('password', $validated) && is_string($validated['password']) && trim($validated['password']) !== '';
        $wantsEmailChange = array_key_exists('email', $validated)
            && Str::lower((string) $validated['email']) !== Str::lower((string) $user->email);

        if ($wantsEmailChange) {
            $exists = User::query()
                ->where('email', Str::lower((string) $validated['email']))
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'email' => ['The email has already been taken.'],
                ]);
            }
        }

        if ($wantsPasswordChange || $wantsEmailChange) {
            $currentPassword = trim((string) ($validated['current_password'] ?? ''));
            if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Current password is incorrect.'],
                ]);
            }
        }

        $oldValues = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ];

        if (array_key_exists('first_name', $validated)) {
            $user->first_name = (string) $validated['first_name'];
        }
        if (array_key_exists('last_name', $validated)) {
            $user->last_name = (string) $validated['last_name'];
        }
        if (array_key_exists('email', $validated)) {
            $user->email = Str::lower((string) $validated['email']);
        }
        if ($wantsPasswordChange) {
            $user->password = Hash::make((string) $validated['password']);
        }

        $user->save();

        $newToken = null;
        if ($wantsPasswordChange) {
            $user->tokens()->delete();
            $newToken = $user->createToken('auth-token')->plainTextToken;
        }

        $this->auditLogger->log(
            action: 'auth.profile_updated',
            resourceType: 'user',
            resourceId: $user->id,
            tenantId: null,
            actorId: $user->id,
            newValues: array_diff_assoc(
                [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'password_changed' => $wantsPasswordChange,
                ],
                $oldValues
            ),
            request: $request
        );

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                ],
                'token' => $newToken,
            ],
        ]);
    }

    private function ensureCompanyOwnerRolePermissions(Role $role): void
    {
        if ($role->slug !== 'company_owner') {
            return;
        }
        if (! $this->hasTable('permissions') || ! $this->hasTable('role_permissions')) {
            return;
        }
        if ($role->permissions()->exists()) {
            return;
        }

        $permissionIds = Permission::query()
            ->where('slug', 'not like', 'platform.%')
            ->pluck('id')
            ->all();
        if ($permissionIds !== []) {
            $role->permissions()->sync($permissionIds);
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
