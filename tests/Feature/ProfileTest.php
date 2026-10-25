<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "My profile": the signed-in account's details and password, and nobody else's.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'phone' => null]);
    }

    public function test_the_page_shows_the_accounts_own_details(): void
    {
        $this->actingAs($this->user)->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Profile/Edit')
                ->where('profile', ['name' => 'Ada', 'email' => 'ada@example.com', 'phone' => null]));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->patch('/profile', [])->assertRedirect('/login');
        $this->put('/profile/password', [])->assertRedirect('/login');
    }

    public function test_details_are_saved_with_a_toast(): void
    {
        $this->actingAs($this->user)->from('/profile')
            ->patch('/profile', ['name' => 'Ada Lovelace', 'email' => 'lovelace@example.com', 'phone' => ' +91 98765 43210 '])
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Your details have been saved.');

        $this->assertSame(['Ada Lovelace', 'lovelace@example.com', '+91 98765 43210'], [
            $this->user->fresh()->name, $this->user->fresh()->email, $this->user->fresh()->phone,
        ]);
    }

    public function test_a_blank_phone_is_stored_as_none(): void
    {
        $this->user->forceFill(['phone' => '12345'])->save();

        $this->actingAs($this->user)->patch('/profile', ['name' => 'Ada', 'email' => 'ada@example.com', 'phone' => '  '])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->user->fresh()->phone);
    }

    public function test_details_are_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->user)
            ->patch('/profile', ['name' => '', 'email' => 'taken@example.com', 'phone' => 'call me'])
            ->assertSessionHasErrors([
                'name',
                'email' => 'Another account already uses that email.',
                'phone' => 'A phone number can only have digits, spaces, +, - and brackets.',
            ]);

        $this->assertSame('ada@example.com', $this->user->fresh()->email);
    }

    public function test_keeping_ones_own_email_is_not_a_clash(): void
    {
        $this->actingAs($this->user)->patch('/profile', ['name' => 'Ada', 'email' => 'ada@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_password_is_changed_and_rehashed(): void
    {
        $old = $this->user->password;

        $this->actingAs($this->user)->from('/profile')->put('/profile/password', $this->passwordForm())
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Password changed. You have been signed out everywhere else.');

        $user = $this->user->fresh();
        $this->assertNotSame($old, $user->password);
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertAuthenticatedAs($this->user);

        $this->post('/logout');
        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'brand-new-password'])->assertRedirect('/');
    }

    public function test_the_wrong_current_password_is_refused(): void
    {
        $this->actingAs($this->user)->put('/profile/password', $this->passwordForm(['current_password' => 'not-my-password']))
            ->assertSessionHasErrors(['current_password' => 'That is not your current password.']);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_the_new_password_is_validated_in_plain_words(): void
    {
        $this->actingAs($this->user)->put('/profile/password', $this->passwordForm(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors(['password' => 'The new password must be at least 8 characters.']);
        $this->actingAs($this->user)->put('/profile/password', $this->passwordForm(['password_confirmation' => 'something-else']))
            ->assertSessionHasErrors(['password' => 'The two new passwords do not match.']);
        $this->actingAs($this->user)->put('/profile/password', $this->passwordForm(['current_password' => '', 'password' => '', 'password_confirmation' => '']))
            ->assertSessionHasErrors(['current_password' => 'Enter your current password.', 'password' => 'Enter a new password.']);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    /**
     * Another device's session holds the password hash it signed in with. After the change it no
     * longer matches, so auth.session signs that session out on its next request; the session
     * that made the change carries the new hash and stays.
     */
    public function test_changing_the_password_signs_out_every_other_session(): void
    {
        // the other device signs in and makes a request, which stores its password hash
        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password']);
        $this->get('/projects')->assertOk();
        $otherDevice = session()->all();
        $this->post('/logout');

        // this device signs in and changes the password
        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'password']);
        $this->get('/projects')->assertOk();
        $this->put('/profile/password', $this->passwordForm())->assertSessionHasNoErrors();
        $this->get('/projects')->assertOk();
        $thisDevice = session()->all();

        // the other device comes back with its old session
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($otherDevice)->get('/projects')->assertRedirect('/login');
        $this->assertGuest();

        // this device is still signed in
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($thisDevice)->get('/projects')->assertOk();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function passwordForm(array $overrides = []): array
    {
        return [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
            ...$overrides,
        ];
    }
}
