<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_register_page_is_the_login_page_on_its_other_tab(): void
    {
        $this->get('/register')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('tab', 'register')->where('canRegister', true));
    }

    public function test_registering_signs_the_new_account_straight_in(): void
    {
        $this->post('/register', $this->form())
            ->assertRedirect('/projects')
            ->assertSessionHas('success', 'Welcome, New Person. Create your first project to begin.');

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertAuthenticatedAs($user);

        $this->get('/projects')->assertOk();
    }

    public function test_the_old_signup_address_redirects_permanently(): void
    {
        $this->get('/signup')->assertStatus(301)->assertRedirect('/register');
    }

    public function test_with_registration_closed_every_register_address_is_a_404(): void
    {
        config(['auth.registration_open' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->form())->assertNotFound();
        $this->get('/signup')->assertNotFound();

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_with_registration_closed_the_login_page_says_nothing_about_signing_up(): void
    {
        config(['auth.registration_open' => false]);

        $this->get('/login')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('canRegister', false));
    }

    public function test_with_registration_open_the_login_page_offers_it(): void
    {
        $this->get('/login')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('canRegister', true));
    }

    public function test_the_form_cannot_set_anything_but_name_email_and_password(): void
    {
        $this->post('/register', $this->form(['phone' => '123', 'email_verified_at' => '2026-01-01 00:00:00', 'id' => 99]));

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertNotSame(99, $user->id);
        $this->assertNull($user->phone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_registered_email_and_bad_password_are_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', $this->form(['email' => 'taken@example.com']))
            ->assertSessionHasErrors(['email' => 'That email is already registered.']);
        $this->post('/register', $this->form(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors(['password' => 'The password must be at least 8 characters.']);
        $this->post('/register', $this->form(['password_confirmation' => 'different-password']))
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_sixth_submission_in_a_minute_from_one_ip_is_refused_before_validation(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/register', $this->form(['email' => "bad-{$attempt}"]))->assertSessionHasErrors('email');
        }

        $this->post('/register', $this->form())->assertSessionHasErrors('register');

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
