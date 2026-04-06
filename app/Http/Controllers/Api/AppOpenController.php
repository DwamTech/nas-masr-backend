<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppOpenEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppOpenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json([
                'message' => 'Unable to resolve the current app user.',
            ], 401);
        }

        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'in:launch,resume'],
            'opened_at' => ['nullable', 'date'],
        ]);

        $event = AppOpenEvent::query()->firstOrNew([
            'event_id' => $data['event_id'],
        ]);

        if ($event->exists) {
            return response()->json([
                'message' => 'App open event already recorded.',
                'recorded' => false,
                'event_id' => $event->event_id,
            ]);
        }

        $isGuest = ($actor->role ?? null) === 'guest';
        $event->fill([
            'actor_type' => $isGuest ? 'guest' : 'user',
            'user_id' => $actor->id,
            'guest_uuid' => $isGuest ? (string) ($actor->guest_uuid ?? '') : null,
            'source' => $data['source'] ?? 'launch',
            'opened_at' => isset($data['opened_at']) ? Carbon::parse($data['opened_at']) : now(),
        ]);
        $event->event_id = $data['event_id'];
        $event->save();

        return response()->json([
            'message' => 'App open event recorded successfully.',
            'recorded' => true,
            'event_id' => $event->event_id,
        ], 201);
    }
}
