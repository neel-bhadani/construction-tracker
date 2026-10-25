<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * project:transfer: the whole project changes hands, and nothing else changes.
 */
class TransferProjectCommandTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->project = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Vrundavan', 'planned_start_date' => '2026-04-05']);
        $building = Building::create(['project_id' => $this->project->id, 'name' => 'Tower A']);
        DB::table('activities')->insert(['building_id' => $building->id, 'description' => 'Root', 'offset_days' => 10, 'planned_date' => '2026-04-15', 'sort_order' => 1]);
        $this->client = User::factory()->create(['email' => 'client@example.com']);
    }

    public function test_moves_the_project_with_its_buildings_and_activities(): void
    {
        $before = [DB::table('buildings')->get()->all(), DB::table('activities')->get()->all()];

        $this->artisan('project:transfer', ['project' => $this->project->id, 'email' => 'client@example.com'])
            ->expectsOutputToContain("[Vrundavan] moved from [{$this->owner()->email}] to [client@example.com].")
            ->assertSuccessful();

        $this->assertSame($this->client->id, $this->project->fresh()->owner_id);
        $this->assertEquals($before, [DB::table('buildings')->get()->all(), DB::table('activities')->get()->all()]);

        // the new owner sees it, the old one no longer does
        $this->actingAs($this->client)->get("/projects/{$this->project->id}")->assertOk();
        $this->actingAs($this->owner())->get("/projects/{$this->project->id}")->assertNotFound();
    }

    public function test_refuses_when_the_new_owner_already_has_a_project_of_that_name(): void
    {
        Project::create(['owner_id' => $this->client->id, 'name' => 'Vrundavan', 'planned_start_date' => '2027-01-01']);

        $this->artisan('project:transfer', ['project' => $this->project->id, 'email' => 'client@example.com'])
            ->expectsOutputToContain('[client@example.com] already has a project named [Vrundavan]')
            ->assertFailed();

        $this->assertSame($this->owner()->id, $this->project->fresh()->owner_id);
    }

    public function test_refuses_an_unknown_email_or_project(): void
    {
        $this->artisan('project:transfer', ['project' => $this->project->id, 'email' => 'nobody@example.com'])
            ->expectsOutputToContain('No account has the email [nobody@example.com]')
            ->assertFailed();
        $this->artisan('project:transfer', ['project' => 999, 'email' => 'client@example.com'])
            ->expectsOutputToContain('There is no project with the id [999]')
            ->assertFailed();

        $this->assertSame($this->owner()->id, $this->project->fresh()->owner_id);
    }

    public function test_moving_to_the_current_owner_changes_nothing(): void
    {
        $this->artisan('project:transfer', ['project' => $this->project->id, 'email' => $this->owner()->email])
            ->expectsOutputToContain('already belongs to')
            ->assertSuccessful();

        $this->assertSame($this->owner()->id, $this->project->fresh()->owner_id);
    }
}
