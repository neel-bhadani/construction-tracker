<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Services\ProjectEditor;
use App\Services\WorkIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work IDs: "{code}-{nnn}", given on create from the project's counter, never typed, never reused,
 * never renumbered. The code comes from the project's initials and can be changed; old IDs stay.
 */
class WorkIdTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Building $tower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 12:00');
        $this->project = app(ProjectEditor::class)->createProject(
            ['name' => 'Test Tower Project', 'details' => null, 'planned_start_date' => '2026-04-05'], $this->owner());
        $this->tower = app(ProjectEditor::class)->createBuilding($this->project, ['name' => 'Tower A'], $this->owner());
    }

    public function test_a_new_activity_gets_the_next_id_and_it_is_in_the_toast(): void
    {
        $this->add($this->tower, 'Excavation')->assertSessionHas('success', 'Added [TTP-001 Excavation].');
        $this->add($this->tower, 'Footings');

        $this->assertSame(['TTP-001', 'TTP-002'], $this->ids($this->tower));
        $this->assertSame(2, $this->project->fresh()->next_activity_number);
    }

    public function test_ids_count_across_the_whole_project_and_restart_in_another(): void
    {
        $common = $this->project->commonActivities()->sole();
        $this->add($this->tower, 'Excavation');
        $this->add($common, 'Site fencing');
        $this->add($this->tower, 'Footings');

        $this->assertSame(['TTP-001', 'TTP-003'], $this->ids($this->tower));
        $this->assertSame(['TTP-002'], $this->ids($common));

        $other = app(ProjectEditor::class)->createProject(['name' => 'Other', 'details' => null, 'planned_start_date' => '2026-04-05'], $this->owner());
        $wing = app(ProjectEditor::class)->createBuilding($other, ['name' => 'Wing'], $this->owner());
        $this->add($wing, 'Excavation');
        $this->assertSame(['O-001'], $this->ids($wing));
    }

    public function test_a_deleted_activitys_id_is_never_given_again(): void
    {
        $this->add($this->tower, 'A');
        $this->add($this->tower, 'B');
        $b = Activity::where('description', 'B')->sole();
        $this->actingAs($this->owner())->delete("/activities/{$b->id}")->assertSessionHas('success', 'Deleted [TTP-002 B].');

        $this->add($this->tower, 'C');

        $this->assertSame(['TTP-001', 'TTP-003'], $this->ids($this->tower));
    }

    public function test_an_inserted_row_takes_the_next_id_and_nothing_is_renumbered(): void
    {
        $this->add($this->tower, 'A');
        $this->add($this->tower, 'B');
        $a = Activity::where('description', 'A')->sole();

        $this->add($this->tower, 'Between', ['after_id' => $a->id, 'push' => false, 'predecessor_id' => $a->id]);

        $this->assertSame([['A', 'TTP-001'], ['Between', 'TTP-003'], ['B', 'TTP-002']],
            Activity::where('building_id', $this->tower->id)->orderBy('sort_order')->get()->map(fn ($r) => [$r->description, $r->work_id])->all());
    }

    public function test_changing_the_code_keeps_the_ids_already_given(): void
    {
        $this->add($this->tower, 'A');

        $this->actingAs($this->owner())->patch("/projects/{$this->project->id}", [
            'name' => $this->project->name, 'code' => 'tt2', 'planned_start_date' => '2026-04-05', 'timezone' => 'Asia/Kolkata',
        ])->assertSessionHasNoErrors();
        $this->add($this->tower, 'B');

        $this->assertSame(['TTP-001', 'TT2-002'], $this->ids($this->tower));
        $this->assertSame([['code', 'TTP', 'TT2']], $this->project->revisions()->where('field', 'code')->get()
            ->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all());
    }

    public function test_a_copied_plan_gets_new_ids_in_its_order(): void
    {
        $this->add($this->tower, 'A');
        $this->add($this->tower, 'B');

        $this->actingAs($this->owner())->post("/projects/{$this->project->id}/buildings", [
            'name' => 'Tower B', 'plan' => 'copy', 'copy_from' => $this->tower->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['TTP-003', 'TTP-004'], $this->ids(Building::where('name', 'Tower B')->sole()));
    }

    public function test_the_ids_cannot_be_typed(): void
    {
        $this->add($this->tower, 'A', ['work_id' => 'HACK-999', 'work_number' => 999]);

        $this->assertSame(['TTP-001'], $this->ids($this->tower));
        $a = Activity::sole();

        $this->actingAs($this->owner())->patch("/activities/{$a->id}", [...$this->plan('A2'), 'work_id' => 'HACK-1'])->assertSessionHasNoErrors();
        $this->assertSame(['A2', 'TTP-001', 1], [$a->fresh()->description, $a->fresh()->work_id, $a->fresh()->work_number]);
    }

    public function test_many_at_once_are_all_different(): void
    {
        foreach (range(1, 12) as $n) {
            $this->add($this->tower, "Row {$n}");
        }
        $this->actingAs($this->owner())->post("/projects/{$this->project->id}/buildings", ['name' => 'Copy', 'plan' => 'copy', 'copy_from' => $this->tower->id]);

        $ids = Activity::pluck('work_id');
        $this->assertCount(24, $ids);
        $this->assertCount(24, $ids->unique());
        $this->assertSame('TTP-024', $ids->sort()->last());
    }

    public function test_ids_are_taken_only_inside_a_transaction(): void
    {
        $this->expectExceptionMessage('inside the transaction');

        // RefreshDatabase's own transaction is level 1; this leaves none
        DB::rollBack();
        try {
            app(WorkIds::class)->take($this->project->id);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_the_format_pads_to_three_digits_and_grows_past_them(): void
    {
        $this->assertSame(['SV-001', 'SV-042', 'SV-999', 'SV-1000'], array_map(fn (int $n) => WorkIds::format('SV', $n), [1, 42, 999, 1000]));
    }

    private function add(Building $building, string $name, array $extra = [])
    {
        $last = Activity::where('building_id', $building->id)->orderByDesc('sort_order')->value('id');

        return $this->actingAs($this->owner())->post("/buildings/{$building->id}/activities", [
            ...$this->plan($name), 'predecessor_id' => $last, ...$extra,
        ])->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $name): array
    {
        return ['description' => $name, 'predecessor_id' => null, 'offset_days' => 5, 'date_adjustment_days' => 0,
            'adjustment_reason' => null, 'is_break' => false, 'actual_date' => null, 'notes' => null];
    }

    /**
     * @return list<string>
     */
    private function ids(Building $building): array
    {
        return Activity::where('building_id', $building->id)->orderBy('work_number')->pluck('work_id')->all();
    }
}
