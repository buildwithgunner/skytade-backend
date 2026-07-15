<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_create_admin_accounts(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Malicious Operator',
            'email' => 'malicious@example.com',
            'password' => 'StrongPass!234',
            'password_confirmation' => 'StrongPass!234',
            'role' => 'admin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_regular_users_cannot_access_admin_overview(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
        ]);

        Sanctum::actingAs($user, ['investor:*']);

        $this->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_users_cannot_mark_another_users_notification_as_read(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
        ]);

        $notification = Notification::create([
            'audience' => 'user',
            'recipient_user_id' => $otherUser->id,
            'title' => 'Private update',
            'severity' => 'info',
            'type' => 'account_update',
            'data' => ['message' => 'This belongs to another investor.'],
        ]);

        Sanctum::actingAs($user, ['investor:*']);

        $this->postJson("/api/notifications/{$notification->id}/read")
            ->assertForbidden();
    }

    public function test_admin_can_suspend_investor_without_touching_other_admins(): void
    {
        $admin = Admin::factory()->create([
            'account_status' => 'active',
        ]);

        $investor = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
        ]);

        $otherAdmin = Admin::factory()->create([
            'account_status' => 'active',
        ]);

        Sanctum::actingAs($admin, ['admin:*']);

        $this->putJson("/api/admin/users/{$investor->id}/status", [
            'account_status' => 'suspended',
            'reason' => 'Manual AML escalation',
        ])->assertOk()->assertJsonPath('user.account_status', 'suspended');

        $this->assertSame('active', $otherAdmin->fresh()->account_status);
    }
}
