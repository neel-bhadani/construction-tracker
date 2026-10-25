<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_account_that_can_sign_in(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Name', 'The Client')
            ->expectsQuestion('Email', 'client@example.com')
            ->expectsQuestion('Password', 'client-password')
            ->expectsQuestion('Confirm password', 'client-password')
            ->expectsOutputToContain('Account [client@example.com] created.')
            ->assertSuccessful();

        $user = User::where('email', 'client@example.com')->sole();
        $this->assertSame('The Client', $user->name);
        $this->assertTrue(Hash::check('client-password', $user->password));

        $this->post('/login', ['email' => 'client@example.com', 'password' => 'client-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_works_while_registration_is_closed(): void
    {
        config(['auth.registration_open' => false]);

        $this->artisan('user:create')
            ->expectsQuestion('Name', 'The Client')
            ->expectsQuestion('Email', 'client@example.com')
            ->expectsQuestion('Password', 'client-password')
            ->expectsQuestion('Confirm password', 'client-password')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
    }

    public function test_refuses_an_email_that_already_exists(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('user:create')
            ->expectsQuestion('Name', 'Someone')
            ->expectsQuestion('Email', 'taken@example.com')
            ->expectsOutputToContain('already exists. Nothing was created.')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_refuses_mismatched_passwords(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Name', 'The Client')
            ->expectsQuestion('Email', 'client@example.com')
            ->expectsQuestion('Password', 'client-password')
            ->expectsQuestion('Confirm password', 'different-password')
            ->expectsOutputToContain('The two passwords do not match.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
