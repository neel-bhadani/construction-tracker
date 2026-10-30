<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Models\SubActivity;
use App\Services\ProjectEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * NEXT is the first row without an actual date that is not a break: a break can never be marked
 * done, so it would otherwise hold NEXT for good. The timeline, the project card and All
 * activities all show the same row, however the activity was done.
 *
 * The hand-made building, from 1 Apr 2026, today 15 Oct 2026:
 *   Excavation 10 · Diwali break 5 · Footings 20 · Holi break 5 · Rains break 5 · Slab 30
 */
class NextActivityTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Building $tower;

    /** @var array<string, Activity> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');
        $editor = app(ProjectEditor::class);
        $this->project = $editor->createProject(['name' => 'Next Test', 'details' => null, 'planned_start_date' => '2026-04-01'], $this->owner());
        $this->tower = $editor->createBuilding($this->project, ['name' => 'Tower A'], $this->owner());

        $after = null;
        foreach (['Excavation' => 10, 'Diwali break' => 5, 'Footings' => 20, 'Holi break' => 5, 'Rains break' => 5, 'Slab' => 30] as $name => $days) {
            $this->actingAs($this->owner())->post("/buildings/{$this->tower->id}/activities", [
                'description' => $name, 'predecessor_id' => $after, 'offset_days' => $days, 'date_adjustment_days' => 0,
                'adjustment_reason' => null, 'is_break' => str_ends_with($name, 'break'), 'actual_date' => null, 'notes' => null,
            ])->assertSessionHasNoErrors();
            $after = Activity::where('description', $name)->value('id');
            $this->rows[$name] = Activity::findOrFail($after);
        }
    }

    public function test_a_break_right_after_a_done_row_is_skipped(): void
    {
        $this->assertNext('Excavation');

        $this->done('Excavation', '2026-04-10');

        $this->assertNext('Footings');
        $this->assertStatus('Diwali break', 'Pending');
    }

    public function test_undoing_the_done_row_moves_next_back(): void
    {
        $this->done('Excavation', '2026-04-10');
        $this->done('Excavation', null);

        $this->assertNext('Excavation');
        $this->assertStatus('Footings', 'Pending');
    }

    public function test_two_breaks_in_a_row_are_both_skipped(): void
    {
        $this->done('Excavation', '2026-04-10');
        $this->done('Footings', '2026-05-01');

        $this->assertNext('Slab');
        $this->assertStatus('Holi break', 'Pending');
        $this->assertStatus('Rains break', 'Pending');
    }

    public function test_an_activity_done_through_its_sub_activities_moves_next_the_same_way(): void
    {
        $this->done('Excavation', '2026-04-10');
        $this->actingAs($this->owner())->post("/activities/{$this->rows['Footings']->id}/sub-activities", ['name' => 'Pour', 'progress' => 100, 'done_date' => '2026-05-02'])
            ->assertSessionHasNoErrors();

        $this->assertNext('Slab');

        $pour = SubActivity::where('name', 'Pour')->sole();
        $this->actingAs($this->owner())->patch("/sub-activities/{$pour->id}", ['name' => 'Pour', 'progress' => 50, 'done_date' => null])
            ->assertSessionHasNoErrors();

        $this->assertNext('Footings');
    }

    public function test_with_every_activity_done_there_is_no_next(): void
    {
        $this->done('Excavation', '2026-04-10');
        $this->done('Footings', '2026-05-01');
        $this->done('Slab', '2026-06-01');

        $this->assertNext(null);
        $this->assertStatus('Diwali break', 'Pending');
        $this->assertStatus('Rains break', 'Pending');
    }

    private function done(string $name, ?string $date): void
    {
        $this->actingAs($this->owner())->patch("/activities/{$this->rows[$name]->id}/actual", ['actual_date' => $date])
            ->assertSessionHasNoErrors();
    }

    /** The NEXT row on the timeline, the project card and All activities; null is none. */
    private function assertNext(?string $name): void
    {
        $statuses = $this->statuses();
        $this->assertSame($name === null ? [] : [$name], array_keys($statuses, 'NEXT', true));

        $this->actingAs($this->owner())->get("/projects/{$this->project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('project.buildings.0.next', $name));
        $this->actingAs($this->owner())->get("/projects/{$this->project->id}/activities")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('groups.1.name', 'Tower A')
                ->where('groups.1.rows', fn ($rows) => collect($rows)->pluck('status')->all() === array_values($statuses)));
    }

    private function assertStatus(string $name, string $status): void
    {
        $this->assertSame($status, $this->statuses()[$name]);
    }

    /**
     * Each row's status on the building's timeline, in row order.
     *
     * @return array<string, string>
     */
    private function statuses(): array
    {
        $props = [];
        $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$this->tower->id}")->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return collect($props['projection']['activities'])->pluck('status', 'description')->all();
    }
}
