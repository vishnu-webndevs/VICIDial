<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\OrgUnit;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actorMembership = $request->attributes->get('membership');
        $members = Membership::query()
            ->with(['user', 'role', 'agencyUnit', 'teamUnit'])
            ->where('tenant_id', $tenant->id)
            ->when($actorMembership, fn ($q) => $this->applyMembershipVisibilityScope($q, $actorMembership))
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $members->items(),
            'meta' => [
                'pagination' => [
                    'total' => $members->total(),
                    'per_page' => $members->perPage(),
                    'current_page' => $members->currentPage(),
                    'last_page' => $members->lastPage(),
                ],
            ],
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actorMembership = $request->attributes->get('membership');
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'exists:roles,slug'],
            'agency_unit_id' => ['nullable', 'uuid'],
            'team_unit_id' => ['nullable', 'uuid'],
        ]);

        $targetRole = Role::query()->where('slug', $validated['role'])->firstOrFail();
        if (! $this->canAssignRole($actorMembership?->role, $targetRole)) {
            throw ValidationException::withMessages([
                'role' => ['You cannot assign this role.'],
            ]);
        }

        [$agencyUnit, $teamUnit] = $this->resolveMembershipUnits(
            tenantId: $tenant->id,
            actorMembership: $actorMembership,
            agencyUnitId: $validated['agency_unit_id'] ?? null,
            teamUnitId: $validated['team_unit_id'] ?? null
        );

        $existingUser = User::query()->where('email', Str::lower($validated['email']))->first();
        if ($existingUser) {
            $existingMembership = Membership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $existingUser->id)
                ->whereIn('status', ['active', 'invited'])
                ->exists();
            if ($existingMembership) {
                return response()->json([
                    'error' => [
                        'code' => 'TEAM_MEMBER_ALREADY_EXISTS',
                        'message' => 'This user is already active or invited in the tenant.',
                    ],
                ], 409);
            }
        }

        $membership = Membership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $existingUser?->id,
            'role_id' => $targetRole->id,
            'agency_unit_id' => $agencyUnit?->id,
            'team_unit_id' => $teamUnit?->id,
            'status' => 'invited',
            'invited_by' => $request->user()->id,
            'invitation_token' => Str::random(64),
            'invitation_expires_at' => now()->addDays(7),
        ]);
        $this->auditLogger->log(
            action: 'membership.invited',
            resourceType: 'membership',
            resourceId: $membership->id,
            tenantId: $tenant->id,
            actorId: $request->user()->id,
            newValues: ['role' => $targetRole->slug, 'email' => Str::lower($validated['email'])],
            request: $request
        );

        return response()->json([
            'data' => [
                'id' => $membership->id,
                'email' => Str::lower($validated['email']),
                'role' => [
                    'slug' => $targetRole->slug,
                    'name' => $targetRole->name,
                ],
                'status' => $membership->status,
                'invitation_token' => $membership->invitation_token,
                'invitation_expires_at' => $membership->invitation_expires_at?->toISOString(),
            ],
        ], 201);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $membership = Membership::query()
            ->with(['role', 'tenant'])
            ->where('invitation_token', $token)
            ->where('status', 'invited')
            ->first();

        if (! $membership || ($membership->invitation_expires_at && $membership->invitation_expires_at->isPast())) {
            return response()->json([
                'error' => [
                    'code' => 'TEAM_INVITATION_EXPIRED',
                    'message' => 'Invitation token is invalid or expired.',
                ],
            ], 410);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        $email = Str::lower($validated['email']);

        $user = DB::transaction(function () use ($membership, $validated, $email) {
            $user = $membership->user;

            if (! $user) {
                $user = User::query()->firstOrCreate(
                    ['email' => $email],
                    [
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'password' => Hash::make($validated['password']),
                    ],
                );
            }

            $membership->user_id = $user->id;
            $membership->status = 'active';
            $membership->joined_at = now();
            $membership->invitation_token = null;
            $membership->invitation_expires_at = null;
            $membership->save();

            return $user;
        });

        $tokenValue = $user->createToken('auth-token')->plainTextToken;
        $membership->refresh();
        $this->auditLogger->log(
            action: 'membership.accepted',
            resourceType: 'membership',
            resourceId: $membership->id,
            tenantId: $membership->tenant_id,
            actorId: $user->id,
            newValues: ['status' => 'active'],
            request: $request
        );

        return response()->json([
            'data' => [
                'token' => $tokenValue,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                ],
                'tenant' => [
                    'id' => $membership->tenant->id,
                    'name' => $membership->tenant->name,
                    'slug' => $membership->tenant->slug,
                ],
                'membership' => [
                    'id' => $membership->id,
                    'status' => $membership->status,
                    'role' => $membership->role->slug,
                ],
            ],
        ]);
    }

    public function update(Request $request, string $membershipId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actorMembership = $request->attributes->get('membership');
        $membership = Membership::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('id', $membershipId)
            ->firstOrFail();
        if (! $this->canManageMembership($actorMembership, $membership)) {
            return response()->json([
                'error' => [
                    'code' => 'TEAM_FORBIDDEN_SCOPE',
                    'message' => 'You cannot manage this member outside your scope.',
                ],
            ], 403);
        }

        if ($membership->role?->slug === 'company_owner') {
            return response()->json([
                'error' => [
                    'code' => 'TEAM_CANNOT_MODIFY_OWNER',
                    'message' => 'Owner membership cannot be modified.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'role' => ['sometimes', 'string', 'exists:roles,slug'],
            'status' => ['sometimes', 'in:active,disabled'],
            'agency_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'team_unit_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (! empty($validated['role'])) {
            $targetRole = Role::query()->where('slug', $validated['role'])->firstOrFail();
            if (! $this->canAssignRole($actorMembership?->role, $targetRole)) {
                throw ValidationException::withMessages([
                    'role' => ['You cannot assign this role.'],
                ]);
            }
            $membership->role_id = $targetRole->id;
        }

        $oldStatus = $membership->status;
        if (! empty($validated['status'])) {
            $membership->status = $validated['status'];
        }

        if (array_key_exists('agency_unit_id', $validated) || array_key_exists('team_unit_id', $validated)) {
            [$agencyUnit, $teamUnit] = $this->resolveMembershipUnits(
                tenantId: $tenant->id,
                actorMembership: $actorMembership,
                agencyUnitId: $validated['agency_unit_id'] ?? $membership->agency_unit_id,
                teamUnitId: $validated['team_unit_id'] ?? $membership->team_unit_id
            );
            $membership->agency_unit_id = $agencyUnit?->id;
            $membership->team_unit_id = $teamUnit?->id;
        }

        $membership->save();
        $membership->load(['role', 'user']);
        $this->auditLogger->log(
            action: 'membership.updated',
            resourceType: 'membership',
            resourceId: $membership->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $membership->status, 'role' => $membership->role?->slug],
            request: $request
        );

        return response()->json(['data' => $membership]);
    }

    public function destroy(Request $request, string $membershipId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $membership = Membership::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('id', $membershipId)
            ->firstOrFail();
        $actorMembership = $request->attributes->get('membership');
        if (! $this->canManageMembership($actorMembership, $membership)) {
            return response()->json([
                'error' => [
                    'code' => 'TEAM_FORBIDDEN_SCOPE',
                    'message' => 'You cannot remove this member outside your scope.',
                ],
            ], 403);
        }

        if ($membership->role?->slug === 'company_owner') {
            return response()->json([
                'error' => [
                    'code' => 'TEAM_CANNOT_REMOVE_OWNER',
                    'message' => 'Owner membership cannot be removed.',
                ],
            ], 403);
        }

        $membershipSnapshot = [
            'status' => $membership->status,
            'role' => $membership->role?->slug,
            'user_id' => $membership->user_id,
        ];
        $membership->delete();
        $this->auditLogger->log(
            action: 'membership.removed',
            resourceType: 'membership',
            resourceId: $membershipId,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: $membershipSnapshot,
            request: $request
        );

        return response()->json([], 204);
    }

    private function canAssignRole(?Role $actorRole, Role $targetRole): bool
    {
        if (! $actorRole) {
            return false;
        }

        if ($actorRole->slug === 'platform_super_admin') {
            return true;
        }

        if ($actorRole->slug === 'super_admin') {
            return true;
        }

        if ($actorRole->slug === 'company_owner') {
            return true;
        }

        if (in_array($actorRole->slug, ['admin', 'agency'], true)) {
            return in_array($targetRole->slug, ['team', 'support_analyst', 'operations_manager', 'developer_manager'], true);
        }

        if ($actorRole->slug === 'company_admin') {
            return ! in_array($targetRole->slug, ['company_owner', 'company_admin'], true);
        }

        return false;
    }

    private function canManageMembership(?Membership $actorMembership, Membership $targetMembership): bool
    {
        $actorRole = $actorMembership?->role?->slug;
        if (! $actorRole) {
            return false;
        }
        if (in_array($actorRole, ['platform_super_admin', 'super_admin', 'company_owner', 'company_admin', 'admin'], true)) {
            return true;
        }
        if ($actorRole === 'agency') {
            return ! empty($actorMembership->agency_unit_id) && $actorMembership->agency_unit_id === $targetMembership->agency_unit_id;
        }
        if ($actorRole === 'team') {
            return ! empty($actorMembership->team_unit_id) && $actorMembership->team_unit_id === $targetMembership->team_unit_id;
        }

        return false;
    }

    private function applyMembershipVisibilityScope($query, Membership $actorMembership)
    {
        $actorRole = $actorMembership->role?->slug;
        if (in_array($actorRole, ['platform_super_admin', 'super_admin', 'company_owner', 'company_admin', 'admin'], true)) {
            return $query;
        }
        if ($actorRole === 'agency' && ! empty($actorMembership->agency_unit_id)) {
            return $query->where('agency_unit_id', $actorMembership->agency_unit_id);
        }
        if ($actorRole === 'team' && ! empty($actorMembership->team_unit_id)) {
            return $query->where('team_unit_id', $actorMembership->team_unit_id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @return array{0: OrgUnit|null, 1: OrgUnit|null}
     */
    private function resolveMembershipUnits(string $tenantId, ?Membership $actorMembership, ?string $agencyUnitId, ?string $teamUnitId): array
    {
        $agencyUnit = null;
        $teamUnit = null;

        if ($agencyUnitId) {
            $agencyUnit = OrgUnit::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $agencyUnitId)
                ->where('type', 'agency')
                ->first();
            if (! $agencyUnit) {
                throw ValidationException::withMessages([
                    'agency_unit_id' => ['agency_unit_id must reference a valid agency unit for this tenant.'],
                ]);
            }
        }

        if ($teamUnitId) {
            $teamUnit = OrgUnit::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $teamUnitId)
                ->where('type', 'team')
                ->first();
            if (! $teamUnit) {
                throw ValidationException::withMessages([
                    'team_unit_id' => ['team_unit_id must reference a valid team unit for this tenant.'],
                ]);
            }
            if ($agencyUnit && $teamUnit->parent_id !== $agencyUnit->id) {
                throw ValidationException::withMessages([
                    'team_unit_id' => ['Selected team_unit_id does not belong to the selected agency_unit_id.'],
                ]);
            }
        }

        if ($actorMembership?->role?->slug === 'agency') {
            $actorAgency = $actorMembership->agency_unit_id;
            if (! $actorAgency) {
                throw ValidationException::withMessages([
                    'agency_unit_id' => ['Agency member has no assigned agency scope.'],
                ]);
            }
            if ($agencyUnit && $agencyUnit->id !== $actorAgency) {
                throw ValidationException::withMessages([
                    'agency_unit_id' => ['Agency role can only assign users inside its own agency.'],
                ]);
            }
            if (! $agencyUnit) {
                $agencyUnit = OrgUnit::query()->find($actorAgency);
            }
            if ($teamUnit && $teamUnit->parent_id !== $actorAgency) {
                throw ValidationException::withMessages([
                    'team_unit_id' => ['Agency role can only assign teams under its own agency.'],
                ]);
            }
        }

        return [$agencyUnit, $teamUnit];
    }
}
