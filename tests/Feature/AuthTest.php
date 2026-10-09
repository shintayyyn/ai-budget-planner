<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_returns_token_and_seeds_categories(): void
    {
        $res = $this->postJson('/api/register', ['name' => 'Ana', 'email' => 'Ana@Example.test', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'onboarded']]);

        $user = User::firstWhere('email', 'ana@example.test');
        $this->assertGreaterThanOrEqual(10, $user->categories()->count());

        $this->withToken($res->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('email', 'ana@example.test');
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create(['email' => 'bo@example.test']);

        $this->postJson('/api/login', ['email' => 'bo@example.test', 'password' => 'nope'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'bo@example.test', 'password' => 'password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_registration_rejects_weak_passwords_bad_names_and_mismatched_confirmation(): void
    {
        $base = ['name' => 'Ana Cruz', 'email' => 'ana@example.test', 'password' => 'secret123', 'password_confirmation' => 'secret123'];

        $this->postJson('/api/register', ['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'] + $base)->assertJsonValidationErrors('password');
        $this->postJson('/api/register', ['password' => '12345678', 'password_confirmation' => '12345678'] + $base)->assertJsonValidationErrors('password');
        $this->postJson('/api/register', ['password_confirmation' => 'secret124'] + $base)->assertJsonValidationErrors('password');
        $this->postJson('/api/register', ['name' => '<script>'] + $base)->assertJsonValidationErrors('name');
        $this->postJson('/api/register', ['email' => 'not-an-email'] + $base)->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 0);
    }
}
