<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $logs = AuditLog::query()
            ->with('actor:id,email,first_name,last_name')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'pagination' => [
                    'total' => $logs->total(),
                    'per_page' => $logs->perPage(),
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                ],
            ],
        ]);
    }
}
