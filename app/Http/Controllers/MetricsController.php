<?php

namespace App\Http\Controllers;

use App\Models\HazardReport;
use App\Models\HazardStatus;
use App\Models\HazardStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MetricsController extends Controller
{
    public function summary(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $statusIds = HazardStatus::query()->pluck('id', 'key');
        $closedId = $statusIds['closed'] ?? null;
        $resolvedId = $statusIds['resolved'] ?? null;

        $pendingCount = HazardReport::query()
            ->when($closedId, fn ($q) => $q->where('current_status_id', '!=', $closedId))
            ->count();

        $closedCount = $closedId
            ? HazardReport::query()->where('current_status_id', $closedId)->count()
            : 0;

        // Average resolution time (hours) from creation to first "resolved" or "closed".
        $avgResolutionHours = null;
        if ($resolvedId || $closedId) {
            $terminalIds = array_values(array_filter([$resolvedId, $closedId]));

            $rows = DB::table('hazard_reports as hr')
                ->join('hazard_status_histories as hsh', 'hsh.hazard_report_id', '=', 'hr.id')
                ->whereIn('hsh.to_status_id', $terminalIds)
                ->select([
                    'hr.id',
                    DB::raw('MIN(hsh.created_at) as terminal_at'),
                    'hr.created_at as created_at',
                ])
                ->groupBy('hr.id', 'hr.created_at')
                ->get();

            if ($rows->count() > 0) {
                $totalHours = 0.0;
                foreach ($rows as $r) {
                    $created = new \DateTime($r->created_at);
                    $terminal = new \DateTime($r->terminal_at);
                    $diffSeconds = max(0, $terminal->getTimestamp() - $created->getTimestamp());
                    $totalHours += $diffSeconds / 3600.0;
                }
                $avgResolutionHours = round($totalHours / $rows->count(), 2);
            } else {
                $avgResolutionHours = 0;
            }
        }

        return response()->json([
            'pending_count' => $pendingCount,
            'closed_count' => $closedCount,
            'avg_resolution_hours' => $avgResolutionHours,
        ]);
    }

    public function dashboard(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $statusIds = HazardStatus::query()->pluck('id', 'key');
        $closedId = $statusIds['closed'] ?? null;
        $resolvedId = $statusIds['resolved'] ?? null;

        // Base query: admins see all reports, reporters only see their own.
        $baseQuery = HazardReport::query();
        if ($user->role !== User::ROLE_ADMIN) {
            $baseQuery->where('reporter_user_id', $user->id);
        }

        $totalCount = (clone $baseQuery)->count();

        $pendingQuery = clone $baseQuery;
        if ($closedId) {
            $pendingQuery->where('current_status_id', '!=', $closedId);
        }
        if ($resolvedId) {
            $pendingQuery->where('current_status_id', '!=', $resolvedId);
        }
        $pendingCount = $pendingQuery->count();

        $resolvedCount = 0;
        if ($resolvedId) {
            $resolvedQuery = clone $baseQuery;
            $resolvedQuery->where('current_status_id', $resolvedId);
            $resolvedCount = $resolvedQuery->count();
        }

        // Build simple monthly series for the last 6 months (including current).
        $months = [];
        $now = Carbon::now();
        for ($i = 5; $i >= 0; $i--) {
            $m = (clone $now)->subMonths($i)->startOfMonth();
            $months[] = $m;
        }

        $series = [];
        foreach ($months as $month) {
            $start = (clone $month)->startOfMonth();
            $end = (clone $month)->endOfMonth();

            $label = $start->format('M');

            $submitted = (clone $baseQuery)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $openForMonth = (clone $baseQuery)
                ->when($closedId, fn ($q) => $q->where('current_status_id', '!=', $closedId))
                ->when($resolvedId, fn ($q) => $q->where('current_status_id', '!=', $resolvedId))
                ->where('created_at', '<=', $end)
                ->count();

            $resolvedForMonth = 0;
            if ($resolvedId || $closedId) {
                $terminalIds = array_values(array_filter([$resolvedId, $closedId]));

                $resolvedForMonth = DB::table('hazard_status_histories as hsh')
                    ->join('hazard_reports as hr', 'hr.id', '=', 'hsh.hazard_report_id')
                    ->whereIn('hsh.to_status_id', $terminalIds)
                    ->where('hsh.created_at', '<=', $end)
                    ->when(
                        $user->role !== User::ROLE_ADMIN,
                        fn ($q) => $q->where('hr.reporter_user_id', $user->id)
                    )
                    ->distinct('hr.id')
                    ->count('hr.id');
            }

            $series[] = [
                'name' => $label,
                'submitted' => $submitted,
                'pending' => $openForMonth,
                'resolved' => $resolvedForMonth,
            ];
        }

        return response()->json([
            'total_count' => $totalCount,
            'pending_count' => $pendingCount,
            'resolved_count' => $resolvedCount,
            'series' => $series,
        ]);
    }
}

