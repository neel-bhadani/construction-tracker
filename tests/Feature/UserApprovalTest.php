<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_pending_accounts_only(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->unapproved()->create(['name' => 'Waiting', 'email' => 'waiting@example.com']);
        User::factory()->create();

        $this->actingAs($admin)->getJson('/users/pending')
            ->assertOk()
            ->assertExactJson([[
                'id' => $pending->id,
                'name' => 'Waiting',
                'email' => 'waiting@example.com',
                'signed_up_at' => $pending->created_at->toIso8601String(),
            ]]);
    }

    public function test_admin_approves_an_account_which_can_then_sign_in(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->unapproved()->create(['name' => 'Waiting']);

        $this->actingAs($admin)->from('/')->patch("/users/{$pending->id}/approve")
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Waiting can now sign in.');

        $this->assertNotNull($pending->fresh()->approved_at);

        $this->post('/logout');
        $this->post('/login', ['email' => $pending->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($pending);
    }

    public function test_approving_does_not_change_the_role(): void
    {
        $pending = User::factory()->unapproved()->create();

        $this->actingAs(User::factory()->admin()->create())->patch("/users/{$pending->id}/approve");

        $this->assertSame('viewer', $pending->fresh()->role->value);
    }

    public function test_already_approved_account_is_refused(): void
    {
        $approved = User::factory()->create();
        $before = $approved->approved_at->toDateTimeString();

        $this->actingAs(User::factory()->admin()->create())->patchJson("/users/{$approved->id}/approve")
            ->assertForbidden()
            ->assertJson(['message' => 'That account is already approved.']);

        $this->assertSame($before, $approved->fresh()->approved_at->toDateTimeString());
    }

    public function test_editor_and_viewer_can_neither_list_nor_approve(): void
    {
        $pending = User::factory()->unapproved()->create();

        foreach ([User::factory()->editor()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->getJson('/users/pending')->assertForbidden();
            $this->actingAs($user)->patchJson("/users/{$pending->id}/approve")->assertForbidden();
        }

        $this->assertNull($pending->fresh()->approved_at);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $pending = User::factory()->unapproved()->create();

        $this->get('/users/pending')->assertRedirect('/login');
        $this->patch("/users/{$pending->id}/approve")->assertRedirect('/login');
        $this->assertNull($pending->fresh()->approved_at);
    }
}
