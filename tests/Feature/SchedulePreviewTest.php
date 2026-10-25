<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The edit preview: the recalculator's own computation on the proposed graph, in memory. It must
 * write nothing, and it must predict exactly what saving the change then does.
 */
class SchedulePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Building $towerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkbookSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->towerA = Building::where('name', 'Tower A')->sole();
    }

    public function test_preview_writes_nothing(): void
    {
        $plaster = $this->activity('INT. & EXT. PLASTER 75');
        $before = $this->snapshot();

        $this->actingAs($this->admin)->postJson("/activities/{$plaster->id}/preview", $this->chain($plaster, ['offset_days' => 82]))->assertOk();
        $this->actingAs($this->admin)->postJson("/buildings/{$this->towerA->id}/activities/preview", ['predecessor_id' => $plaster->id, 'offset_days' => 5, 'date_adjustment_days' => 0])->assertOk();

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * Preview, then save the same change: the count, the finish and the row's own date must be
     * what saving actually produced.
     */
    public function test_preview_predicts_exactly_what_saving_does(): void
    {
        $plaster = $this->activity('INT. & EXT. PLASTER 75');
        $before = $this->plannedDates();

        $preview = $this->actingAs($this->admin)
            ->postJson("/activities/{$plaster->id}/preview", $this->chain($plaster, ['offset_days' => 82]))
            ->assertOk()->json();

        $this->actingAs($this->admin)->patch("/activities/{$plaster->id}", [
            ...$this->chain($plaster, ['offset_days' => 82]),
            'description' => $plaster->description, 'adjustment_reason' => null, 'is_break' => false, 'notes' => null,
        ])->assertSessionHasNoErrors();

        $after = $this->plannedDates();
        $movedOthers = array_diff(array_keys(array_diff_assoc($after, $before)), [$plaster->id]);

        $this->assertSame(count($movedOthers), $preview['changedCount']);
        $this->assertSame(['2028-08-05', '2028-08-12'], [$preview['finishBefore'], $preview['finishAfter']]);
        $this->assertSame(max($after), $preview['finishAfter']);
        $this->assertSame($after[$plaster->id], $preview['plannedDate']);
    }

    public function test_preview_of_a_new_activity(): void
    {
        $handover = $this->activity('FLAT HANDOVER');

        $this->actingAs($this->admin)
            ->postJson("/buildings/{$this->towerA->id}/activities/preview", ['predecessor_id' => $handover->id, 'offset_days' => 14, 'date_adjustment_days' => 0])
            ->assertOk()
            ->assertExactJson(['plannedDate' => '2028-08-19', 'changedCount' => 0, 'finishBefore' => '2028-08-05', 'finishAfter' => '2028-08-19']);
    }

    /** A date adjustment moves the row's own date and nothing downstream. */
    public function test_preview_of_an_adjustment_moves_only_that_row(): void
    {
        $slab = $this->activity('3rd SLAB 15');

        $this->actingAs($this->admin)
            ->postJson("/activities/{$slab->id}/preview", $this->chain($slab, ['date_adjustment_days' => 4]))
            ->assertOk()
            ->assertJson(['changedCount' => 0, 'finishBefore' => '2028-08-05', 'finishAfter' => '2028-08-05']);
    }

    public function test_preview_of_a_cycle_is_refused_with_the_activities_named(): void
    {
        $first = $this->activity('1st SLAB');
        $third = $this->activity('3rd SLAB 15');

        $response = $this->actingAs($this->admin)
            ->postJson("/activities/{$first->id}/preview", $this->chain($first, ['predecessor_id' => $third->id]))
            ->assertUnprocessable()
            ->assertJsonMissingPath('changedCount');

        $this->assertSame(
            'Predecessor cycle: 1st SLAB → 2nd SLAB 20 → 3rd SLAB 15 → 1st SLAB',
            $response->json('errors.predecessor_id.0'),
        );
    }

    private function activity(string $description): Activity
    {
        return Activity::where('building_id', $this->towerA->id)->where('description', $description)->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function chain(Activity $activity, array $overrides = []): array
    {
        return [
            'predecessor_id' => $activity->predecessor_id,
            'offset_days' => $activity->offset_days,
            'date_adjustment_days' => $activity->date_adjustment_days,
            ...$overrides,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function plannedDates(): array
    {
        return DB::table('activities')->where('building_id', $this->towerA->id)->orderBy('id')->pluck('planned_date', 'id')->all();
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
