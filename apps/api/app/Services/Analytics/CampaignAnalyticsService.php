<?php

namespace App\Services\Analytics;

use App\Models\CampaignDailyStat;
use App\Models\CampaignHourlyStat;
use App\Models\CallSession;
use App\Models\LeadDisposition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CampaignAnalyticsService
{
    /**
     * @return array<string, float|int>
     */
    public function callSummary(string $tenantId, Carbon $from, Carbon $to): array
    {
        $row = CallSession::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN status IN ('completed','answered','in_progress') THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status IN ('failed','busy','no_answer','timeout','rejected','canceled') THEN 1 ELSE 0 END) as failed")
            ->selectRaw('SUM(duration_seconds) as total_duration_seconds')
            ->first();

        $totalCalls = (int) ($row?->total_calls ?? 0);
        $completed = (int) ($row?->completed ?? 0);
        $failed = (int) ($row?->failed ?? 0);
        $totalDurationSeconds = (int) ($row?->total_duration_seconds ?? 0);

        return [
            'total_calls' => $totalCalls,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate' => $totalCalls > 0 ? round(($completed / $totalCalls) * 100, 2) : 0,
            'total_duration_seconds' => $totalDurationSeconds,
            'avg_duration_seconds' => $totalCalls > 0 ? round($totalDurationSeconds / $totalCalls, 2) : 0,
        ];
    }

    public function refreshAggregates(string $tenantId, Carbon $from, Carbon $to): void
    {
        $driver = DB::connection()->getDriverName();
        $hourBucketExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')";
        $campaignIdExpr = $driver === 'sqlite'
            ? "json_extract(metadata, '$.campaign_id')"
            : "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.campaign_id'))";
        $leadIdExpr = $driver === 'sqlite'
            ? "json_extract(metadata, '$.lead_id')"
            : "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.lead_id'))";

        $hourly = CallSession::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw("{$hourBucketExpr} as bucket_start")
            ->selectRaw("{$campaignIdExpr} as campaign_id")
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN status IN ('completed','answered','in_progress') THEN 1 ELSE 0 END) as connected_calls")
            ->selectRaw("SUM(CASE WHEN status IN ('failed','rejected','timeout') THEN 1 ELSE 0 END) as failed_calls")
            ->selectRaw("SUM(CASE WHEN status = 'no_answer' THEN 1 ELSE 0 END) as no_answer_calls")
            ->selectRaw("SUM(CASE WHEN status = 'busy' THEN 1 ELSE 0 END) as busy_calls")
            ->selectRaw("SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as canceled_calls")
            ->selectRaw('SUM(duration_seconds) as total_duration_seconds')
            ->selectRaw('COUNT(DISTINCT initiated_by) as distinct_agents')
            ->selectRaw("COUNT(DISTINCT {$leadIdExpr}) as distinct_leads")
            ->groupBy('bucket_start', 'campaign_id')
            ->get();

        foreach ($hourly as $row) {
            $bucket = Carbon::parse((string) $row->bucket_start);
            CampaignHourlyStat::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'campaign_id' => $row->campaign_id ?: null,
                    'bucket_start' => $bucket,
                ],
                [
                    'total_calls' => (int) $row->total_calls,
                    'connected_calls' => (int) $row->connected_calls,
                    'failed_calls' => (int) $row->failed_calls,
                    'no_answer_calls' => (int) $row->no_answer_calls,
                    'busy_calls' => (int) $row->busy_calls,
                    'canceled_calls' => (int) $row->canceled_calls,
                    'total_duration_seconds' => (int) $row->total_duration_seconds,
                    'distinct_agents' => (int) $row->distinct_agents,
                    'distinct_leads' => (int) $row->distinct_leads,
                ]
            );
        }

        $daily = CallSession::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw("DATE(created_at) as bucket_date")
            ->selectRaw("{$campaignIdExpr} as campaign_id")
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN status IN ('completed','answered','in_progress') THEN 1 ELSE 0 END) as connected_calls")
            ->selectRaw("SUM(CASE WHEN status IN ('failed','rejected','timeout') THEN 1 ELSE 0 END) as failed_calls")
            ->selectRaw("SUM(CASE WHEN status = 'no_answer' THEN 1 ELSE 0 END) as no_answer_calls")
            ->selectRaw("SUM(CASE WHEN status = 'busy' THEN 1 ELSE 0 END) as busy_calls")
            ->selectRaw("SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as canceled_calls")
            ->selectRaw('SUM(duration_seconds) as total_duration_seconds')
            ->selectRaw('COUNT(DISTINCT initiated_by) as distinct_agents')
            ->selectRaw("COUNT(DISTINCT {$leadIdExpr}) as distinct_leads")
            ->groupBy('bucket_date', 'campaign_id')
            ->get();

        foreach ($daily as $row) {
            CampaignDailyStat::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'campaign_id' => $row->campaign_id ?: null,
                    'bucket_date' => (string) $row->bucket_date,
                ],
                [
                    'total_calls' => (int) $row->total_calls,
                    'connected_calls' => (int) $row->connected_calls,
                    'failed_calls' => (int) $row->failed_calls,
                    'no_answer_calls' => (int) $row->no_answer_calls,
                    'busy_calls' => (int) $row->busy_calls,
                    'canceled_calls' => (int) $row->canceled_calls,
                    'total_duration_seconds' => (int) $row->total_duration_seconds,
                    'distinct_agents' => (int) $row->distinct_agents,
                    'distinct_leads' => (int) $row->distinct_leads,
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function campaignMetrics(string $tenantId, Carbon $from, Carbon $to, ?string $campaignId): array
    {
        $query = CampaignDailyStat::query()
            ->where('tenant_id', $tenantId)
            ->where('bucket_date', '>=', $from->toDateString())
            ->where('bucket_date', '<=', $to->toDateString())
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->selectRaw('campaign_id')
            ->selectRaw('SUM(total_calls) as total_calls')
            ->selectRaw('SUM(connected_calls) as connected_calls')
            ->selectRaw('SUM(failed_calls + no_answer_calls + busy_calls + canceled_calls) as unsuccessful_calls')
            ->selectRaw('SUM(total_duration_seconds) as total_duration_seconds')
            ->groupBy('campaign_id')
            ->get();

        return $query->map(function ($row) {
            $total = (int) $row->total_calls;
            $connected = (int) $row->connected_calls;

            return [
                'campaign_id' => $row->campaign_id,
                'total_calls' => $total,
                'connected_calls' => $connected,
                'unsuccessful_calls' => (int) $row->unsuccessful_calls,
                'success_rate' => $total > 0 ? round(($connected / $total) * 100, 2) : 0,
                'total_duration_seconds' => (int) $row->total_duration_seconds,
                'avg_duration_seconds' => $total > 0
                    ? round(((int) $row->total_duration_seconds) / $total, 2)
                    : 0,
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function agentLeaderboard(string $tenantId, Carbon $from, Carbon $to, ?string $agentId): array
    {
        $rows = CallSession::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('initiated_by')
            ->when($agentId, fn ($q) => $q->where('initiated_by', $agentId))
            ->selectRaw('initiated_by as agent_id')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN status IN ('completed','answered','in_progress') THEN 1 ELSE 0 END) as connected_calls")
            ->selectRaw('SUM(duration_seconds) as total_duration_seconds')
            ->groupBy('initiated_by')
            ->orderByDesc('connected_calls')
            ->orderByDesc('total_calls')
            ->get();

        return $rows->map(function ($row) {
            $total = (int) $row->total_calls;
            $connected = (int) $row->connected_calls;

            return [
                'agent_id' => $row->agent_id,
                'total_calls' => $total,
                'connected_calls' => $connected,
                'success_rate' => $total > 0 ? round(($connected / $total) * 100, 2) : 0,
                'avg_duration_seconds' => $total > 0 ? round(((int) $row->total_duration_seconds) / $total, 2) : 0,
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trends(string $tenantId, Carbon $from, Carbon $to, string $groupBy): array
    {
        if ($groupBy === 'hour') {
            $rows = CampaignHourlyStat::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('bucket_start', [$from, $to])
                ->selectRaw('bucket_start as bucket')
                ->selectRaw('SUM(total_calls) as total_calls')
                ->selectRaw('SUM(connected_calls) as connected_calls')
                ->selectRaw('SUM(failed_calls + no_answer_calls + busy_calls + canceled_calls) as unsuccessful_calls')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
        } else {
            $rows = CampaignDailyStat::query()
                ->where('tenant_id', $tenantId)
                ->whereBetween('bucket_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('bucket_date as bucket')
                ->selectRaw('SUM(total_calls) as total_calls')
                ->selectRaw('SUM(connected_calls) as connected_calls')
                ->selectRaw('SUM(failed_calls + no_answer_calls + busy_calls + canceled_calls) as unsuccessful_calls')
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
        }

        return $rows->map(fn ($row) => [
            'bucket' => (string) $row->bucket,
            'total_calls' => (int) $row->total_calls,
            'connected_calls' => (int) $row->connected_calls,
            'unsuccessful_calls' => (int) $row->unsuccessful_calls,
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, int|string|float>>
     */
    public function hourlyHeatmap(string $tenantId, Carbon $from, Carbon $to): array
    {
        $driver = DB::connection()->getDriverName();
        $hourExpr = $driver === 'sqlite'
            ? "CAST(strftime('%H', bucket_start) AS INTEGER)"
            : 'HOUR(bucket_start)';
        $weekdayExpr = $driver === 'sqlite'
            ? "CAST(strftime('%w', bucket_start) AS INTEGER)"
            : '(DAYOFWEEK(bucket_start) - 1)';

        $rows = CampaignHourlyStat::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('bucket_start', [$from, $to])
            ->selectRaw("{$hourExpr} as hour_of_day")
            ->selectRaw("{$weekdayExpr} as weekday_index")
            ->selectRaw('SUM(total_calls) as total_calls')
            ->selectRaw('SUM(connected_calls) as connected_calls')
            ->groupBy('weekday_index', 'hour_of_day')
            ->orderBy('weekday_index')
            ->orderBy('hour_of_day')
            ->get();

        $weekdayMap = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return $rows->map(function ($row) use ($weekdayMap) {
            $total = (int) $row->total_calls;
            $connected = (int) $row->connected_calls;
            $hour = (int) $row->hour_of_day;
            $weekdayIndex = (int) $row->weekday_index;
            $day = $weekdayMap[$weekdayIndex] ?? 'Mon';

            return [
                'day' => $day,
                'hour' => $hour,
                'calls' => $total,
                'connected' => $connected,
                'total_calls' => $total,
                'connected_calls' => $connected,
                'success_rate' => $total > 0 ? round(($connected / $total) * 100, 2) : 0,
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function agentScorecards(string $tenantId, Carbon $from, Carbon $to): array
    {
        $callRows = CallSession::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('initiated_by')
            ->selectRaw('initiated_by as agent_id')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as connected_calls")
            ->selectRaw('SUM(duration_seconds) as total_duration_seconds')
            ->groupBy('initiated_by')
            ->get()
            ->keyBy('agent_id');

        $dispositionRows = LeadDisposition::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('agent_id')
            ->selectRaw('agent_id')
            ->selectRaw("SUM(CASE WHEN disposition = 'converted' THEN 1 ELSE 0 END) as converted")
            ->selectRaw("SUM(CASE WHEN disposition = 'interested' THEN 1 ELSE 0 END) as interested")
            ->selectRaw("SUM(CASE WHEN disposition = 'dnc' THEN 1 ELSE 0 END) as dnc")
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        return $callRows->map(function ($row, $agentId) use ($dispositionRows) {
            $total = (int) $row->total_calls;
            $connected = (int) $row->connected_calls;
            $disp = $dispositionRows->get($agentId);

            return [
                'agent_id' => $agentId,
                'total_calls' => $total,
                'connected_calls' => $connected,
                'success_rate' => $total > 0 ? round(($connected / $total) * 100, 2) : 0,
                'avg_duration_seconds' => $total > 0 ? round(((int) $row->total_duration_seconds) / $total, 2) : 0,
                'interested' => (int) ($disp->interested ?? 0),
                'converted' => (int) ($disp->converted ?? 0),
                'dnc' => (int) ($disp->dnc ?? 0),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPerformance(string $tenantId, Carbon $from, Carbon $to): array
    {
        return DB::table('lead_lists')
            ->leftJoin('lead_list_lead', 'lead_list_lead.lead_list_id', '=', 'lead_lists.id')
            ->leftJoin('lead_dispositions', function ($join) use ($from, $to) {
                $join->on('lead_dispositions.lead_id', '=', 'lead_list_lead.lead_id')
                    ->whereBetween('lead_dispositions.created_at', [$from, $to]);
            })
            ->where('lead_lists.tenant_id', $tenantId)
            ->selectRaw('lead_lists.id as list_id')
            ->selectRaw('lead_lists.name as list_name')
            ->selectRaw('COUNT(DISTINCT lead_list_lead.lead_id) as leads')
            ->selectRaw("SUM(CASE WHEN lead_dispositions.disposition = 'interested' THEN 1 ELSE 0 END) as interested")
            ->selectRaw("SUM(CASE WHEN lead_dispositions.disposition = 'converted' THEN 1 ELSE 0 END) as converted")
            ->selectRaw("SUM(CASE WHEN lead_dispositions.disposition = 'dnc' THEN 1 ELSE 0 END) as dnc")
            ->groupBy('lead_lists.id', 'lead_lists.name')
            ->orderBy('lead_lists.name')
            ->get()
            ->map(function ($row) {
                $leads = (int) $row->leads;
                $converted = (int) $row->converted;

                return [
                    'list_id' => $row->list_id,
                    'list_name' => $row->list_name,
                    'leads' => $leads,
                    'interested' => (int) $row->interested,
                    'converted' => $converted,
                    'dnc' => (int) $row->dnc,
                    'conversion_rate' => $leads > 0 ? round(($converted / $leads) * 100, 2) : 0,
                ];
            })
            ->values()
            ->all();
    }
}
