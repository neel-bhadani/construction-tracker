<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_link_is_emailed_to_an_existing_account(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', PasswordResetController::LINK_SENT);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /** One answer either way, so the form cannot be used to find out who has an account. */
    public function test_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', PasswordResetController::LINK_SENT)
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_the_emailed_link_opens_the_reset_page_and_sets_a_new_password(): void
    {
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringContainsString("/reset-password/{$notification->token}", $url);

            $this->get("/reset-password/{$notification->token}?email=".urlencode($user->email))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword')
                    ->where('token', $notification->token)->where('email', $user->email));

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertRedirect('/login')->assertSessionHas('status', __(Password::PASSWORD_RESET));

            return true;
        });

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->post('/login', ['email' => $user->email, 'password' => 'brand-new-password'])->assertRedirect('/');
    }

    public function test_invalid_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
