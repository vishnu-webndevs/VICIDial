<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataSubjectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GovernanceComplianceController extends Controller
{
    public function dsrIndex(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $items = DataSubjectRequest::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function dsrStore(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'request_type' => ['required', 'in:export,erase'],
            'subject_type' => ['required', 'in:phone,email'],
            'subject_value' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ((string) $validated['tenant_id'] !== $tenant->id) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TENANT_MISMATCH',
                    'message' => 'tenant_id in payload does not match authenticated tenant.',
                ],
            ], 403);
        }

        $dsr = DataSubjectRequest::query()->create([
            'tenant_id' => $tenant->id,
            'request_type' => (string) $validated['request_type'],
            'subject_type' => (string) $validated['subject_type'],
            'subject_value' => (string) $validated['subject_value'],
            'status' => 'queued',
            'requested_by' => $request->user()?->id,
            'metadata' => [
                'reason' => $validated['reason'] ?? null,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $dsr,
        ], 201);
    }

    public function dsrApprove(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $dsr = DataSubjectRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if (! in_array($dsr->status, ['queued', 'pending_approval'], true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DSR_NOT_APPROVABLE',
                    'message' => 'DSR cannot be approved in its current status.',
                ],
            ], 409);
        }

        $dsr->status = 'approved';
        $dsr->approved_by = $request->user()?->id;
        $dsr->approved_at = now();
        $dsr->save();

        return response()->json(['success' => true, 'data' => $dsr]);
    }

    public function dsrDownload(Request $request, string $id): StreamedResponse
    {
        $tenant = $request->attributes->get('tenant');
        $dsr = DataSubjectRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $path = (string) ($dsr->result_path ?? '');
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->streamDownload(function () use ($path) {
            echo Storage::disk('local')->get($path);
        }, basename($path), ['Content-Type' => 'application/json']);
    }
}

