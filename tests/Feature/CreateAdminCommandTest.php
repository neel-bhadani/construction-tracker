<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_approved_admin(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Name', 'First Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'admin-password')
            ->expectsQuestion('Confirm password', 'admin-password')
            ->expectsOutputToContain('Admin [admin@example.com] created and approved.')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->sole();
        $this->assertSame(['First Admin', Role::Admin], [$admin->name, $admin->role]);
        $this->assertNotNull($admin->approved_at);
        $this->assertTrue(Hash::check('admin-password', $admin->password));
    }

    public function test_the_new_admin_can_sign_in(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Name', 'First Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'admin-password')
            ->expectsQuestion('Confirm password', 'admin-password')
            ->assertSuccessful();

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'admin-password'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_refuses_an_email_that_already_exists(): void
    {
        $existing = User::factory()->unapproved()->create(['email' => 'taken@example.com']);

        $this->artisan('user:create-admin')
            ->expectsQuestion('Name', 'Someone')
            ->expectsQuestion('Email', 'taken@example.com')
            ->expectsOutputToContain('already exists. Nothing was created.')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertSame(Role::Viewer, $existing->fresh()->role);
        $this->assertNull($existing->fresh()->approved_at);
    }

    public function test_refuses_mismatched_passwords(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Name', 'First Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'admin-password')
            ->expectsQuestion('Confirm password', 'different-password')
            ->expectsOutputToContain('The two passwords do not match.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
