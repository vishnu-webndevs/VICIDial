<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallSession;
use App\Models\Invoice;
use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $query = trim((string) $request->input('q', ''));
        if ($query === '') {
            return response()->json(['data' => []]);
        }

        $limit = min(20, max(1, (int) $request->integer('limit', 8)));
        $like = '%'.$query.'%';

        $calls = CallSession::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('to_number', 'like', $like)->orWhere('from_number', 'like', $like))
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (CallSession $call) => [
                'id' => $call->id,
                'type' => 'call',
                'label' => "{$call->to_number} ({$call->status})",
                'route' => "/calls/{$call->id}",
                'meta' => ['created_at' => $call->created_at?->toISOString()],
            ]);

        $members = Membership::query()
            ->with('user:id,first_name,last_name,email')
            ->where('tenant_id', $tenant->id)
            ->whereHas('user', fn ($q) => $q
                ->where('email', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like))
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Membership $membership) => [
                'id' => $membership->id,
                'type' => 'team_member',
                'label' => trim(($membership->user?->first_name ?? '').' '.($membership->user?->last_name ?? '')),
                'route' => '/team',
                'meta' => ['email' => $membership->user?->email],
            ]);

        $invoices = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q
                ->where('invoice_number', 'like', $like)
                ->orWhere('stripe_invoice_id', 'like', $like))
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'type' => 'invoice',
                'label' => $invoice->invoice_number ?: (string) $invoice->stripe_invoice_id,
                'route' => '/billing',
                'meta' => [
                    'status' => $invoice->status,
                    'total_cents' => $invoice->total_cents,
                    'currency' => $invoice->currency,
                ],
            ]);

        return response()->json([
            'data' => $calls
                ->concat($members)
                ->concat($invoices)
                ->take($limit)
                ->values(),
        ]);
    }
}
