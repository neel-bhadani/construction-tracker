<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\Services\ProjectEditor;
use App\Services\ProjectSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seedClientWorkbooks();
        $this->travelTo('2026-10-15 12:00');
        $this->editor = User::factory()->editor()->create();
    }

    /* ---------------- the summary figures ---------------- */

    /**
     * From the workbook figures already proven: done 180 + 190 + 180 of 853 + 843 + 825 planned
     * days; leads +12, +7, +12; hold-lead finishes 24 Jul, 19 Jul, 26 Jun 2028.
     */
    public function test_project_summary_combines_its_buildings(): void
    {
        $summary = app(ProjectSummaryService::class)->summarise(Project::sole());

        $this->assertSame(3, $summary->buildingsCount);
        $this->assertEqualsWithDelta(550 / 2521, $summary->progress, 1e-12);
        $this->assertSame(['id' => Building::where('name', 'Tower B')->value('id'), 'name' => 'Tower B', 'lead' => 7], $summary->mostBehind);
        $this->assertSame('2028-07-24', $summary->expectedHandover);

        $cards = collect($summary->buildings)->keyBy('name');
        $this->assertSame(['Tower A', 'Tower B', 'Overall Timeline'], $cards->keys()->all());
        $this->assertSame(['building', 'building', 'common_work'], $cards->pluck('type')->all());
        $this->assertSame(['1st SLAB', '3rd SLAB 15', 'RCC WORK 280'], $cards->pluck('next')->all());
        $this->assertSame([12, 7, 12], $cards->pluck('currentLead')->all());
        $this->assertSame(['2028-07-24', '2028-07-19', '2028-06-26'], $cards->pluck('expectedHandover')->all());
    }

    /** Most behind skips buildings with no actuals; handover falls back to the planned finish. */
    public function test_buildings_without_actuals_are_skipped_for_most_behind_and_use_their_planned_finish(): void
    {
        $project = Project::create(['name' => 'Fresh', 'planned_start_date' => '2027-01-01']);
        $source = Building::where('name', 'Tower B')->sole();
        app(ProjectEditor::class)->createBuilding($project, ['name' => 'Wing 1', 'type' => 'building'], $this->editor, $source);

        $summary = app(ProjectSummaryService::class)->summarise($project);

        $this->assertNull($summary->mostBehind);
        $this->assertSame(0.0, $summary->progress);
        $this->assertSame('2029-04-23', $summary->expectedHandover);
        $this->assertSame('DAY 1 TO GF SLAB - 150 DAYS', $summary->buildings[0]->next);
    }

    public function test_a_project_without_buildings_has_no_figures_rather_than_invented_ones(): void
    {
        $project = Project::create(['name' => 'Empty', 'planned_start_date' => '2027-01-01']);

        $summary = app(ProjectSummaryService::class)->summarise($project);

        $this->assertSame([0, null, null, null], [$summary->buildingsCount, $summary->progress, $summary->mostBehind, $summary->expectedHandover]);
    }

    public function test_projects_page_lists_every_project_with_its_summary(): void
    {
        Project::create(['name' => 'Another', 'planned_start_date' => '2027-01-01']);

        $this->actingAs(User::factory()->create())->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Projects/Index')
                ->has('projects', 2)
                ->where('projects.0.name', 'Another')
                ->where('projects.1.name', 'Shaligram Vrundavan')
                ->where('projects.1.buildingsCount', 3)
                ->where('projects.1.mostBehind.name', 'Tower B')
                ->where('can.create', false));
    }

    public function test_project_page_shows_a_card_per_building_and_every_copy_source(): void
    {
        $project = Project::sole();

        $this->actingAs($this->editor)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Projects/Show')
                ->where('project.name', 'Shaligram Vrundavan')
                ->has('project.buildings', 3)
                ->where('project.buildings.2.typeLabel', 'Common work')
                ->has('copySources', 3)
                ->where('copySources.0.label', 'Shaligram Vrundavan › Tower A')
                ->where('copySources.0.activities', 52)
                ->where('can', ['manageProject' => true, 'manageBuildings' => true]));
    }

    public function test_header_switcher_lists_projects_but_not_deleted_ones(): void
    {
        $gone = Project::create(['name' => 'Gone', 'planned_start_date' => '2027-01-01']);
        app(ProjectEditor::class)->deleteProject($gone, $this->editor);

        $this->actingAs($this->editor)->get('/projects')
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->where('projects.0.name', 'Shaligram Vrundavan'));
    }

    /* ---------------- creating, editing, deleting ---------------- */

    public function test_editor_creates_a_project_which_records_its_creator_and_is_audited(): void
    {
        $this->actingAs($this->editor)->post('/projects', [
            'name' => 'Shaligram Heights', 'details' => 'Bopal, 4 towers', 'planned_start_date' => '2027-02-01', 'timezone' => '',
        ])->assertSessionHasNoErrors();

        $project = Project::where('name', 'Shaligram Heights')->sole();
        $this->assertSame(['Bopal, 4 towers', '2027-02-01', 'Asia/Kolkata', $this->editor->id],
            [$project->details, $project->planned_start_date->toDateString(), $project->timezone, $project->created_by]);
        $this->assertSame([['created', null, 'Shaligram Heights', $this->editor->id]], $this->revisions($project));
    }

    public function test_editing_a_project_audits_each_changed_field_and_moves_no_planned_date(): void
    {
        $project = Project::sole();
        $before = \DB::table('activities')->pluck('planned_date', 'id')->all();

        $this->actingAs($this->editor)->patch("/projects/{$project->id}", [
            'name' => 'Shaligram Vrundavan', 'details' => 'Phase 1', 'planned_start_date' => '2026-06-01', 'timezone' => 'Asia/Dubai',
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([
            ['details', null, 'Phase 1', $this->editor->id],
            ['planned_start_date', '2026-04-05', '2026-06-01', $this->editor->id],
            ['timezone', 'Asia/Kolkata', 'Asia/Dubai', $this->editor->id],
        ], $this->revisions($project));
        // only the default for new buildings: no building, and no date, moves
        $this->assertSame($before, \DB::table('activities')->pluck('planned_date', 'id')->all());
        $this->assertSame(['2026-04-05'], Building::pluck('planned_start_date')->map->toDateString()->unique()->values()->all());
    }

    public function test_project_names_are_unique_among_live_projects_and_free_again_once_deleted(): void
    {
        $this->actingAs($this->editor)->post('/projects', ['name' => 'Shaligram Vrundavan', 'planned_start_date' => '2027-01-01', 'timezone' => 'Asia/Kolkata'])
            ->assertSessionHasErrors(['name' => 'There is already a project with that name.']);

        $this->actingAs($this->editor)->post('/projects', ['name' => 'Short lived', 'planned_start_date' => '2027-01-01', 'timezone' => 'Asia/Kolkata']);
        $this->actingAs($this->editor)->delete('/projects/'.Project::where('name', 'Short lived')->value('id'))->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->post('/projects', ['name' => 'Short lived', 'planned_start_date' => '2027-01-01', 'timezone' => 'Asia/Kolkata'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Project::withTrashed()->where('name', 'Short lived')->count());
    }

    public function test_bad_input_gets_plain_language_messages(): void
    {
        $this->actingAs($this->editor)->post('/projects', ['name' => '', 'planned_start_date' => '', 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors([
                'name' => 'The name field is required.',
                'planned_start_date' => 'Give the planned start date. New buildings start on it unless you choose another.',
                'timezone' => 'Choose a timezone from the list.',
            ]);
    }

    public function test_deleting_a_project_with_buildings_is_refused_with_them_named(): void
    {
        $project = Project::sole();

        $this->actingAs($this->editor)->delete("/projects/{$project->id}")
            ->assertSessionHasErrors(['project' => '[Shaligram Vrundavan] still has buildings: Tower A, Tower B, Overall Timeline. Delete them first.']);

        $this->assertNotSoftDeleted($project);
        $this->assertSame(0, ProjectRevision::count());
    }

    public function test_deleting_an_empty_project_is_a_soft_delete_and_is_audited(): void
    {
        $project = Project::create(['name' => 'Cancelled', 'planned_start_date' => '2027-01-01']);

        $this->actingAs($this->editor)->delete("/projects/{$project->id}")->assertRedirect('/projects');

        $this->assertSoftDeleted($project);
        $this->assertSame([['deleted', 'Cancelled', null, $this->editor->id]], $this->revisions($project));
        $this->actingAs($this->editor)->get("/projects/{$project->id}")->assertNotFound();
    }

    /**
     * @return list<array{string, ?string, ?string, int}>
     */
    private function revisions(Project $project): array
    {
        return ProjectRevision::where('project_id', $project->id)->orderBy('id')->get()
            ->map(fn ($r) => [$r->field, $r->old_value, $r->new_value, $r->user_id])->all();
    }
}
