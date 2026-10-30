<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\Project;
use App\Models\SubActivity;
use App\Models\SubActivityRevision;
use App\Services\BuildingMetricsService;
use App\Services\ProjectEditor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sub-activities: the steps inside an activity. Their average is the activity's bar; once all are
 * at 100% the activity is done on the latest done date, and below that it is not done. They never
 * move a planned date, and the building's % stays the workbook's own figure.
 *
 * The hand-made building, from 1 Apr 2026, today 15 Oct 2026:
 *   Excavation 10 days (11 Apr) · Footings +20 (1 May) · Slab +30 (31 May) · Diwali break +5
 */
class SubActivityTest extends TestCase
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
        $this->project = $editor->createProject(['name' => 'Sub Test', 'details' => null, 'planned_start_date' => '2026-04-01'], $this->owner());
        $this->tower = $editor->createBuilding($this->project, ['name' => 'Tower A'], $this->owner());

        $after = null;
        foreach (['Excavation' => 10, 'Footings' => 20, 'Slab' => 30, 'Diwali break' => 5] as $name => $days) {
            $this->actingAs($this->owner())->post("/buildings/{$this->tower->id}/activities", [
                'description' => $name, 'predecessor_id' => $after, 'offset_days' => $days, 'date_adjustment_days' => 0,
                'adjustment_reason' => null, 'is_break' => $name === 'Diwali break', 'actual_date' => null, 'notes' => null,
            ])->assertSessionHasNoErrors();
            $after = Activity::where('description', $name)->value('id');
            $this->rows[$name] = Activity::findOrFail($after);
        }
    }

    public function test_the_activitys_bar_is_the_average_of_its_sub_activities(): void
    {
        $footings = $this->rows['Footings'];
        $this->add($footings, 'Shuttering', ['progress' => 0]);
        $this->add($footings, 'Rebar', ['progress' => 50]);
        $this->add($footings, 'Pour', ['progress' => 100, 'done_date' => '2026-05-02']);

        $this->assertNull($footings->fresh()->actual_date);
        $this->timeline()->assertInertia(fn (Assert $page) => $page
            ->where('activities.1.work_id', 'ST-002')
            ->where('activities.1.progress', 50)
            ->has('activities.1.sub_activities', 3)
            ->where('activities.1.sub_activities.1.work_id', 'ST-002.2')
            // without sub-activities: 0 until done; a break has no bar
            ->where('activities.0.progress', 0)
            ->where('activities.3.progress', null));
    }

    public function test_all_at_100_makes_the_activity_done_on_the_latest_done_date(): void
    {
        $footings = $this->rows['Footings'];
        // alone at 100%, it is all there is: done
        $a = $this->add($footings, 'Shuttering', ['progress' => 100, 'done_date' => '2026-05-03']);
        $this->assertSame('2026-05-03', $footings->fresh()->actual_date->toDateString());
        // another below 100%: not done
        $b = $this->add($footings, 'Pour', ['progress' => 75]);
        $this->assertNull($footings->fresh()->actual_date);

        $this->change($b, ['progress' => 100, 'done_date' => '2026-05-01'])->assertSessionHas('success', 'Saved [ST-002.2 Pour], 100%.');

        $this->assertSame('2026-05-03', $footings->fresh()->actual_date->toDateString());
        $this->assertSame([
            ['actual_date', null, '2026-05-03'], ['actual_date', '2026-05-03', null], ['actual_date', null, '2026-05-03'],
        ], $this->activityRevisions($footings, 'actual_date'));
        // done through its sub-activities is done in every figure, as Mark done is
        $this->assertSame(30, app(BuildingMetricsService::class)->forBuilding($this->tower->fresh())->daysCompleted);

        // one back below 100: not done any more, and its own done date goes
        $this->change($a, ['progress' => 50]);
        $this->assertNull($footings->fresh()->actual_date);
        $this->assertNull($a->fresh()->done_date);
        $this->assertSame(0, app(BuildingMetricsService::class)->forBuilding($this->tower->fresh())->daysCompleted);
    }

    public function test_partial_progress_moves_no_building_figure_and_no_planned_date(): void
    {
        $planned = Activity::orderBy('id')->pluck('planned_date')->map->toDateString()->all();
        $before = app(BuildingMetricsService::class)->forBuilding($this->tower->fresh());

        $this->add($this->rows['Slab'], 'Shuttering', ['progress' => 75]);

        $after = app(BuildingMetricsService::class)->forBuilding($this->tower->fresh());
        $this->assertSame([$before->daysCompleted, $before->progress], [$after->daysCompleted, $after->progress]);
        $this->assertSame($planned, Activity::orderBy('id')->pluck('planned_date')->map->toDateString()->all());
    }

    public function test_the_first_sub_activity_of_a_done_activity_starts_at_100_on_its_date(): void
    {
        $excavation = $this->rows['Excavation'];
        $this->actingAs($this->owner())->patch("/activities/{$excavation->id}/actual", ['actual_date' => '2026-04-10'])->assertSessionHasNoErrors();

        $first = $this->add($excavation, 'Survey');

        $this->assertSame([100, '2026-04-10'], [$first->progress, $first->done_date->toDateString()]);
        $this->assertSame('2026-04-10', $excavation->fresh()->actual_date->toDateString());

        // the next one starts at 0, which reopens the activity
        $second = $this->add($excavation, 'Levelling');
        $this->assertSame([0, null], [$second->progress, $second->done_date]);
        $this->assertNull($excavation->fresh()->actual_date);
    }

    public function test_a_first_sub_activity_saved_below_100_reopens_the_done_activity(): void
    {
        $excavation = $this->rows['Excavation'];
        $this->actingAs($this->owner())->patch("/activities/{$excavation->id}/actual", ['actual_date' => '2026-04-10']);

        $this->add($excavation, 'Survey', ['progress' => 25]);

        $this->assertNull($excavation->fresh()->actual_date);
    }

    public function test_deleting_the_last_sub_activity_keeps_the_activity_as_it_is(): void
    {
        $footings = $this->rows['Footings'];
        $only = $this->add($footings, 'Pour', ['progress' => 100, 'done_date' => '2026-05-02']);
        $this->assertSame('2026-05-02', $footings->fresh()->actual_date->toDateString());

        $this->actingAs($this->owner())->delete("/sub-activities/{$only->id}")->assertSessionHas('success', 'Deleted [ST-002.1 Pour].');

        $this->assertSoftDeleted($only);
        $this->assertSame('2026-05-02', $footings->fresh()->actual_date->toDateString());
        // and it is marked done by hand again
        $this->actingAs($this->owner())->patch("/activities/{$footings->id}/actual", ['actual_date' => null])->assertSessionHasNoErrors();
        $this->assertNull($footings->fresh()->actual_date);
    }

    public function test_deleting_the_one_below_100_makes_the_rest_done(): void
    {
        $footings = $this->rows['Footings'];
        $this->add($footings, 'Shuttering', ['progress' => 100, 'done_date' => '2026-05-03']);
        $pour = $this->add($footings, 'Pour', ['progress' => 25]);

        $this->actingAs($this->owner())->delete("/sub-activities/{$pour->id}");

        $this->assertSame('2026-05-03', $footings->fresh()->actual_date->toDateString());
    }

    public function test_the_done_date_follows_the_actual_date_rules(): void
    {
        $footings = $this->rows['Footings'];
        $store = fn (array $data) => $this->actingAs($this->owner())->post("/activities/{$footings->id}/sub-activities", ['name' => 'Pour', ...$data]);

        $store(['progress' => 100])->assertSessionHasErrors(['done_date' => 'Give the date it was done: 100% needs a done date.']);
        $store(['progress' => 100, 'done_date' => '2026-10-16'])->assertSessionHasErrors(['done_date' => 'The done date cannot be in the future.']);
        $store(['progress' => 100, 'done_date' => '2026-03-31'])->assertSessionHasErrors(['done_date' => 'The done date cannot be before the building\'s actual start (2026-04-01).']);
        $store(['progress' => 30])->assertSessionHasErrors(['progress' => 'Choose 0, 25, 50, 75 or 100%.']);
        $store(['name' => ''])->assertSessionHasErrors(['name' => 'Enter the sub-activity name.']);
        $this->assertSame(0, SubActivity::count());

        // a done date below 100% is not kept
        $sub = $this->add($footings, 'Pour', ['progress' => 50, 'done_date' => '2026-05-01']);
        $this->assertNull($sub->done_date);
        // going to 100% alone, without a date, is refused
        $this->change($sub, ['progress' => 100])->assertSessionHasErrors('done_date');
        // and the database refuses a step that is not one of the five
        $this->expectException(QueryException::class);
        DB::table('sub_activities')->where('id', $sub->id)->update(['progress' => 30]);
    }

    public function test_a_break_has_no_sub_activities(): void
    {
        $break = $this->rows['Diwali break'];

        $this->actingAs($this->owner())->post("/activities/{$break->id}/sub-activities", ['name' => 'Rest'])
            ->assertSessionHasErrors(['activity' => 'A break has no sub-activities.']);

        // nor does an activity with sub-activities become a break
        $slab = $this->rows['Slab'];
        $this->add($slab, 'Shuttering');
        $this->actingAs($this->owner())->patch("/activities/{$slab->id}", [...$this->plan($slab), 'is_break' => true])
            ->assertSessionHasErrors(['is_break' => 'An activity with sub-activities cannot be a break. Delete its sub-activities first.']);
        $this->assertSame(0, SubActivity::where('activity_id', $break->id)->count());
    }

    public function test_with_sub_activities_the_actual_date_is_not_set_by_hand(): void
    {
        $slab = $this->rows['Slab'];
        $this->add($slab, 'Shuttering', ['progress' => 50]);

        foreach (['2026-06-01', null] as $date) {
            $this->actingAs($this->owner())->patch("/activities/{$slab->id}/actual", ['actual_date' => $date])
                ->assertSessionHasErrors(['actual_date' => 'This activity is done through its sub-activities.']);
        }
        $this->actingAs($this->owner())->patch("/activities/{$slab->id}", [...$this->plan($slab), 'actual_date' => '2026-06-01'])
            ->assertSessionHasErrors(['actual_date' => 'This activity is done through its sub-activities.']);

        // the rest of the dialog saves as always, the actual date untouched
        $this->actingAs($this->owner())->patch("/activities/{$slab->id}", [...$this->plan($slab), 'description' => 'Slab 1'])->assertSessionHasNoErrors();
        $this->assertSame(['Slab 1', null], [$slab->fresh()->description, $slab->fresh()->actual_date]);
    }

    public function test_work_ids_follow_creation_and_are_never_reused_or_renumbered(): void
    {
        $slab = $this->rows['Slab'];
        $a = $this->add($slab, 'A');
        $b = $this->add($slab, 'B');
        $c = $this->add($slab, 'C');
        $this->actingAs($this->owner())->delete("/sub-activities/{$b->id}");
        $d = $this->add($slab, 'D');

        $this->assertSame(['ST-003.1', 'ST-003.3', 'ST-003.4'], [$a->work_id, $c->work_id, $d->work_id]);

        // reordering moves positions only
        $this->actingAs($this->owner())->patch("/activities/{$slab->id}/sub-activities/order", ['ids' => [$d->id, $a->id, $c->id]])->assertSessionHasNoErrors();
        $this->assertSame([['D', 'ST-003.4'], ['A', 'ST-003.1'], ['C', 'ST-003.3']],
            $slab->subActivities()->get()->map(fn ($s) => [$s->name, $s->work_id])->all());

        // the order must name every live one once, and only this activity's
        $other = $this->add($this->rows['Footings'], 'X');
        foreach ([[$d->id, $a->id], [$d->id, $a->id, $c->id, $b->id], [$d->id, $a->id, $other->id], [$d->id, $d->id, $a->id]] as $ids) {
            $this->actingAs($this->owner())->patch("/activities/{$slab->id}/sub-activities/order", ['ids' => $ids])->assertSessionHasErrors();
        }
        $this->assertSame(['D', 'A', 'C'], $slab->subActivities()->pluck('name')->all());
    }

    public function test_every_change_is_audited_and_the_trail_outlives_the_activity(): void
    {
        $slab = $this->rows['Slab'];
        $sub = $this->add($slab, 'Shuttering', ['planned_date' => '2026-05-20', 'note' => 'Steel plates']);
        $this->change($sub, ['progress' => 100, 'done_date' => '2026-05-25', 'note' => '']);

        $this->assertSame([
            ['created', null, 'Shuttering'],
            ['planned_date', null, '2026-05-20'],
            ['note', null, 'Steel plates'],
            ['progress', '0', '100'],
            ['done_date', null, '2026-05-25'],
            ['note', 'Steel plates', null],
        ], SubActivityRevision::where('sub_activity_snapshot_id', $sub->id)->orderBy('id')->get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all());

        // deleting the activity takes the rows; the trail stays, with the deletion in it
        $this->actingAs($this->owner())->patch("/activities/{$this->rows['Diwali break']->id}", [...$this->plan($this->rows['Diwali break']), 'predecessor_id' => $this->rows['Footings']->id]);
        $this->actingAs($this->owner())->delete("/activities/{$slab->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sub_activities')->where('id', $sub->id)->count());
        $this->assertSame(['deleted', 'Shuttering', null], SubActivityRevision::where('sub_activity_snapshot_id', $sub->id)->latest('id')->get()
            ->map(fn ($r) => [$r->field, $r->old_value, $r->sub_activity_id])->first());
    }

    public function test_a_copied_plan_brings_names_and_planned_dates_at_0(): void
    {
        $slab = $this->rows['Slab'];
        $this->add($slab, 'Shuttering', ['planned_date' => '2026-05-20', 'progress' => 100, 'done_date' => '2026-05-21', 'note' => 'Site note']);
        $this->add($slab, 'Pour', ['planned_date' => '2026-05-30']);

        $this->actingAs($this->owner())->post("/projects/{$this->project->id}/buildings", ['name' => 'Tower B', 'plan' => 'copy', 'copy_from' => $this->tower->id])
            ->assertSessionHasNoErrors();

        $copy = Activity::where('building_id', Building::where('name', 'Tower B')->value('id'))->where('description', 'Slab')->sole();
        $this->assertSame([
            ['ST-007.1', 'Shuttering', '2026-05-20', 0, null, null],
            ['ST-007.2', 'Pour', '2026-05-30', 0, null, null],
        ], $copy->subActivities()->get()->map(fn ($s) => [$s->work_id, $s->name, $s->planned_date?->toDateString(), $s->progress, $s->done_date, $s->note])->all());
        $this->assertNull($copy->actual_date);

        // the copy numbers on from there
        $this->assertSame('ST-007.3', $this->add($copy, 'Cure')->work_id);
    }

    private function add(Activity $activity, string $name, array $data = []): SubActivity
    {
        $this->actingAs($this->owner())->post("/activities/{$activity->id}/sub-activities", ['name' => $name, ...$data])->assertSessionHasNoErrors();

        return SubActivity::where('activity_id', $activity->id)->where('name', $name)->latest('id')->firstOrFail();
    }

    private function change(SubActivity $sub, array $data)
    {
        return $this->actingAs($this->owner())->patch("/sub-activities/{$sub->id}", $data);
    }

    private function timeline(): TestResponse
    {
        return $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$this->tower->id}")->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(Activity $activity): array
    {
        return [
            'description' => $activity->description, 'predecessor_id' => $activity->predecessor_id, 'offset_days' => $activity->offset_days,
            'date_adjustment_days' => 0, 'adjustment_reason' => null, 'is_break' => $activity->is_break, 'notes' => null,
        ];
    }

    /**
     * @return list<array{string, ?string, ?string}>
     */
    private function activityRevisions(Activity $activity, string $field): array
    {
        return ActivityRevision::where('activity_id', $activity->id)->where('field', $field)->orderBy('id')->get()
            ->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all();
    }
}
