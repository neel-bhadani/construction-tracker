<?php

namespace Tests\Feature;

use App\Import\ScheduleWorkbook;
use App\Models\Project;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class WorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkbookSeeder::class);
    }

    /**
     * The migration's proof: every computed date equals the workbook's own cached cell. All 151.
     */
    public function test_every_planned_date_matches_the_workbook(): void
    {
        $compared = 0;
        $mismatches = [];

        foreach (array_keys(ScheduleWorkbook::SOURCES) as $scope) {
            $stored = $this->activities($scope)->keyBy('sort_order');

            foreach (ScheduleWorkbook::read($scope)->activities as $row => $expected) {
                $actual = $stored[$row] ?? null;
                $compared++;

                if ($actual?->description !== $expected['description']
                    || (int) $actual->planned_day !== $expected['expected_day']
                    || $actual->planned_date !== $expected['expected_date']) {
                    $mismatches[] = sprintf(
                        '%s row %d [%s]: expected %d / %s, got %s / %s',
                        $scope, $row, $expected['description'], $expected['expected_day'], $expected['expected_date'],
                        $actual->planned_day ?? 'missing', $actual->planned_date ?? 'missing',
                    );
                }
            }
        }

        $this->assertSame(151, $compared);
        $this->assertSame([], $mismatches);
    }

    #[DataProvider('scopeTotals')]
    public function test_scope_totals_match_the_workbook(string $scope, int $activities, int $totalDays, string $finish): void
    {
        $stored = $this->activities($scope);

        $this->assertCount($activities, $stored);
        $this->assertSame($totalDays, (int) $stored->max('planned_day'));
        $this->assertSame($finish, $stored->max('planned_date'));
    }

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function scopeTotals(): array
    {
        return [
            'Tower A' => ['Tower A', 52, 853, '2028-08-05'],
            'Tower B' => ['Tower B', 52, 843, '2028-07-26'],
            'Overall Timeline' => ['Overall Timeline', 47, 825, '2028-07-08'],
        ];
    }

    public function test_project_starts_on_the_workbook_start_date(): void
    {
        $project = Project::sole();

        $this->assertSame(WorkbookSeeder::PROJECT_NAME, $project->name);
        $this->assertSame('2026-04-05', $project->planned_start_date->toDateString());
        $this->assertNull($project->actual_start_date);
    }

    /**
     * Where the name disagrees with the formula, the formula wins. Nothing here is special-cased
     * in the import; these offsets are what the formulas say.
     */
    public function test_offsets_follow_the_formula_not_the_name(): void
    {
        $offsets = fn (string $scope) => $this->activities($scope)
            ->whereIn('description', ['MASONRY 30', 'ALL FINISHING WORK 90'])
            ->pluck('offset_days', 'description')->map(fn ($days) => (int) $days)->all();

        $this->assertSame(['MASONRY 30' => 0, 'ALL FINISHING WORK 90' => 60], $offsets('Tower A'));
        $this->assertSame(['MASONRY 30' => 0, 'ALL FINISHING WORK 90' => 60], $offsets('Tower B'));
        $this->assertSame(['MASONRY 30' => 30, 'ALL FINISHING WORK 90' => 90], $offsets('Overall Timeline'));
    }

    /**
     * Every hidden nudge in the three sheets. Tower B's Holi +7 is in the workbook but not listed in
     * docs/database-structure.md.
     */
    public function test_every_date_adjustment_is_carried_with_its_reason(): void
    {
        $adjusted = DB::table('activities')
            ->join('buildings', 'buildings.id', '=', 'activities.building_id')
            ->where('date_adjustment_days', '!=', 0)
            ->orderBy('buildings.sort_order')->orderBy('activities.sort_order')
            ->get(['buildings.name as scope', 'description', 'date_adjustment_days', 'adjustment_reason'])
            ->map(fn ($row) => [$row->scope, $row->description, (int) $row->date_adjustment_days, $row->adjustment_reason])
            ->all();

        $reason = ScheduleWorkbook::ADJUSTMENT_REASON;
        $this->assertSame([
            ['Tower A', 'DIWALI (08-11-2026) BREAK 20', 17, $reason],
            ['Tower B', 'DIWALI (08-11-2026) BREAK 20', 12, $reason],
            ['Tower B', 'HOLI (22-03-2027) BREAK 30', 7, $reason],
            ['Overall Timeline', 'FIRE RELATED WORK 30', 7, $reason],
            ['Overall Timeline', 'FIRE NOC AND LIFT NOC 45', 7, $reason],
        ], $adjusted);
        $this->assertSame(0, DB::table('activities')->where('date_adjustment_days', 0)->whereNotNull('adjustment_reason')->count());
    }

    public function test_ground_level_plaster_branches_back_to_internal_and_external_plaster(): void
    {
        foreach (['Tower A', 'Tower B'] as $scope) {
            $activities = $this->activities($scope)->keyBy('id');
            $groundLevel = $activities->firstWhere('description', 'GROUND LEVEL PLASTER 20');

            $this->assertSame('INT. & EXT. PLASTER 75', $activities[$groundLevel->predecessor_id]->description, $scope);
            $this->assertSame(31, (int) $groundLevel->sort_order);
            $this->assertSame(25, (int) $activities[$groundLevel->predecessor_id]->sort_order);
        }
    }

    public function test_each_scope_has_a_single_chain_root(): void
    {
        foreach (array_keys(ScheduleWorkbook::SOURCES) as $scope) {
            $roots = $this->activities($scope)->whereNull('predecessor_id');

            $this->assertCount(1, $roots, $scope);
            $this->assertSame(4, (int) $roots->first()->sort_order, $scope);
        }
    }

    public function test_breaks_are_flagged(): void
    {
        $breaks = fn (string $scope) => $this->activities($scope)->where('is_break', 1)->pluck('description')->values()->all();
        $towerBreaks = ['DIWALI (08-11-2026) BREAK 20', 'HOLI (22-03-2027) BREAK 30', 'OTHER FESTIVAL BREAKS 13', 'HOLI (11-03-2028) BREAK 30'];

        $this->assertSame($towerBreaks, $breaks('Tower A'));
        $this->assertSame($towerBreaks, $breaks('Tower B'));
        $this->assertSame([], $breaks('Overall Timeline'));
    }

    public function test_actual_dates_are_imported(): void
    {
        $actuals = fn (string $scope) => $this->activities($scope)->whereNotNull('actual_date')
            ->pluck('actual_date', 'description')->all();

        $this->assertSame(['DAY 1 TO GF SLAB - 180 DAYS' => '2026-09-20'], $actuals('Tower A'));
        $this->assertSame([
            'DAY 1 TO GF SLAB - 150 DAYS' => '2026-07-28',
            '1st SLAB' => '2026-09-19',
            '2nd SLAB 20' => '2026-10-05',
        ], $actuals('Tower B'));
        $this->assertSame(['DAY 1 TO GF SLAB - 180 DAYS' => '2026-09-20'], $actuals('Overall Timeline'));
    }

    public function test_importing_twice_is_refused_and_changes_nothing(): void
    {
        $before = DB::table('activities')->count();

        try {
            $this->seed(WorkbookSeeder::class);
            $this->fail('Expected the second import to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already been imported', $exception->getMessage());
        }

        $this->assertSame($before, DB::table('activities')->count());
        $this->assertSame(1, Project::count());
    }

    public function test_chain_formulas_parse_into_predecessor_and_offset(): void
    {
        $this->assertSame(['predecessor_row' => null, 'offset_days' => 180], ScheduleWorkbook::parseChain(180, 'C4'));
        $this->assertSame(['predecessor_row' => 4, 'offset_days' => 20], ScheduleWorkbook::parseChain('=C4+20', 'C5'));
        $this->assertSame(['predecessor_row' => 25, 'offset_days' => 40], ScheduleWorkbook::parseChain('=C25+20+20', 'C31'));
        $this->assertSame(['predecessor_row' => 23, 'offset_days' => 0], ScheduleWorkbook::parseChain('=C23+30-30', 'C24'));
        $this->assertSame(['predecessor_row' => 25, 'offset_days' => 0], ScheduleWorkbook::parseChain('=C25', 'C26'));
    }

    public function test_date_formulas_parse_into_adjustments(): void
    {
        $this->assertSame(0, ScheduleWorkbook::parseAdjustment('=E$2+C5', 5, 'D5'));
        $this->assertSame(17, ScheduleWorkbook::parseAdjustment('=E$2+C6+17', 6, 'D6'));
    }

    #[DataProvider('unrecognisedFormulas')]
    public function test_unrecognised_formulas_are_rejected_not_guessed(string $method, array $arguments): void
    {
        $this->expectException(UnexpectedValueException::class);

        ScheduleWorkbook::$method(...$arguments);
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function unrecognisedFormulas(): array
    {
        return [
            'function call' => ['parseChain', ['=MAX(C4,C5)+10', 'C6']],
            'other column' => ['parseChain', ['=D4+10', 'C5']],
            'negative net offset' => ['parseChain', ['=C4+10-20', 'C5']],
            'empty cell' => ['parseChain', [null, 'C5']],
            'date from another row' => ['parseAdjustment', ['=E$2+C5', 6, 'D6']],
            'date without start' => ['parseAdjustment', ['=C6+17', 6, 'D6']],
        ];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function activities(string $scope)
    {
        return DB::table('activities')
            ->join('buildings', 'buildings.id', '=', 'activities.building_id')
            ->where('buildings.name', $scope)
            ->orderBy('activities.sort_order')
            ->get(['activities.*']);
    }
}
