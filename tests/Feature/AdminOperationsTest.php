<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\InvestmentRequest;
use App\Models\LedgerEntry;
use App\Models\LoginChallenge;
use App\Models\MoneyMovement;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_requires_mfa_challenge_and_verification(): void
    {
        Mail::fake();

        $admin = Admin::factory()->create([
            'account_status' => 'active',
            'password' => 'StrongPass!234',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'StrongPass!234',
            'role' => 'admin',
        ])->assertStatus(202)
            ->assertJsonPath('mfa_required', true);

        $challenge = LoginChallenge::firstOrFail();
        $challenge->forceFill([
            'code_hash' => Hash::make('123456'),
        ])->save();

        $this->assertSame(2, NotificationDelivery::count());

        $this->postJson('/api/auth/admin/verify-mfa', [
            'challenge_token' => $challenge->challenge_token,
            'code' => '123456',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user', 'permissions']);

        $this->assertNotNull($admin->fresh()->last_mfa_at);
    }

    public function test_high_risk_requests_require_dual_approval_and_post_to_ledger(): void
    {
        $primaryAdmin = Admin::factory()->create([
            'account_status' => 'active',
        ]);

        $secondaryAdmin = Admin::factory()->create([
            'account_status' => 'active',
        ]);

        $investor = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
            'kyc_completed' => true,
            'suitability_completed' => true,
        ]);

        $request = InvestmentRequest::create([
            'user_id' => $investor->id,
            'amount' => 25000,
            'asset_type' => 'Cryptocurrencies',
            'funding_source' => 'Wire Transfer',
            'frequency' => 'one-time',
            'attested' => true,
            'status' => 'pending',
            'requires_dual_approval' => true,
            'approval_state' => 'pending',
            'risk_score' => 80,
            'risk_flags' => ['high_amount', 'volatile_asset_class'],
        ]);

        Sanctum::actingAs($primaryAdmin, ['admin:*']);

        $this->putJson("/api/admin/requests/{$request->id}/status", [
            'status' => 'approved',
            'review_notes' => 'Primary approval complete.',
        ])->assertOk()
            ->assertJsonPath('request.status', 'awaiting_secondary_approval')
            ->assertJsonPath('request.approval_count', 1);

        $this->assertSame(0, MoneyMovement::count());

        $this->putJson("/api/admin/requests/{$request->id}/status", [
            'status' => 'approved',
            'review_notes' => 'Trying to self-approve twice.',
        ])->assertStatus(422);

        Sanctum::actingAs($secondaryAdmin, ['admin:*']);

        $this->putJson("/api/admin/requests/{$request->id}/status", [
            'status' => 'approved',
            'review_notes' => 'Secondary approval complete.',
        ])->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.approval_count', 2);

        $movement = MoneyMovement::firstOrFail();
        $this->assertSame('posted', $movement->status);
        $this->assertSame(2, LedgerEntry::where('money_movement_id', $movement->id)->count());
    }

    public function test_money_movements_can_be_posted_and_reconciled(): void
    {
        Mail::fake();

        $admin = Admin::factory()->create([
            'account_status' => 'active',
        ]);

        $investor = User::factory()->create([
            'role' => 'user',
            'account_status' => 'active',
            'phone' => '+15555550101',
            'push_channel_key' => 'device-token-1',
            'notification_channels' => ['email', 'sms', 'push'],
        ]);

        Sanctum::actingAs($admin, ['admin:*']);

        $storeResponse = $this->postJson('/api/admin/money-movements', [
            'user_id' => $investor->id,
            'type' => 'deposit',
            'amount' => 1500,
            'currency' => 'usd',
            'external_reference' => 'BANK-001',
            'description' => 'Initial funding',
        ])->assertCreated();

        $movementId = $storeResponse->json('movement.id');

        $this->putJson("/api/admin/money-movements/{$movementId}/approve")
            ->assertOk()
            ->assertJsonPath('movement.status', 'posted');

        $this->postJson("/api/admin/money-movements/{$movementId}/reconcile", [
            'external_amount' => 1500,
            'external_reference' => 'BANK-001',
            'notes' => 'Matched against bank statement.',
        ])->assertOk()
            ->assertJsonPath('reconciliation.status', 'matched')
            ->assertJsonPath('movement.status', 'reconciled');

        $this->assertGreaterThanOrEqual(6, NotificationDelivery::count());
    }
}
