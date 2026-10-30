<?php

namespace Tests\Feature;

use App\Import\PlanDocumentValidator;
use App\Models\Activity;
use App\Models\Building;
use App\Models\PlanImport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `timeline:import-json` on tests/Fixtures/plan-import.json, a hand-made project. Its figures, by hand:
 *
 * Tower 1 starts 1 Jan 2026, no separate actual start. Work-days and planned dates:
 *   Excavation 10 (11 Jan, done 10 Jan) · GF SLAB 30 (31 Jan, done 29 Jan) · GF walls 40 (10 Feb, done 12 Feb)
 *   1st SLAB 45 (15 Feb) · DIWALI BREAK 55, +3 adjustment (25 Feb + 3 = 28 Feb) · 1F walls 65 (7 Mar)
 *   2nd SLAB 5 60 (2 Mar) · Handover 70 (12 Mar)
 * The adjustment moves only Diwali's own date: 1F walls and 2nd SLAB count from work-day 55.
 *
 *   total 70 · done: furthest work-day 40 · progress 40/70 · latest actual 12 Feb (GF walls, planned 10 Feb):
 *   lead −2 · planned finish 12 Mar · hold lead 12 Mar + 2 = 14 Mar
 *   average pace 40 / (12 Feb − 1 Jan = 42) → 30 left take round(31.5) = 32 days → 16 Mar
 *   recent pace: previous actual 29 Jan, 30 done by then → (40 − 30) / 14 → 30 left take 42 days → 26 Mar
 *
 * Site works (common work): one 30-day activity, done on its planned day, 31 Jan: everything 31 Jan, lead 0.
 */
class ImportJsonTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-01 12:00');
        $this->file = base_path('tests/Fixtures/plan-import.json');
        $this->owner()->forceFill(['email' => 'client@example.com'])->save();
    }

    public function test_a_real_import_creates_the_project_and_logs_it(): void
    {
        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'client@example.com'])
            ->expectsOutputToContain('Planned dates equal to the file (Excel): 9 of 9')
            ->expectsOutputToContain('Figures equal to `expected` (%, lead, finish, forecasts): 18 of 18')
            ->expectsOutputToContain('logged in plan_imports')
            ->assertSuccessful();

        $project = Project::sole();
        $this->assertSame(['Hand-made Heights', $this->owner()->id, '2026-01-01', 'Asia/Kolkata'],
            [$project->name, $project->owner_id, $project->planned_start_date->toDateString(), $project->timezone]);

        $tower = Building::where('name', 'Tower 1')->sole();
        $rows = Activity::where('building_id', $tower->id)->with('predecessor')->orderBy('sort_order')->get();
        $this->assertSame([
            ['Excavation', null, 10, '2026-01-11', '2026-01-10'],
            ['GF SLAB', 'Excavation', 30, '2026-01-31', '2026-01-29'],
            ['GF walls', 'GF SLAB', 40, '2026-02-10', '2026-02-12'],
            ['1st SLAB', 'GF SLAB', 45, '2026-02-15', null],
            ['DIWALI BREAK 10', '1st SLAB', 55, '2026-02-28', null],
            ['1F walls', 'DIWALI BREAK 10', 65, '2026-03-07', null],
            ['2nd SLAB 5', 'DIWALI BREAK 10', 60, '2026-03-02', null],
            ['Handover', '1F walls', 70, '2026-03-12', null],
        ], $rows->map(fn (Activity $a) => [
            $a->description, $a->predecessor?->description, $a->planned_day, $a->planned_date->toDateString(), $a->actual_date?->toDateString(),
        ])->all());

        $diwali = $rows->firstWhere('description', 'DIWALI BREAK 10');
        $this->assertSame([true, 3], [$diwali->is_break, $diwali->date_adjustment_days]);
        $this->assertSame('Poured in two halves', $rows->firstWhere('description', 'GF SLAB')->notes);
        $this->assertSame('common_work', Building::where('name', 'Site works')->sole()->type->value);

        $log = PlanImport::sole();
        $this->assertSame([$this->owner()->id, $project->id, 'Hand-made Heights', 'plan-import.json', hash_file('sha256', $this->file)],
            [$log->owner_id, $log->project_id, $log->project_name, $log->file, $log->sha256]);
        $this->assertSame([9, 18], [$log->counts['dates_checked'], $log->counts['figures_checked']]);

        // the owner sees it; nobody else does
        $this->actingAs($this->owner())->get("/projects/{$project->id}")->assertOk();
        $this->actingAs(User::factory()->create())->get("/projects/{$project->id}")->assertNotFound();
    }

    public function test_a_dry_run_reports_the_same_and_writes_nothing(): void
    {
        $before = $this->counts();

        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'client@example.com', '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN: nothing will be written')
            ->expectsOutputToContain('Planned dates equal to the file (Excel): 9 of 9')
            ->expectsOutputToContain('Figures equal to `expected` (%, lead, finish, forecasts): 18 of 18')
            ->expectsOutputToContain('DRY RUN rolled back: nothing written.')
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }

    public function test_an_existing_project_name_is_refused_with_a_way_out(): void
    {
        Project::create(['owner_id' => $this->owner()->id, 'name' => 'Hand-made Heights', 'planned_start_date' => '2026-01-01']);

        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'client@example.com'])
            ->expectsOutputToContain('already has a project named [Hand-made Heights]. Import it under another name with --as="Hand-made Heights (2)"')
            ->assertFailed();

        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'client@example.com', '--as' => 'Hand-made Heights (2)'])
            ->assertSuccessful();
        $this->assertSame(['Hand-made Heights', 'Hand-made Heights (2)'], Project::orderBy('id')->pluck('name')->all());
    }

    public function test_the_same_file_cannot_be_imported_twice_for_one_owner(): void
    {
        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'client@example.com'])->assertSuccessful();
        $before = $this->counts();

        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'client@example.com', '--as' => 'Again'])
            ->expectsOutputToContain('This file was already imported for [client@example.com]')
            ->assertFailed();

        $this->assertSame($before, $this->counts());
    }

    public function test_an_unknown_owner_or_file_is_refused(): void
    {
        $this->artisan('timeline:import-json', ['file' => $this->file, '--owner' => 'nobody@example.com'])
            ->expectsOutputToContain('No account has the email [nobody@example.com]')->assertFailed();
        $this->artisan('timeline:import-json', ['file' => $this->file])
            ->expectsOutputToContain('Name the owner')->assertFailed();
        $this->artisan('timeline:import-json', ['file' => '/no/such/file.json', '--owner' => 'client@example.com'])
            ->expectsOutputToContain('Cannot read')->assertFailed();
        $this->assertSame(0, Project::count());
    }

    /** A planned date that is not what the chain gives is a mismatch, reported with its row. */
    public function test_a_planned_date_that_differs_from_the_plan_stops_the_import(): void
    {
        $document = $this->document();
        $document['buildings'][0]['activities'][3]['planned_date'] = '2026-02-16';

        $this->assertSame(['Tower 1 row 7 [1st SLAB]: the plan gives 2026-02-15, the JSON (Excel) says 2026-02-16.'],
            app(PlanDocumentValidator::class)->validate($document));
        $this->assertImportFails($document, 'the plan gives 2026-02-15');
    }

    /** An expected figure that the import does not reproduce rolls everything back. */
    public function test_an_expected_figure_that_differs_stops_the_import_and_writes_nothing(): void
    {
        $document = $this->document();
        $document['expected']['Tower 1']['currentLead'] = -3;

        $this->assertImportFails($document, 'Tower 1 currentLead: imported -2, expected -3');
    }

    public function test_every_validation_rule_names_where_the_problem_is(): void
    {
        $check = function (callable $change) {
            $document = $this->document();
            $change($document);

            return app(PlanDocumentValidator::class)->validate($document);
        };

        $this->assertContains('Tower 1 row 6 [GF walls]: comes after row 99, which is not in this building.',
            $check(function (&$d) {
                $d['buildings'][0]['activities'][2]['comes_after_row'] = 99;
            }));
        $this->assertContains('Tower 1 row 8 [DIWALI BREAK 10]: a break has no actual date.',
            $check(function (&$d) {
                $d['buildings'][0]['activities'][4]['actual_date'] = '2026-02-20';
            }));
        $this->assertContains('Tower 1 row 4 [Excavation]: the actual date 2025-12-31 is before the building\'s start (2026-01-01).',
            $check(function (&$d) {
                $d['buildings'][0]['activities'][0]['actual_date'] = '2025-12-31';
            }));
        $this->assertContains('Tower 1 row 6 [GF walls]: comes after itself.',
            $check(function (&$d) {
                $d['buildings'][0]['activities'][2]['comes_after_row'] = 6;
            }));
        $this->assertContains('Site works: no expected figures in `expected`.',
            $check(function (&$d) {
                unset($d['expected']['Site works']);
            }));
        $this->assertContains('building 0, activity 1, planned_date: The buildings.0.activities.1.planned_date field must match the format Y-m-d.',
            $check(function (&$d) {
                $d['buildings'][0]['activities'][1]['planned_date'] = '31/01/2026';
            }));
        $this->assertContains('project.name: The project.name field is required.',
            $check(function (&$d) {
                unset($d['project']['name']);
            }));
    }

    /** Excavation after Handover makes a loop through the whole chain; it is named, row by row. */
    public function test_a_loop_is_refused_with_its_rows(): void
    {
        $document = $this->document();
        $document['buildings'][0]['activities'][0]['comes_after_row'] = 11;

        $problems = app(PlanDocumentValidator::class)->validate($document);

        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('Tower 1: Predecessor cycle: row 4 [Excavation] → row 5 [GF SLAB]', $problems[0]);
        $this->assertStringContainsString('row 11 [Handover] → row 4 [Excavation]', $problems[0]);
        $this->assertImportFails($document, 'Predecessor cycle');
    }

    private function assertImportFails(array $document, string $message): void
    {
        $file = tempnam(sys_get_temp_dir(), 'plan').'.json';
        file_put_contents($file, json_encode($document));
        $before = $this->counts();

        $this->artisan('timeline:import-json', ['file' => $file, '--owner' => 'client@example.com'])
            ->expectsOutputToContain($message)
            ->assertFailed();

        $this->assertSame($before, $this->counts());
        unlink($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return json_decode(file_get_contents($this->file), true);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['projects', 'buildings', 'activities', 'activity_revisions', 'building_revisions', 'project_revisions', 'plan_imports'];

        return array_combine($tables, array_map(fn (string $t) => DB::table($t)->count(), $tables));
    }
}
