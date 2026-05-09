<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\OrgUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgHierarchyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actorMembership = $request->attributes->get('membership');

        $units = OrgUnit::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        if ($actorMembership?->role?->slug === 'agency' && $actorMembership->agency_unit_id) {
            $units = $units->filter(fn (OrgUnit $unit) => $unit->id === $actorMembership->agency_unit_id || $unit->parent_id === $actorMembership->agency_unit_id)->values();
        } elseif ($actorMembership?->role?->slug === 'team' && $actorMembership->team_unit_id) {
            $units = $units->where('id', $actorMembership->team_unit_id)->values();
        }

        $memberCountsByAgency = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereNotNull('agency_unit_id')
            ->selectRaw('agency_unit_id, COUNT(*) as count')
            ->groupBy('agency_unit_id')
            ->pluck('count', 'agency_unit_id');
        $memberCountsByTeam = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereNotNull('team_unit_id')
            ->selectRaw('team_unit_id, COUNT(*) as count')
            ->groupBy('team_unit_id')
            ->pluck('count', 'team_unit_id');

        $agencies = $units->where('type', 'agency')->values()->map(function (OrgUnit $agency) use ($units, $memberCountsByAgency, $memberCountsByTeam) {
            $teams = $units->where('type', 'team')->where('parent_id', $agency->id)->values()->map(fn (OrgUnit $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'type' => $team->type,
                'is_active' => (bool) $team->is_active,
                'member_count' => (int) ($memberCountsByTeam[$team->id] ?? 0),
            ])->all();

            return [
                'id' => $agency->id,
                'name' => $agency->name,
                'type' => $agency->type,
                'is_active' => (bool) $agency->is_active,
                'member_count' => (int) ($memberCountsByAgency[$agency->id] ?? 0),
                'teams' => $teams,
            ];
        })->all();

        return response()->json(['data' => $agencies]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actorMembership = $request->attributes->get('membership');
        $validated = $request->validate([
            'type' => ['required', 'in:agency,team'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        if ($actorMembership?->role?->slug === 'agency' && $validated['type'] === 'agency') {
            return response()->json([
                'error' => [
                    'code' => 'ORG_FORBIDDEN',
                    'message' => 'Agency role cannot create agencies.',
                ],
            ], 403);
        }

        $parentId = $validated['parent_id'] ?? null;
        if ($validated['type'] === 'team') {
            if (! $parentId) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_PARENT_REQUIRED',
                        'message' => 'Team units must include an agency parent_id.',
                    ],
                ], 422);
            }

            $parent = OrgUnit::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $parentId)
                ->where('type', 'agency')
                ->first();
            if (! $parent) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_PARENT_INVALID',
                        'message' => 'parent_id must reference an agency in the same tenant.',
                    ],
                ], 422);
            }

            if ($actorMembership?->role?->slug === 'agency' && $actorMembership->agency_unit_id !== $parent->id) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_FORBIDDEN',
                        'message' => 'Agency role can only create teams inside its own agency.',
                    ],
                ], 403);
            }
        } else {
            $parentId = null;
        }

        $unit = OrgUnit::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
            'name' => $validated['name'],
            'parent_id' => $parentId,
            'is_active' => true,
        ]);

        return response()->json(['data' => $unit], 201);
    }

    public function assignMembershipUnits(Request $request, string $membershipId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actorMembership = $request->attributes->get('membership');
        $membership = Membership::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->where('id', $membershipId)
            ->firstOrFail();

        $validated = $request->validate([
            'agency_unit_id' => ['nullable', 'uuid'],
            'team_unit_id' => ['nullable', 'uuid'],
        ]);

        $agency = null;
        if (! empty($validated['agency_unit_id'])) {
            $agency = OrgUnit::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $validated['agency_unit_id'])
                ->where('type', 'agency')
                ->first();
            if (! $agency) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_AGENCY_INVALID',
                        'message' => 'agency_unit_id must be a valid agency unit in this tenant.',
                    ],
                ], 422);
            }
        }

        $team = null;
        if (! empty($validated['team_unit_id'])) {
            $team = OrgUnit::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $validated['team_unit_id'])
                ->where('type', 'team')
                ->first();
            if (! $team) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_TEAM_INVALID',
                        'message' => 'team_unit_id must be a valid team unit in this tenant.',
                    ],
                ], 422);
            }
            if ($agency && $team->parent_id !== $agency->id) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_TEAM_MISMATCH',
                        'message' => 'Selected team does not belong to selected agency.',
                    ],
                ], 422);
            }
        }

        if ($actorMembership?->role?->slug === 'agency') {
            if (! $actorMembership->agency_unit_id || ($agency && $agency->id !== $actorMembership->agency_unit_id)) {
                return response()->json([
                    'error' => [
                        'code' => 'ORG_FORBIDDEN',
                        'message' => 'Agency role can only assign units within its own agency.',
                    ],
                ], 403);
            }
        }

        $membership->agency_unit_id = $agency?->id;
        $membership->team_unit_id = $team?->id;
        $membership->save();
        $membership->load(['role', 'agencyUnit', 'teamUnit']);

        return response()->json(['data' => $membership]);
    }
}
