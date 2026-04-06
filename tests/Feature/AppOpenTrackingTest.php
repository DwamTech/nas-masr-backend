<?php

namespace Tests\Feature;

use App\Models\AppOpenEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppOpenTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'TestDataSeeder']);
    }

    public function test_authenticated_user_open_event_is_recorded_idempotently(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'role' => 'user',
        ]);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/app-opens', [
                'event_id' => 'evt-auth-1',
                'source' => 'launch',
                'opened_at' => '2026-04-06T09:15:00+02:00',
            ]);

        $first->assertCreated()
            ->assertJson([
                'recorded' => true,
                'event_id' => 'evt-auth-1',
            ]);

        $duplicate = $this->actingAs($user, 'sanctum')
            ->postJson('/api/app-opens', [
                'event_id' => 'evt-auth-1',
                'source' => 'launch',
            ]);

        $duplicate->assertOk()
            ->assertJson([
                'recorded' => false,
                'event_id' => 'evt-auth-1',
            ]);

        $this->assertDatabaseCount('app_open_events', 1);
        $this->assertDatabaseHas('app_open_events', [
            'event_id' => 'evt-auth-1',
            'actor_type' => 'user',
            'user_id' => $user->id,
            'guest_uuid' => null,
            'source' => 'launch',
        ]);
    }

    public function test_guest_open_event_is_recorded_via_guest_header(): void
    {
        $guest = User::factory()->create([
            'status' => 'active',
            'role' => 'guest',
            'guest_uuid' => 'guest-uuid-123',
        ]);

        $response = $this->postJson(
            '/api/app-opens',
            [
                'event_id' => 'evt-guest-1',
                'source' => 'resume',
                'opened_at' => '2026-04-06T10:30:00+02:00',
            ],
            ['X-Guest-Uuid' => $guest->guest_uuid]
        );

        $response->assertCreated()
            ->assertJson([
                'recorded' => true,
                'event_id' => 'evt-guest-1',
            ]);

        $this->assertDatabaseHas('app_open_events', [
            'event_id' => 'evt-guest-1',
            'actor_type' => 'guest',
            'user_id' => $guest->id,
            'guest_uuid' => $guest->guest_uuid,
            'source' => 'resume',
        ]);
    }

    public function test_admin_report_returns_unique_openers_total_opens_and_daily_breakdown(): void
    {
        $admin = User::factory()->create([
            'status' => 'active',
            'role' => 'admin',
        ]);
        $user = User::factory()->create([
            'status' => 'active',
            'role' => 'user',
        ]);
        $guest = User::factory()->create([
            'status' => 'active',
            'role' => 'guest',
            'guest_uuid' => 'guest-uuid-summary',
        ]);

        foreach ([
            [
                'event_id' => 'evt-user-day1-a',
                'actor_type' => 'user',
                'user_id' => $user->id,
                'guest_uuid' => null,
                'source' => 'launch',
                'opened_at' => '2026-04-06 08:00:00',
            ],
            [
                'event_id' => 'evt-user-day1-b',
                'actor_type' => 'user',
                'user_id' => $user->id,
                'guest_uuid' => null,
                'source' => 'resume',
                'opened_at' => '2026-04-06 14:00:00',
            ],
            [
                'event_id' => 'evt-user-day2',
                'actor_type' => 'user',
                'user_id' => $user->id,
                'guest_uuid' => null,
                'source' => 'launch',
                'opened_at' => '2026-04-07 09:00:00',
            ],
            [
                'event_id' => 'evt-guest-day1-a',
                'actor_type' => 'guest',
                'user_id' => $guest->id,
                'guest_uuid' => $guest->guest_uuid,
                'source' => 'launch',
                'opened_at' => '2026-04-06 11:00:00',
            ],
            [
                'event_id' => 'evt-guest-day1-b',
                'actor_type' => 'guest',
                'user_id' => $guest->id,
                'guest_uuid' => $guest->guest_uuid,
                'source' => 'resume',
                'opened_at' => '2026-04-06 18:00:00',
            ],
        ] as $event) {
            AppOpenEvent::query()->create($event);
        }

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/reports/app-opens?from=2026-04-06&to=2026-04-07');

        $response->assertOk()
            ->assertJson([
                'period' => [
                    'from' => '2026-04-06',
                    'to' => '2026-04-07',
                ],
                'totals' => [
                    'unique_openers' => 2,
                    'total_opens' => 5,
                    'unique_users' => 1,
                    'unique_guests' => 1,
                    'user_opens' => 3,
                    'guest_opens' => 2,
                ],
            ]);

        $timeline = collect($response->json('timeline'))->keyBy('date');

        $this->assertSame(2, $timeline['2026-04-06']['unique_openers']);
        $this->assertSame(4, $timeline['2026-04-06']['total_opens']);
        $this->assertSame(1, $timeline['2026-04-06']['unique_users']);
        $this->assertSame(1, $timeline['2026-04-06']['unique_guests']);
        $this->assertSame(1, $timeline['2026-04-07']['unique_openers']);
        $this->assertSame(1, $timeline['2026-04-07']['total_opens']);
        $this->assertSame(1, $timeline['2026-04-07']['unique_users']);
        $this->assertSame(0, $timeline['2026-04-07']['unique_guests']);
    }
}
