<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppOpenEvent;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppOpenReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : now()->startOfDay();
        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : now()->endOfDay();

        $events = AppOpenEvent::query()
            ->whereBetween('opened_at', [$from, $to])
            ->orderBy('opened_at')
            ->get();

        $totals = $this->buildTotals($events);
        $timeline = $this->buildTimeline($events, $from, $to);

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => $totals,
            'timeline' => $timeline,
        ]);
    }

    private function buildTotals($events): array
    {
        $uniqueOpeners = $events->unique(fn (AppOpenEvent $event) => $this->actorKey($event));
        $userEvents = $events->where('actor_type', 'user');
        $guestEvents = $events->where('actor_type', 'guest');

        return [
            'unique_openers' => $uniqueOpeners->count(),
            'total_opens' => $events->count(),
            'unique_users' => $userEvents->unique(fn (AppOpenEvent $event) => (string) $event->user_id)->count(),
            'unique_guests' => $guestEvents->unique(fn (AppOpenEvent $event) => (string) $event->guest_uuid)->count(),
            'user_opens' => $userEvents->count(),
            'guest_opens' => $guestEvents->count(),
        ];
    }

    private function buildTimeline($events, Carbon $from, Carbon $to): array
    {
        $grouped = $events->groupBy(fn (AppOpenEvent $event) => $event->opened_at->toDateString());
        $timeline = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay()) as $date) {
            $key = $date->toDateString();
            $dayEvents = $grouped->get($key, collect());
            $totals = $this->buildTotals($dayEvents);
            $timeline[] = array_merge([
                'date' => $key,
            ], $totals);
        }

        return $timeline;
    }

    private function actorKey(AppOpenEvent $event): string
    {
        return $event->actor_type === 'guest'
            ? 'guest:' . (string) $event->guest_uuid
            : 'user:' . (string) $event->user_id;
    }
}
