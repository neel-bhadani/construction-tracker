<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_page_renders_on_the_sign_in_tab(): void
    {
        $this->get('/login')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('tab', 'signin'));
    }

    public function test_user_signs_in_and_lands_on_the_main_page(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertRedirect('/')
            ->assertSessionHas('success', "Welcome back, {$user->name}.");

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_generic_message(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_sixth_attempt_in_a_minute_is_locked_out_even_with_the_right_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        }

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_the_lockout_is_per_email_and_ip(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        User::factory()->create(['email' => 'grace@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'ADA@example.com', 'password' => 'wrong']);
        }

        // the same address in another case is the same key
        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password']);
        $this->assertGuest();

        // another address from the same IP is not locked
        $this->post('/login', ['email' => 'grace@example.com', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->post('/logout');

        // the same address from another IP is not locked
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->post('/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_signed_in_user_visiting_login_goes_to_the_main_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_sign_out(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')
            ->assertRedirect('/login')
            ->assertSessionHas('success', 'You have been signed out.');

        $this->assertGuest();
    }
}
