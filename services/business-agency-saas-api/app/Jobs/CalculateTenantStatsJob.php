<?php

namespace App\Jobs;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CalculateTenantStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $tenantId)
    {}

    public function handle(): void
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        $aggregates = Lead::where('tenant_id', $this->tenantId)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) FILTER (WHERE status = 'new') as new_leads")
            ->selectRaw("count(*) FILTER (WHERE status = 'closed' and won = true) as closed_leads")
            ->selectRaw("count(*) FILTER (WHERE temperature = 'hot') as hot_leads")
            ->selectRaw("count(*) FILTER (WHERE status = 'new' AND created_at < ?) as stale_leads", [$now->subDay()])
            ->selectRaw('count(*) FILTER (WHERE created_at >= ?) as this_month_leads', [$startOfMonth])
            ->selectRaw('count(*) FILTER (WHERE created_at >= ? AND created_at <= ?) as last_month_leads', [$startOfLastMonth, $endOfLastMonth])
            ->first();

        $dailyTrend = Lead::where('tenant_id', $this->tenantId)
            ->where('created_at', '>=', $now->copy()->subDays(6)->startOfDay())
            ->selectRaw('created_at::date as date, count(*) as count')
            ->groupByRaw('created_at::date')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->date => $item->count]);

        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->format('Y-m-d');
            $chartData[$date] = $dailyTrend[$date] ?? 0;
        }

        $topSources = Lead::where('tenant_id', $this->tenantId)
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->orderByDesc('count')
            ->limit(4)
            ->get();

        $conversionRate = $aggregates->total > 0
            ? round(($aggregates->closed_leads / $aggregates->total) * 100, 1)
            : 0;

        $growth = 0;
        if ($aggregates->last_month_leads > 0) {
            $growth = (($aggregates->this_month_leads - $aggregates->last_month_leads) / $aggregates->last_month_leads) * 100;
        } elseif ($aggregates->this_month_leads > 0) {
            $growth = 100;
        }

        $data = [
            'overview' => [
                'total_leads' => $aggregates->total,
                'new_leads' => $aggregates->new_leads,
                'hot_leads' => $aggregates->hot_leads,
                'conversion_rate' => $conversionRate,
                'stale_leads' => $aggregates->stale_leads,
            ],
            'growth' => [
                'this_month' => $aggregates->this_month_leads,
                'last_month' => $aggregates->last_month_leads,
                'percentage' => round($growth, 1),
            ],
            'chart_data' => $chartData,
            'top_sources' => $topSources,
            'leads_search_filters' => [
                'temperatures' => ['cold', 'warm', 'hot'],
                'sources' => $topSources->pluck('source'),
            ],
        ];

        $cacheKey = "dashboard_stats_{$this->tenantId}";
        Cache::put($cacheKey, $data, 86400); // cache for 1 day, it will be background refreshed
        Cache::put("{$cacheKey}_last_updated", time(), 86400);
    }
}
