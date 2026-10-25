<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HelpPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_account_sees_the_help_page(): void
    {
        $this->withoutVite();

        $this->actingAs($this->owner())->get('/help')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Help'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/help')->assertRedirect('/login');
    }

    /** The help text and the dialog say the same thing about adjustments. */
    public function test_the_adjustment_wording_matches_the_dialog(): void
    {
        $help = file_get_contents(resource_path('js/Pages/Help.vue'));
        $dialog = file_get_contents(resource_path('js/Components/ActivityFormModal.vue'));

        $this->assertStringContainsString("days adjustment, which doesn't carry", $help);
        $this->assertStringContainsString("days adjustment, which doesn't carry over.", $dialog);
        $this->assertStringContainsString('Nothing to push', $help);
        $this->assertStringContainsString('Nothing to push', $dialog);
    }
}
