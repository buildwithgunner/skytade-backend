<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\LoginChallenge;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserSignupOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_signup_sends_otp_and_sets_pending_otp(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'abc123',
            'password_confirmation' => 'abc123',
            'role' => 'user',
            'account_type' => 'Individual brokerage',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'challenge_token', 'user'])
            ->assertJsonPath('user.account_status', 'pending_otp');

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'account_status' => 'pending_otp',
        ]);

        $this->assertDatabaseHas('login_challenges', [
            'context' => 'signup_otp',
        ]);

        $challenge = LoginChallenge::firstOrFail();
        $this->assertNotNull($challenge->challenge_token);

        // Verification email should be sent
        $this->assertSame(1, NotificationDelivery::count());
    }

    public function test_user_can_verify_otp_and_activate_account(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
            'account_status' => 'pending_otp',
        ]);

        $challenge = LoginChallenge::create([
            'user_id' => $user->id,
            'context' => 'signup_otp',
            'challenge_token' => 'test-token-uuid',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'challenge_token' => 'test-token-uuid',
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.account_status', 'active');

        $this->assertSame('active', $user->fresh()->account_status);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_cannot_verify_with_invalid_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
            'account_status' => 'pending_otp',
        ]);

        $challenge = LoginChallenge::create([
            'user_id' => $user->id,
            'context' => 'signup_otp',
            'challenge_token' => 'test-token-uuid',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'challenge_token' => 'test-token-uuid',
            'code' => '111111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertSame('pending_otp', $user->fresh()->account_status);
    }

    public function test_user_login_with_pending_otp_returns_202(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => Hash::make('abc123'),
            'account_status' => 'pending_otp',
            'role' => 'user',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'john@example.com',
            'password' => 'abc123',
            'role' => 'user',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['otp_required', 'challenge_token'])
            ->assertJsonPath('otp_required', true);

        $this->assertDatabaseHas('login_challenges', [
            'user_id' => $user->id,
            'context' => 'signup_otp',
        ]);
    }

    public function test_user_can_resend_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'john@example.com',
            'account_status' => 'pending_otp',
        ]);

        $challenge = LoginChallenge::create([
            'user_id' => $user->id,
            'context' => 'signup_otp',
            'challenge_token' => 'test-token-uuid',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/auth/resend-otp', [
            'challenge_token' => 'test-token-uuid',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'challenge_token']);

        $newChallengeToken = $response->json('challenge_token');
        $this->assertNotSame('test-token-uuid', $newChallengeToken);

        $this->assertDatabaseHas('login_challenges', [
            'user_id' => $user->id,
            'challenge_token' => $newChallengeToken,
            'context' => 'signup_otp',
        ]);
    }
}
