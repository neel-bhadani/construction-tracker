<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\Project;
use App\Services\BuildingMetricsService;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The activity dialog: a planned date typed in or the days after "Comes after", the actual date,
 * breaks, and inserting a row between two others, with or without pushing the rest later.
 *
 * A hand-made building starting 1 Jan 2026, today 1 Mar 2026:
 *
 *   row  activity  comes after  offset  work-day  planned       actual
 *   1    A         start        10      10        11 Jan 2026   9 Jan 2026
 *   2    B         A            20      30        31 Jan 2026
 *   3    C         B            15      45        15 Feb 2026
 *   4    D         C             5      50        20 Feb 2026
 *   5    Holi      C            15      60        2 Mar 2026    (a break)
 *
 * Total 60 work-days, 10 done: 1/6. Finish 2 Mar 2026.
 */
class ActivityDialogTest extends TestCase
{
    use RefreshDatabase;

    private Building $building;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-03-01 12:00');
        $project = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Hand-made', 'planned_start_date' => '2026-01-01']);
        $this->building = Building::create(['project_id' => $project->id, 'name' => 'Tower', 'planned_start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01']);

        foreach ([
            ['A', null, 10, false, '2026-01-09'],
            ['B', 'A', 20, false, null],
            ['C', 'B', 15, false, null],
            ['D', 'C', 5, false, null],
            ['Holi', 'C', 15, true, null],
        ] as $row => [$name, $after, $offset, $break, $actual]) {
            $this->ids[$name] = DB::table('activities')->insertGetId([
                'building_id' => $this->building->id, 'description' => $name,
                'predecessor_id' => $after === null ? null : $this->ids[$after],
                'offset_days' => $offset, 'is_break' => $break, 'actual_date' => $actual,
                'planned_date' => '2026-01-01', 'sort_order' => $row + 1,
            ]);
        }
        app(ScheduleRecalculator::class)->recalculate($this->building);
    }

    public function test_the_hand_made_building_is_as_described(): void
    {
        $this->assertSame([
            'A' => '2026-01-11', 'B' => '2026-01-31', 'C' => '2026-02-15', 'D' => '2026-02-20', 'Holi' => '2026-03-02',
        ], $this->plannedDates());
        $metrics = app(BuildingMetricsService::class)->forBuilding($this->building);
        $this->assertSame([60, 10, '2026-03-02'], [$metrics->totalPlannedDays, $metrics->daysCompleted, $metrics->plannedFinish]);
    }

    /* ---------------- planned date and offset ---------------- */

    public function test_typing_a_planned_date_stores_the_offset(): void
    {
        $this->store(['description' => 'New', 'predecessor_id' => $this->ids['B'], 'planned_date' => '2026-02-10'])->assertSessionHasNoErrors();

        $new = Activity::where('description', 'New')->sole();
        $this->assertSame([10, 40, '2026-02-10'], [$new->offset_days, $new->planned_day, $new->planned_date->toDateString()]);
    }

    public function test_typing_the_days_after_gives_the_planned_date(): void
    {
        $this->actingAs($this->owner())->postJson("/buildings/{$this->building->id}/activities/preview", ['predecessor_id' => $this->ids['B'], 'offset_days' => 10])
            ->assertOk()->assertJson(['plannedDate' => '2026-02-10']);

        $this->store(['description' => 'New', 'predecessor_id' => $this->ids['B'], 'offset_days' => 10])->assertSessionHasNoErrors();
        $this->assertSame('2026-02-10', Activity::where('description', 'New')->sole()->planned_date->toDateString());
    }

    public function test_the_planned_date_wins_when_both_are_sent(): void
    {
        $this->store(['description' => 'New', 'predecessor_id' => $this->ids['B'], 'planned_date' => '2026-02-10', 'offset_days' => 99])->assertSessionHasNoErrors();

        $this->assertSame(10, Activity::where('description', 'New')->sole()->offset_days);
    }

    public function test_the_first_activity_counts_from_the_building_start(): void
    {
        $this->store(['description' => 'Survey', 'predecessor_id' => null, 'planned_date' => '2026-01-04'])->assertSessionHasNoErrors();

        $this->assertSame(3, Activity::where('description', 'Survey')->sole()->offset_days);
    }

    /**
     * B nudged +5 shows 5 Feb, but the next row still counts from B's work-day 30 (31 Jan): Excel's
     * column C, not column D. So 10 Feb is 10 days after B, not 5.
     */
    public function test_the_comes_after_rows_adjustment_does_not_carry_over(): void
    {
        DB::table('activities')->where('id', $this->ids['B'])->update(['date_adjustment_days' => 5]);
        app(ScheduleRecalculator::class)->recalculate($this->building);

        $this->store(['description' => 'New', 'predecessor_id' => $this->ids['B'], 'planned_date' => '2026-02-10'])->assertSessionHasNoErrors();

        $this->assertSame(10, Activity::where('description', 'New')->sole()->offset_days);
        $this->assertSame('2026-02-05', Activity::find($this->ids['B'])->planned_date->toDateString());
    }

    /** C's own +3 is part of its planned date, so it is taken off before the offset is stored. */
    public function test_editing_a_row_with_its_own_adjustment_keeps_it_in_the_date(): void
    {
        DB::table('activities')->where('id', $this->ids['C'])->update(['date_adjustment_days' => 3, 'adjustment_reason' => 'Rain']);
        app(ScheduleRecalculator::class)->recalculate($this->building);

        $this->update('C', ['planned_date' => '2026-02-20'])->assertSessionHasNoErrors();

        $c = Activity::find($this->ids['C']);
        $this->assertSame([17, 3, '2026-02-20'], [$c->offset_days, $c->date_adjustment_days, $c->planned_date->toDateString()]);
    }

    public function test_a_planned_date_before_the_comes_after_row_is_refused(): void
    {
        $this->store(['description' => 'New', 'predecessor_id' => $this->ids['B'], 'planned_date' => '2026-01-30'])
            ->assertSessionHasErrors(['planned_date' => 'The planned date cannot be before 2026-01-31: an activity cannot come before the one it comes after.']);
        $this->store(['description' => 'New', 'predecessor_id' => $this->ids['B']])
            ->assertSessionHasErrors(['planned_date' => 'Enter the planned date.']);

        $this->assertSame(0, Activity::where('description', 'New')->count());
    }

    /* ---------------- actual date ---------------- */

    public function test_the_dialog_records_an_actual_date_and_empty_means_not_done(): void
    {
        $this->update('B', ['actual_date' => '2026-02-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-02-01', Activity::find($this->ids['B'])->actual_date->toDateString());
        $this->assertSame([['actual_date', null, '2026-02-01']], $this->revisions('B'));

        $this->update('B', ['actual_date' => null])->assertSessionHasNoErrors();
        $this->assertNull(Activity::find($this->ids['B'])->actual_date);

        // a planned date that did not change moves nothing when only the actual is recorded
        $this->assertSame('2026-01-31', $this->plannedDates()['B']);
    }

    public function test_an_actual_date_cannot_be_in_the_future_or_before_the_buildings_actual_start(): void
    {
        $this->update('B', ['actual_date' => '2026-03-02'])
            ->assertSessionHasErrors(['actual_date' => 'The actual date cannot be in the future.']);
        $this->update('B', ['actual_date' => '2025-12-31'])
            ->assertSessionHasErrors(['actual_date' => 'The actual date cannot be before the building\'s actual start (2026-01-01).']);
        $this->update('B', ['actual_date' => '2026-03-01'])->assertSessionHasNoErrors();
    }

    public function test_a_new_activity_can_be_added_already_done(): void
    {
        $this->store(['description' => 'Survey', 'predecessor_id' => null, 'planned_date' => '2026-01-04', 'actual_date' => '2026-01-05'])->assertSessionHasNoErrors();

        $survey = Activity::where('description', 'Survey')->sole();
        $this->assertSame('2026-01-05', $survey->actual_date->toDateString());
        $this->assertContains(['actual_date', null, '2026-01-05'], $this->revisions($survey->id));
    }

    public function test_a_break_cannot_have_an_actual_date(): void
    {
        $this->store(['description' => 'Diwali', 'predecessor_id' => $this->ids['D'], 'offset_days' => 20, 'is_break' => true, 'actual_date' => '2026-02-25'])
            ->assertSessionHasErrors(['actual_date' => 'A break has no actual date.']);
        $this->update('Holi', ['actual_date' => '2026-02-25'])
            ->assertSessionHasErrors(['actual_date' => 'A break has no actual date.']);
        $this->update('B', ['is_break' => true, 'actual_date' => '2026-02-01'])
            ->assertSessionHasErrors(['actual_date' => 'A break has no actual date.']);

        // the quick "Record actual" form too; clearing is always allowed
        $this->patch("/activities/{$this->ids['Holi']}/actual", ['actual_date' => '2026-02-25'])
            ->assertSessionHasErrors(['actual_date' => 'A break has no actual date.']);
        $this->patch("/activities/{$this->ids['Holi']}/actual", ['actual_date' => null])->assertSessionHasNoErrors();

        $this->assertNull(Activity::find($this->ids['Holi'])->actual_date);
        $this->assertSame(0, Activity::where('description', 'Diwali')->count());
    }

    /* ---------------- insert between ---------------- */

    /**
     * New (7 days) below B, push: C now comes after New, so C, D and Holi move 7 days and the finish
     * with them. A's actual stays; the total grows to 67 work-days, so progress drops to 10/67.
     */
    public function test_inserting_with_push_moves_everything_after_by_the_new_activitys_days(): void
    {
        $actualsBefore = $this->actuals();

        $this->insertBelow('B', 7, push: true)->assertSessionHasNoErrors();

        $new = Activity::where('description', 'New')->sole();
        $this->assertSame([
            'A' => '2026-01-11', 'B' => '2026-01-31', 'New' => '2026-02-07', 'C' => '2026-02-22', 'D' => '2026-02-27', 'Holi' => '2026-03-09',
        ], $this->plannedDates());
        $this->assertSame(['A', 'B', 'New', 'C', 'D', 'Holi'], $this->rowOrder());
        $this->assertSame([1, 2, 3, 4, 5, 6], Activity::where('building_id', $this->building->id)->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame($new->id, Activity::find($this->ids['C'])->predecessor_id);
        $this->assertSame(15, Activity::find($this->ids['C'])->offset_days);
        $this->assertSame($actualsBefore, array_intersect_key($this->actuals(), $actualsBefore));
        $this->assertNull($new->actual_date);

        $metrics = app(BuildingMetricsService::class)->forBuilding($this->building);
        $this->assertSame([67, 10, '2026-03-09'], [$metrics->totalPlannedDays, $metrics->daysCompleted, $metrics->plannedFinish]);
        $this->assertSame(10 / 67, $metrics->progress);

        // the relink is the person's change, so it is audited on C
        $this->assertSame([['predecessor_id', (string) $this->ids['B'], (string) $new->id]], $this->revisions('C'));
    }

    public function test_inserting_without_push_moves_nothing_else(): void
    {
        $this->insertBelow('B', 7, push: false)->assertSessionHasNoErrors();

        $this->assertSame([
            'A' => '2026-01-11', 'B' => '2026-01-31', 'New' => '2026-02-07', 'C' => '2026-02-15', 'D' => '2026-02-20', 'Holi' => '2026-03-02',
        ], $this->plannedDates());
        $this->assertSame(['A', 'B', 'New', 'C', 'D', 'Holi'], $this->rowOrder());
        $this->assertSame($this->ids['B'], Activity::find($this->ids['C'])->predecessor_id);
        $this->assertSame([], $this->revisions('C'));
    }

    /** The preview says exactly what saving then does. */
    public function test_the_preview_of_an_insert_with_push_lists_what_moves(): void
    {
        $this->actingAs($this->owner())->postJson("/buildings/{$this->building->id}/activities/preview", $this->insertion('B', 7, push: true))
            ->assertOk()
            ->assertExactJson([
                'plannedDate' => '2026-02-07',
                'changedCount' => 3,
                'finishBefore' => '2026-03-02',
                'finishAfter' => '2026-03-09',
                'changed' => [
                    ['id' => $this->ids['C'], 'description' => 'C', 'before' => '2026-02-15', 'after' => '2026-02-22'],
                    ['id' => $this->ids['D'], 'description' => 'D', 'before' => '2026-02-20', 'after' => '2026-02-27'],
                    ['id' => $this->ids['Holi'], 'description' => 'Holi', 'before' => '2026-03-02', 'after' => '2026-03-09'],
                ],
                'pushes' => 'C',
            ]);

        $this->actingAs($this->owner())->postJson("/buildings/{$this->building->id}/activities/preview", $this->insertion('B', 7, push: false))
            ->assertOk()->assertJson(['changedCount' => 0, 'changed' => [], 'pushes' => null, 'finishAfter' => '2026-03-02']);
    }

    /** Below D the next row is Holi, which comes after C, not D: there is nothing to push. */
    public function test_nothing_is_pushed_when_the_row_below_does_not_come_after_the_row_above(): void
    {
        $this->actingAs($this->owner())->postJson("/buildings/{$this->building->id}/activities/preview", $this->insertion('D', 3, push: true))
            ->assertOk()->assertJson(['pushes' => null, 'changedCount' => 0]);

        $this->insertBelow('D', 3, push: true)->assertSessionHasNoErrors();

        $this->assertSame([
            'A' => '2026-01-11', 'B' => '2026-01-31', 'C' => '2026-02-15', 'D' => '2026-02-20', 'New' => '2026-02-23', 'Holi' => '2026-03-02',
        ], $this->plannedDates());
        $this->assertSame($this->ids['C'], Activity::find($this->ids['Holi'])->predecessor_id);
    }

    public function test_inserting_below_the_last_row_has_nothing_to_push(): void
    {
        $this->insertBelow('Holi', 4, push: true)->assertSessionHasNoErrors();

        $this->assertSame(['A', 'B', 'C', 'D', 'Holi', 'New'], $this->rowOrder());
    }

    public function test_where_to_insert_must_be_a_row_of_this_building_and_only_for_a_new_activity(): void
    {
        $other = Building::create(['project_id' => $this->building->project_id, 'name' => 'Tower 2']);
        $stranger = DB::table('activities')->insertGetId(['building_id' => $other->id, 'description' => 'X', 'planned_date' => '2026-01-01']);

        $this->store([...$this->insertion('B', 7, push: true), 'after_id' => $stranger])
            ->assertSessionHasErrors(['after_id' => 'Choose a row from this building to insert below.']);
        $this->update('C', ['after_id' => $this->ids['A'], 'push' => true])
            ->assertSessionHasErrors(['after_id', 'push']);
    }

    public function test_a_failed_insert_changes_no_position(): void
    {
        $this->store([...$this->insertion('B', 7, push: true), 'planned_date' => '2026-01-01'])->assertSessionHasErrors('planned_date');

        $this->assertSame(['A', 'B', 'C', 'D', 'Holi'], $this->rowOrder());
        $this->assertSame([1, 2, 3, 4, 5], Activity::orderBy('sort_order')->pluck('sort_order')->all());
    }

    /* ---------------- helpers ---------------- */

    /**
     * @param  array<string, mixed>  $fields
     */
    private function store(array $fields): TestResponse
    {
        return $this->actingAs($this->owner())->post("/buildings/{$this->building->id}/activities", [
            'is_break' => false, 'notes' => null, 'actual_date' => null, ...$fields,
        ]);
    }

    /**
     * The dialog's save for an existing row: its current fields, with these changed.
     *
     * @param  array<string, mixed>  $changes
     */
    private function update(string $name, array $changes): TestResponse
    {
        $activity = Activity::find($this->ids[$name]);

        return $this->actingAs($this->owner())->patch("/activities/{$activity->id}", [
            'description' => $activity->description,
            'predecessor_id' => $activity->predecessor_id,
            'planned_date' => $activity->planned_date->toDateString(),
            'actual_date' => $activity->actual_date?->toDateString(),
            'is_break' => $activity->is_break,
            'notes' => $activity->notes,
            ...$changes,
        ]);
    }

    private function insertBelow(string $name, int $days, bool $push): TestResponse
    {
        return $this->store($this->insertion($name, $days, $push));
    }

    /**
     * A new activity "New", $days after $name and placed right below it.
     *
     * @return array<string, mixed>
     */
    private function insertion(string $name, int $days, bool $push): array
    {
        return ['description' => 'New', 'predecessor_id' => $this->ids[$name], 'offset_days' => $days, 'after_id' => $this->ids[$name], 'push' => $push];
    }

    /**
     * @return array<string, string>
     */
    private function plannedDates(): array
    {
        return Activity::where('building_id', $this->building->id)->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (Activity $a) => [$a->description => $a->planned_date->toDateString()])->all();
    }

    /**
     * @return list<string>
     */
    private function rowOrder(): array
    {
        return Activity::where('building_id', $this->building->id)->orderBy('sort_order')->orderBy('id')->pluck('description')->all();
    }

    /**
     * @return array<int, ?string>
     */
    private function actuals(): array
    {
        return DB::table('activities')->orderBy('id')->pluck('actual_date', 'id')->all();
    }

    /**
     * @return list<array{string, ?string, ?string}>
     */
    private function revisions(string|int $activity): array
    {
        return ActivityRevision::where('activity_id', is_int($activity) ? $activity : $this->ids[$activity])->orderBy('id')->get()
            ->map(fn (ActivityRevision $r) => [$r->field, $r->old_value, $r->new_value])->all();
    }
}
