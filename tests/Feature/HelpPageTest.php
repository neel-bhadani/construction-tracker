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

    /** The parts of version 5 each have their section; floors are gone. */
    public function test_it_covers_sub_activities_work_ids_common_and_all_activities(): void
    {
        $help = file_get_contents(resource_path('js/Pages/Help.vue'));

        foreach (["id: 'sub-activities'", "id: 'work-id'", "id: 'common'", "id: 'all'"] as $section) {
            $this->assertStringContainsString($section, $help);
        }
        $this->assertStringContainsString('SV-012.3', $help);
        $this->assertDoesNotMatchRegularExpression('/\bfloors?\b/i', $help);
    }
}
