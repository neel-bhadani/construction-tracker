<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\Scope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The schedule's write paths, against the real imported Tower A chain: what each change writes,
 * what it recalculates, what it audits, and what it refuses.
 */
class ScheduleEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private Scope $towerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkbookSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Asia/Kolkata'));
        $this->admin = User::factory()->admin()->create();
        $this->editor = User::factory()->editor()->create();
        $this->towerA = Scope::where('name', 'Tower A')->sole();
    }

    /* ---------------- recording actual dates ---------------- */

    public function test_recording_an_actual_writes_two_revisions_and_moves_no_planned_date(): void
    {
        $slab = $this->activity('1st SLAB');
        $planned = $this->plannedDates();

        $this->actingAs($this->editor)->patch("/activities/{$slab->id}/actual", ['actual_date' => '2026-10-10', 'notes' => 'Poured a day late'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-10', $slab->fresh()->actual_date->toDateString());
        $this->assertSame($planned, $this->plannedDates());
        $this->assertSame([
            ['actual_date', null, '2026-10-10', $this->editor->id],
            ['notes', null, 'Poured a day late', $this->editor->id],
        ], $this->revisions($slab));
    }

    public function test_editor_can_clear_a_wrong_actual_date_and_it_is_audited(): void
    {
        $first = $this->activity('DAY 1 TO GF SLAB - 180 DAYS');

        $this->actingAs($this->editor)->patch("/activities/{$first->id}/actual", ['actual_date' => null, 'notes' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($first->fresh()->actual_date);
        $this->assertSame([['actual_date', '2026-09-20', null, $this->editor->id]], $this->revisions($first));
    }

    /** The clock is 2026-10-15 in Kolkata: today is allowed, tomorrow is not. */
    public function test_actual_date_cannot_be_in_the_future_or_before_the_actual_start(): void
    {
        $slab = $this->activity('1st SLAB');
        $this->travelTo(CarbonImmutable::parse('2026-10-15 01:00', 'Asia/Kolkata'));

        $this->actingAs($this->editor)->patch("/activities/{$slab->id}/actual", ['actual_date' => '2026-10-16'])
            ->assertSessionHasErrors(['actual_date' => 'The actual date cannot be in the future.']);
        $this->actingAs($this->editor)->patch("/activities/{$slab->id}/actual", ['actual_date' => '2026-04-04'])
            ->assertSessionHasErrors('actual_date');
        $this->actingAs($this->editor)->patch("/activities/{$slab->id}/actual", ['actual_date' => '2026-10-15'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-15', $slab->fresh()->actual_date->toDateString());
    }

    /* ---------------- editing the plan ---------------- */

    /**
     * One duration change mid-chain moves dozens of dates and writes exactly one revision: the
     * cascade is not audited.
     */
    public function test_one_offset_change_moves_every_downstream_date_and_writes_one_revision(): void
    {
        $plaster = $this->activity('INT. & EXT. PLASTER 75');
        $before = $this->plannedDates();

        $this->actingAs($this->admin)->patch("/activities/{$plaster->id}", $this->plan($plaster, ['offset_days' => 82]))
            ->assertSessionHasNoErrors();

        $moved = array_keys(array_diff_assoc($this->plannedDates(), $before));
        $this->assertGreaterThan(25, count($moved));
        $this->assertSame('2028-08-12', Activity::where('scope_id', $this->towerA->id)->max('planned_date'));
        $this->assertSame([['offset_days', '75', '82', $this->admin->id]], $this->revisions($plaster));
        $this->assertSame(1, ActivityRevision::count());
    }

    public function test_every_changed_field_is_audited_once(): void
    {
        $slab = $this->activity('3rd SLAB 15');

        $this->actingAs($this->admin)->patch("/activities/{$slab->id}", $this->plan($slab, [
            'description' => '3rd SLAB 16',
            'offset_days' => 16,
            'date_adjustment_days' => 2,
            'adjustment_reason' => 'Crane booked late',
            'is_break' => true,
            'notes' => 'See site diary',
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([
            ['offset_days', '15', '16', $this->admin->id],
            ['date_adjustment_days', '0', '2', $this->admin->id],
            ['adjustment_reason', null, 'Crane booked late', $this->admin->id],
            ['description', '3rd SLAB 15', '3rd SLAB 16', $this->admin->id],
            ['is_break', 'false', 'true', $this->admin->id],
            ['notes', null, 'See site diary', $this->admin->id],
        ], $this->revisions($slab));
    }

    public function test_saving_without_changes_writes_nothing(): void
    {
        $slab = $this->activity('3rd SLAB 15');

        $this->actingAs($this->admin)->patch("/activities/{$slab->id}", $this->plan($slab))->assertSessionHasNoErrors();

        $this->assertSame(0, ActivityRevision::count());
    }

    public function test_adjustment_needs_a_reason_and_a_zero_adjustment_drops_it(): void
    {
        $slab = $this->activity('3rd SLAB 15');
        $diwali = $this->activity('DIWALI (08-11-2026) BREAK 20');

        $this->actingAs($this->admin)->patch("/activities/{$slab->id}", $this->plan($slab, ['date_adjustment_days' => 3, 'adjustment_reason' => '']))
            ->assertSessionHasErrors('adjustment_reason');

        $this->actingAs($this->admin)->patch("/activities/{$diwali->id}", $this->plan($diwali, ['date_adjustment_days' => 0]))
            ->assertSessionHasNoErrors();
        $this->assertNull($diwali->fresh()->adjustment_reason);
    }

    public function test_predecessor_must_be_in_the_same_scope_and_not_itself(): void
    {
        $slab = $this->activity('3rd SLAB 15');
        $towerB = Activity::where('scope_id', Scope::where('name', 'Tower B')->sole()->id)->first();

        $this->actingAs($this->admin)->patch("/activities/{$slab->id}", $this->plan($slab, ['predecessor_id' => $towerB->id]))
            ->assertSessionHasErrors(['predecessor_id' => 'Choose an activity from this scope.']);
        $this->actingAs($this->admin)->patch("/activities/{$slab->id}", $this->plan($slab, ['predecessor_id' => $slab->id]))
            ->assertSessionHasErrors(['predecessor_id' => 'An activity cannot follow itself.']);
    }

    public function test_a_predecessor_that_would_create_a_cycle_is_refused_with_the_cycle_named_and_nothing_written(): void
    {
        $first = $this->activity('1st SLAB');
        $third = $this->activity('3rd SLAB 15');
        $before = $this->snapshot();

        $this->actingAs($this->admin)->patch("/activities/{$first->id}", $this->plan($first, ['predecessor_id' => $third->id]))
            ->assertSessionHasErrors('predecessor_id');

        $message = session('errors')->first('predecessor_id');
        foreach (['1st SLAB', '2nd SLAB 20', '3rd SLAB 15'] as $description) {
            $this->assertStringContainsString($description, $message);
        }
        $this->assertStringNotContainsString('DIWALI', $message);
        $this->assertSame($before, $this->snapshot());
    }

    /* ---------------- adding and deleting ---------------- */

    public function test_adding_an_activity_writes_a_placeholder_and_recalculates_in_one_go(): void
    {
        $handover = $this->activity('FLAT HANDOVER');

        $this->actingAs($this->admin)->post("/scopes/{$this->towerA->id}/activities", [
            'description' => 'SNAG LIST', 'predecessor_id' => $handover->id, 'offset_days' => 14,
            'date_adjustment_days' => 0, 'adjustment_reason' => null, 'is_break' => false, 'notes' => null,
        ])->assertSessionHasNoErrors();

        $added = Activity::where('description', 'SNAG LIST')->sole();
        $this->assertSame([867, '2028-08-19', 56], [$added->planned_day, $added->planned_date->toDateString(), $added->sort_order]);
        $this->assertSame([
            ['created', null, 'SNAG LIST', $this->admin->id],
            ['predecessor_id', null, (string) $handover->id, $this->admin->id],
            ['offset_days', null, '14', $this->admin->id],
        ], $this->revisions($added));
    }

    public function test_deleting_an_activity_others_follow_is_refused_with_them_named(): void
    {
        $fireNoc = $this->activity('FIRE NOC AND LIFT NOC 45');
        $before = $this->snapshot();

        $this->actingAs($this->admin)->delete("/activities/{$fireNoc->id}")
            ->assertSessionHasErrors('activity');

        $message = session('errors')->first('activity');
        foreach (['PROJECT BUC', 'TOWER A: ENTRANCE FOYER', 'TRANSFORMERS AND ELE. METER'] as $dependant) {
            $this->assertStringContainsString($dependant, $message);
        }
        $this->assertSame($before, $this->snapshot());
    }

    /** The audit trail outlives the row: earlier revisions stay, and the deletion is recorded. */
    public function test_deleting_a_leaf_keeps_its_audit_trail_and_records_the_deletion(): void
    {
        $handover = $this->activity('FLAT HANDOVER');
        $this->actingAs($this->editor)->patch("/activities/{$handover->id}/actual", ['actual_date' => null, 'notes' => 'Keys ready']);

        $this->actingAs($this->admin)->delete("/activities/{$handover->id}")->assertSessionHasNoErrors();

        $this->assertNull(Activity::find($handover->id));
        $this->assertSame([
            [null, 'notes', null, 'Keys ready'],
            [null, 'deleted', 'FLAT HANDOVER', null],
        ], ActivityRevision::orderBy('id')->get()->map(fn ($r) => [$r->activity_id, $r->field, $r->old_value, $r->new_value])->all());
        $this->assertSame('2028-07-06', Activity::where('scope_id', $this->towerA->id)->max('planned_date'));
    }

    public function test_two_deleted_activities_keep_separate_histories(): void
    {
        $handover = $this->activity('FLAT HANDOVER');
        $foyer = $this->activity('TOWER A: ENTRANCE FOYER');
        $this->actingAs($this->editor)->patch("/activities/{$handover->id}/actual", ['actual_date' => null, 'notes' => 'Keys ready']);
        $this->actingAs($this->editor)->patch("/activities/{$foyer->id}/actual", ['actual_date' => null, 'notes' => 'Marble chosen']);

        $this->actingAs($this->admin)->delete("/activities/{$handover->id}")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete("/activities/{$foyer->id}")->assertSessionHasNoErrors();

        $history = fn (int $id) => ActivityRevision::where('activity_snapshot_id', $id)->orderBy('id')->get()
            ->map(fn ($r) => [$r->activity_id, $r->field, $r->old_value, $r->new_value])->all();

        $this->assertSame([[null, 'notes', null, 'Keys ready'], [null, 'deleted', 'FLAT HANDOVER', null]], $history($handover->id));
        $this->assertSame([[null, 'notes', null, 'Marble chosen'], [null, 'deleted', 'TOWER A: ENTRANCE FOYER', null]], $history($foyer->id));
    }

    public function test_every_revision_carries_its_activity_id_snapshot(): void
    {
        $slab = $this->activity('3rd SLAB 15');
        $this->actingAs($this->admin)->patch("/activities/{$slab->id}", $this->plan($slab, ['offset_days' => 16, 'notes' => 'x']));
        $this->actingAs($this->admin)->post("/scopes/{$this->towerA->id}/activities", [
            'description' => 'NEW', 'predecessor_id' => null, 'offset_days' => 1,
            'date_adjustment_days' => 0, 'adjustment_reason' => null, 'is_break' => false, 'notes' => null,
        ]);

        $this->assertGreaterThan(2, ActivityRevision::count());
        $this->assertSame(0, ActivityRevision::whereColumn('activity_snapshot_id', '!=', 'activity_id')->count());
    }

    /* ---------------- project dates ---------------- */

    public function test_moving_the_planned_start_recalculates_every_scope_and_is_audited(): void
    {
        $before = $this->plannedDates(null);
        $project = Project::sole();

        $this->actingAs($this->admin)->patch("/projects/{$project->id}/dates", ['planned_start_date' => '2026-04-15', 'actual_start_date' => null])
            ->assertSessionHasNoErrors();

        $after = $this->plannedDates(null);
        $this->assertCount(151, $after);
        foreach ($before as $id => $date) {
            $this->assertSame(CarbonImmutable::parse($date)->addDays(10)->toDateString(), $after[$id]);
        }
        $this->assertSame([['planned_start_date', '2026-04-05', '2026-04-15', $this->admin->id]],
            ProjectRevision::get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value, $r->user_id])->all());
        $this->assertSame(0, ActivityRevision::count());
    }

    public function test_actual_start_after_recorded_actuals_is_refused_with_them_named(): void
    {
        $project = Project::sole();

        $this->actingAs($this->admin)->patch("/projects/{$project->id}/dates", ['planned_start_date' => '2026-04-05', 'actual_start_date' => '2026-09-25'])
            ->assertSessionHasErrors('actual_start_date');

        $message = session('errors')->first('actual_start_date');
        $this->assertStringContainsString('Tower A: DAY 1 TO GF SLAB - 180 DAYS', $message);
        $this->assertStringContainsString('Tower B: 1st SLAB', $message);
        // 5 Oct is after the new start, so it is not named
        $this->assertStringNotContainsString('2nd SLAB', $message);
        $this->assertNull($project->fresh()->actual_start_date);
        $this->assertSame(0, ProjectRevision::count());
    }

    public function test_setting_the_actual_start_is_audited(): void
    {
        $project = Project::sole();

        $this->actingAs($this->admin)->patch("/projects/{$project->id}/dates", ['planned_start_date' => '2026-04-05', 'actual_start_date' => '2026-05-09'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-05-09', $project->fresh()->actual_start_date->toDateString());
        $this->assertSame([['actual_start_date', null, '2026-05-09']], ProjectRevision::get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all());
    }

    /* ---------------- helpers ---------------- */

    private function activity(string $description, ?Scope $scope = null): Activity
    {
        return Activity::where('scope_id', ($scope ?? $this->towerA)->id)->where('description', $description)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function plan(Activity $activity, array $overrides = []): array
    {
        return [
            'description' => $activity->description,
            'predecessor_id' => $activity->predecessor_id,
            'offset_days' => $activity->offset_days,
            'date_adjustment_days' => $activity->date_adjustment_days,
            'adjustment_reason' => $activity->adjustment_reason,
            'is_break' => $activity->is_break,
            'notes' => $activity->notes,
            ...$overrides,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function plannedDates(?Scope $scope = null): array
    {
        $query = DB::table('activities')->orderBy('id');
        if (func_num_args() === 0) {
            $query->where('scope_id', $this->towerA->id);
        }

        return $query->pluck('planned_date', 'id')->all();
    }

    /**
     * @return list<array{string, ?string, ?string, int}>
     */
    private function revisions(Activity $activity): array
    {
        return ActivityRevision::where('activity_id', $activity->id)->orderBy('id')->get()
            ->map(fn ($r) => [$r->field, $r->old_value, $r->new_value, $r->user_id])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            DB::table('activities')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('activity_revisions')->count(),
        ];
    }
}
