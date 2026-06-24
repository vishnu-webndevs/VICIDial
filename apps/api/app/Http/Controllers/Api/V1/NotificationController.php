<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $userId = $request->user()?->id;
        $notifications = Notification::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'conversation_reply')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'pagination' => [
                    'total' => $notifications->total(),
                    'per_page' => $notifications->perPage(),
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                ],
                'unread_count' => Notification::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('type', 'conversation_reply')
                    ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $userId = $request->user()?->id;
        $notification = Notification::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->where('type', 'conversation_reply')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->firstOrFail();

        $notification->read_at = now();
        $notification->save();

        return response()->json(['data' => $notification]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $userId = $request->user()?->id;

        Notification::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'conversation_reply')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
