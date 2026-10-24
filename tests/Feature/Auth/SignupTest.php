<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_signup_page_is_the_login_page_on_its_other_tab(): void
    {
        $this->get('/signup')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('tab', 'signup'));
    }

    public function test_signup_creates_an_unapproved_viewer_and_signs_nobody_in(): void
    {
        $this->post('/signup', $this->form())
            ->assertRedirect('/signup/submitted')
            ->assertSessionHas('signedUp', ['name' => 'New Person', 'email' => 'new@example.com']);

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame(Role::Viewer, $user->role);
        $this->assertNull($user->approved_at);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertGuest();
    }

    public function test_signup_cannot_choose_its_role_or_approve_itself(): void
    {
        $this->post('/signup', $this->form(['role' => 'admin', 'approved_at' => '2026-01-01 00:00:00']));

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame(Role::Viewer, $user->role);
        $this->assertNull($user->approved_at);
    }

    public function test_new_account_is_told_it_is_awaiting_approval_at_sign_in(): void
    {
        $this->post('/signup', $this->form());

        $this->post('/login', ['email' => 'new@example.com', 'password' => 'secret-password'])
            ->assertSessionHasErrors(['approval' => LoginRequest::AWAITING_APPROVAL]);
        $this->assertGuest();
    }

    public function test_submitted_page_explains_what_happens_next(): void
    {
        $this->get('/signup/submitted')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/SignupSubmitted')->where('account', null));
    }

    public function test_registered_email_and_bad_password_are_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/signup', $this->form(['email' => 'taken@example.com']))
            ->assertSessionHasErrors(['email' => 'That email is already registered.']);
        $this->post('/signup', $this->form(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        $this->post('/signup', $this->form(['password_confirmation' => 'different-password']))
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);

        $this->assertSame(1, User::count());
    }

    public function test_sixth_submission_in_a_minute_from_one_ip_is_refused_before_validation(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/signup', $this->form(['email' => "bad-{$attempt}"]))->assertSessionHasErrors('email');
        }

        $this->post('/signup', $this->form())->assertSessionHasErrors('signup');

        $this->assertSame(0, User::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            ...$overrides,
        ];
    }
}
