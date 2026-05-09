<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CampaignAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly CampaignAnalyticsService $analyticsService
    ) {
    }

    public function campaigns(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);
        $campaignId = $request->input('campaign_id');

        $this->analyticsService->refreshAggregates($tenant->id, $from, $to);
        $data = $this->analyticsService->campaignMetrics($tenant->id, $from, $to, $campaignId ?: null);

        return response()->json(['data' => $data]);
    }

    public function agents(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);
        $agentId = $request->input('agent_id');

        $data = $this->analyticsService->agentLeaderboard($tenant->id, $from, $to, $agentId ?: null);

        return response()->json(['data' => $data]);
    }

    public function calls(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);
        $summary = $this->analyticsService->callSummary($tenant->id, $from, $to);

        return response()->json([
            'data' => [
                'summary' => $summary,
            ],
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);
        $groupBy = $request->input('group_by', 'day');
        if (! in_array($groupBy, ['hour', 'day'], true)) {
            $groupBy = 'day';
        }

        $this->analyticsService->refreshAggregates($tenant->id, $from, $to);
        $data = $this->analyticsService->trends($tenant->id, $from, $to, $groupBy);

        return response()->json(['data' => $data]);
    }

    public function heatmap(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);

        $this->analyticsService->refreshAggregates($tenant->id, $from, $to);
        $data = $this->analyticsService->hourlyHeatmap($tenant->id, $from, $to);

        return response()->json(['data' => $data]);
    }

    public function scorecards(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);
        $data = $this->analyticsService->agentScorecards($tenant->id, $from, $to);

        return response()->json(['data' => $data]);
    }

    public function lists(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        [$from, $to] = $this->resolveRange($request);
        $data = $this->analyticsService->listPerformance($tenant->id, $from, $to);

        return response()->json(['data' => $data]);
    }

    public function dashboardSummary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $tenantId = $tenant->id;
        $windowStart = now()->subHours(11)->startOfHour();
        $windowEnd = now()->endOfHour();
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $aggregates = DB::table('call_sessions as cs')
            ->where('cs.tenant_id', $tenantId)
            ->selectRaw(
                "SUM(CASE WHEN cs.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as calls_today",
                [$todayStart, $todayEnd]
            )
            ->selectRaw(
                "SUM(CASE WHEN cs.created_at BETWEEN ? AND ? AND cs.status IN ('completed','answered','in_progress') THEN 1 ELSE 0 END) as connected_today",
                [$todayStart, $todayEnd]
            )
            ->selectRaw(
                "(SELECT COUNT(*) FROM campaigns c WHERE c.tenant_id = ? AND c.status IN ('scheduled', 'running')) as active_campaigns",
                [$tenantId]
            )
            ->selectRaw(
                "(SELECT COUNT(*) FROM agent_sessions a WHERE a.tenant_id = ? AND a.status IN ('available', 'busy')) as agents_online",
                [$tenantId]
            )
            ->first();

        $hourlyRows = DB::table('call_sessions')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->selectRaw(
                DB::connection()->getDriverName() === 'sqlite'
                    ? "strftime('%Y-%m-%d %H:00:00', created_at) as hour_bucket, COUNT(*) as calls"
                    : "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour_bucket, COUNT(*) as calls"
            )
            ->groupBy('hour_bucket')
            ->orderBy('hour_bucket')
            ->get();

        $hourlyCounts = [];
        foreach ($hourlyRows as $row) {
            $hourlyCounts[(string) $row->hour_bucket] = (int) $row->calls;
        }

        $callsPerHour = [];
        $cursor = $windowStart->copy();
        while ($cursor->lte($windowEnd)) {
            $bucket = $cursor->format('Y-m-d H:00:00');
            $callsPerHour[] = [
                'hour' => (int) $cursor->format('G'),
                'calls' => (int) ($hourlyCounts[$bucket] ?? 0),
            ];
            $cursor->addHour();
        }

        $callsToday = (int) ($aggregates->calls_today ?? 0);
        $connectedToday = (int) ($aggregates->connected_today ?? 0);
        $conversionRate = $callsToday > 0 ? round(($connectedToday / $callsToday) * 100, 2) : 0;

        return response()->json([
            'data' => [
                'calls_today' => $callsToday,
                'active_campaigns' => (int) ($aggregates->active_campaigns ?? 0),
                'agents_online' => (int) ($aggregates->agents_online ?? 0),
                'conversion_rate' => $conversionRate,
                'calls_per_hour' => $callsPerHour,
            ],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $from = Carbon::parse((string) $request->input('from', now()->subDays(7)->toDateString()))->startOfDay();
        $to = Carbon::parse((string) $request->input('to', now()->toDateString()))->endOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
