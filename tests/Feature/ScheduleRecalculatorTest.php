<?php

namespace Tests\Feature;

use App\Exceptions\InvalidScheduleException;
use App\Exceptions\ScheduleCycleException;
use App\Models\Project;
use App\Models\Scope;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleRecalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const STALE_DATE = '2000-01-01';

    private const FIXED_TIMESTAMP = '2026-01-01 00:00:00';

    private Project $project;

    private Scope $scope;

    private int $sortOrder = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::create(['name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        $this->scope = Scope::create(['project_id' => $this->project->id, 'name' => 'Tower A']);
    }

    public function test_straight_chain_computes_correctly(): void
    {
        $gf = $this->activity('DAY 1 TO GF SLAB', null, 180);
        $first = $this->activity('1st SLAB', $gf, 20);
        $second = $this->activity('2nd SLAB', $first, 40);

        $this->recalculate();

        $this->assertPlanned($gf, 180, '2026-10-02');
        $this->assertPlanned($first, 200, '2026-10-22');
        $this->assertPlanned($second, 240, '2026-12-01');
    }

    public function test_two_activities_sharing_one_predecessor_both_compute(): void
    {
        $root = $this->activity('Root', null, 10);
        $short = $this->activity('Short branch', $root, 5);
        $long = $this->activity('Long branch', $root, 30);

        $this->recalculate();

        $this->assertPlanned($root, 10, '2026-04-15');
        $this->assertPlanned($short, 15, '2026-04-20');
        $this->assertPlanned($long, 40, '2026-05-15');
    }

    /**
     * The workbook's real pattern: GROUND LEVEL PLASTER follows INT. & EXT. PLASTER six rows earlier,
     * not the row above it. A "previous row + offset" implementation gives 190 here instead of 135.
     */
    public function test_activity_branching_back_several_rows_follows_its_predecessor_not_the_row_above(): void
    {
        $plaster = $this->activity('INT. & EXT. PLASTER', null, 100);
        $previous = $plaster;
        foreach (['Row 26', 'Row 27', 'Row 28', 'Row 29', 'Row 30'] as $description) {
            $previous = $this->activity($description, $previous, 10);
        }
        $groundLevel = $this->activity('GROUND LEVEL PLASTER', $plaster, 35);
        $afterGroundLevel = $this->activity('After ground level', $groundLevel, 5);

        $this->recalculate();

        $this->assertPlanned($previous, 150, '2026-09-02');
        $this->assertPlanned($groundLevel, 135, '2026-08-18');
        $this->assertPlanned($afterGroundLevel, 140, '2026-08-23');
    }

    public function test_date_adjustment_shifts_planned_date_but_not_planned_day_or_anything_downstream(): void
    {
        $a = $this->activity('A', null, 20);
        $diwali = $this->activity('DIWALI BREAK', $a, 10, adjustment: 17);
        $after = $this->activity('After Diwali', $diwali, 5);

        $this->recalculate();

        $this->assertPlanned($a, 20, '2026-04-25');
        $this->assertPlanned($diwali, 30, '2026-05-22');
        $this->assertPlanned($after, 35, '2026-05-10');
    }

    public function test_direct_cycle_is_rejected_naming_both_activities(): void
    {
        $a = $this->activity('Alpha', null, 10);
        $b = $this->activity('Bravo', $a, 10);
        $this->setPredecessor($a, $b);

        $exception = $this->recalculateExpectingCycle();

        $this->assertEqualsCanonicalizing([$a, $b], $exception->activityIds);
        $this->assertStringContainsString('Alpha', $exception->getMessage());
        $this->assertStringContainsString('Bravo', $exception->getMessage());
    }

    public function test_longer_cycle_is_rejected_naming_all_three_activities(): void
    {
        $root = $this->activity('Valid root', null, 10);
        $a = $this->activity('Alpha', null, 10);
        $b = $this->activity('Bravo', $a, 10);
        $c = $this->activity('Charlie', $b, 10);
        $this->setPredecessor($a, $c);
        $hangingOff = $this->activity('Hanging off the cycle', $b, 10);

        $exception = $this->recalculateExpectingCycle();

        $this->assertEqualsCanonicalizing([$a, $b, $c], $exception->activityIds);
        $this->assertSame('Predecessor cycle: Alpha → Bravo → Charlie → Alpha', $exception->getMessage());
        foreach (['Alpha', 'Bravo', 'Charlie'] as $description) {
            $this->assertStringContainsString($description, $exception->getMessage());
        }
        $this->assertStringNotContainsString('Valid root', $exception->getMessage());
        $this->assertStringNotContainsString('Hanging off the cycle', $exception->getMessage());
    }

    public function test_changing_one_offset_mid_chain_moves_every_downstream_date(): void
    {
        $a = $this->activity('A', null, 10);
        $b = $this->activity('B', $a, 10);
        $c = $this->activity('C', $b, 10);
        $d = $this->activity('D', $c, 10);
        $sibling = $this->activity('Sibling of B', $a, 5);

        $this->recalculate();
        $this->assertPlanned($d, 40, '2026-05-15');

        DB::table('activities')->where('id', $b)->update(['offset_days' => 25]);
        $this->recalculate();

        $this->assertPlanned($a, 10, '2026-04-15');
        $this->assertPlanned($b, 35, '2026-05-10');
        $this->assertPlanned($c, 45, '2026-05-20');
        $this->assertPlanned($d, 55, '2026-05-30');
        $this->assertPlanned($sibling, 15, '2026-04-20');
    }

    public function test_multiple_chain_roots_in_one_scope_all_compute(): void
    {
        $firstRoot = $this->activity('First root', null, 0);
        $firstChild = $this->activity('First child', $firstRoot, 7);
        $secondRoot = $this->activity('Second root', null, 50);
        $secondChild = $this->activity('Second child', $secondRoot, 3);

        $this->recalculate();

        $this->assertPlanned($firstRoot, 0, '2026-04-05');
        $this->assertPlanned($firstChild, 7, '2026-04-12');
        $this->assertPlanned($secondRoot, 50, '2026-05-25');
        $this->assertPlanned($secondChild, 53, '2026-05-28');
    }

    public function test_scope_with_no_activities_is_not_an_error(): void
    {
        $this->recalculate();

        $this->assertSame(0, DB::table('activities')->count());
    }

    /**
     * A recalculator that writes valid rows before discovering the cycle would corrupt the schedule
     * invisibly. Every column of every row must be exactly as it was.
     */
    public function test_rejected_cycle_leaves_every_stored_value_unchanged(): void
    {
        $root = $this->activity('Valid root', null, 10);
        $child = $this->activity('Valid child', $root, 10);
        $a = $this->activity('Alpha', $child, 10);
        $b = $this->activity('Bravo', $a, 10);
        $downstream = $this->activity('Downstream of cycle', $b, 10);
        $this->recalculate();

        // Make a valid recalculation necessary, then break the graph.
        DB::table('activities')->where('id', $root)->update(['offset_days' => 99]);
        $this->setPredecessor($a, $b);
        $before = $this->snapshotAllActivities();

        $this->recalculateExpectingCycle();

        $this->assertSame($before, $this->snapshotAllActivities());
        $this->assertCount(5, $before);
        $this->assertPlanned($root, 10, '2026-04-15');
    }

    public function test_predecessor_in_another_scope_is_rejected(): void
    {
        $otherScope = Scope::create(['project_id' => $this->project->id, 'name' => 'Tower B']);
        $foreign = $this->activity('Tower B activity', null, 10, scopeId: $otherScope->id);
        $this->activity('Points across scopes', $foreign, 10);

        $this->expectException(InvalidScheduleException::class);
        $this->expectExceptionMessage('Points across scopes');

        $this->recalculate();
    }

    public function test_only_planned_columns_of_the_given_scope_are_written(): void
    {
        $otherScope = Scope::create(['project_id' => $this->project->id, 'name' => 'Tower B']);
        $other = $this->activity('Tower B activity', null, 10, scopeId: $otherScope->id);
        $mine = $this->activity('Tower A activity', null, 10);
        $before = $this->snapshotAllActivities();

        $this->recalculate();
        $after = $this->snapshotAllActivities();

        $this->assertSame($before[$other], $after[$other]);
        $this->assertPlanned($mine, 10, '2026-04-15');
        $changed = array_keys(array_diff_assoc($before[$mine], $after[$mine]));
        $this->assertEqualsCanonicalizing(['planned_day', 'planned_date'], $changed);
    }

    /**
     * Insert directly: planned_day and planned_date are not fillable, and a stale value proves the
     * recalculator wrote them.
     */
    private function activity(string $description, ?int $predecessorId, int $offset, int $adjustment = 0, ?int $scopeId = null): int
    {
        return DB::table('activities')->insertGetId([
            'scope_id' => $scopeId ?? $this->scope->id,
            'description' => $description,
            'predecessor_id' => $predecessorId,
            'offset_days' => $offset,
            'date_adjustment_days' => $adjustment,
            'adjustment_reason' => $adjustment === 0 ? null : 'carried from original sheet',
            'planned_day' => 0,
            'planned_date' => self::STALE_DATE,
            'sort_order' => ++$this->sortOrder,
            'created_at' => self::FIXED_TIMESTAMP,
            'updated_at' => self::FIXED_TIMESTAMP,
        ]);
    }

    private function setPredecessor(int $activityId, int $predecessorId): void
    {
        DB::table('activities')->where('id', $activityId)->update(['predecessor_id' => $predecessorId]);
    }

    private function recalculate(): void
    {
        app(ScheduleRecalculator::class)->recalculate($this->scope);
    }

    private function recalculateExpectingCycle(): ScheduleCycleException
    {
        try {
            $this->recalculate();
        } catch (ScheduleCycleException $exception) {
            return $exception;
        }

        $this->fail('Expected a ScheduleCycleException.');
    }

    private function assertPlanned(int $activityId, int $day, string $date): void
    {
        $row = DB::table('activities')->where('id', $activityId)->first();

        $this->assertSame(
            [$day, $date],
            [(int) $row->planned_day, $row->planned_date],
            "Activity [{$row->description}] planned day/date",
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshotAllActivities(): array
    {
        return DB::table('activities')->orderBy('id')->get()
            ->mapWithKeys(fn ($row) => [$row->id => (array) $row])
            ->all();
    }
}
