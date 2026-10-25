<?php

namespace Tests\Feature;

use App\Import\ImportUploads;
use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\BuildingRevision;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildingsTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private Project $sv;

    private Project $heights;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(WorkbookSeeder::class);
        $this->travelTo('2026-10-15 12:00');
        $this->editor = User::factory()->editor()->create();
        $this->sv = Project::sole();
        $this->heights = Project::create(['name' => 'Shaligram Heights', 'planned_start_date' => '2027-01-01']);
    }

    /* ---------------- adding ---------------- */

    public function test_an_empty_building_takes_the_projects_start_and_goes_last(): void
    {
        $this->actingAs($this->editor)->post("/projects/{$this->sv->id}/buildings", ['name' => 'Tower C', 'type' => 'building', 'plan' => 'empty'])
            ->assertSessionHasNoErrors();

        $building = Building::where('name', 'Tower C')->sole();
        $this->assertSame(['2026-04-05', 4, 0], [$building->planned_start_date->toDateString(), $building->sort_order, $building->activities()->count()]);
        $this->assertSame([['created', null, 'Tower C', null, $this->editor->id]], $this->revisions($building));
    }

    /**
     * Copying brings the chain, offsets, adjustments and breaks; never actual dates or notes. The
     * dates follow the new building's own start: every planned day is the source's, every date is
     * the new start plus that day plus the adjustment.
     */
    public function test_a_copied_plan_follows_the_new_buildings_start(): void
    {
        $source = Building::where('name', 'Tower A')->sole();
        Activity::where('building_id', $source->id)->where('description', '1st SLAB')->update(['notes' => 'Site note']);

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", [
            'name' => 'Wing 1', 'type' => 'building', 'planned_start_date' => '2027-03-01', 'plan' => 'copy', 'copy_from' => $source->id,
        ])->assertSessionHasNoErrors();

        $copy = Building::where('name', 'Wing 1')->sole();
        $original = $this->plan($source);
        $copied = $this->plan($copy);

        $this->assertCount(52, $copied);
        foreach ($original as $description => $row) {
            $this->assertSame($row['predecessor'], $copied[$description]['predecessor'], $description);
            $this->assertSame($row['planned_day'], $copied[$description]['planned_day'], $description);
            $this->assertSame(
                CarbonImmutable::parse('2027-03-01')->addDays($row['planned_day'] + $row['date_adjustment_days'])->toDateString(),
                $copied[$description]['planned_date'],
                $description,
            );
            $this->assertSame([$row['offset_days'], $row['date_adjustment_days'], $row['adjustment_reason'], $row['is_break'], $row['sort_order']],
                [$copied[$description]['offset_days'], $copied[$description]['date_adjustment_days'], $copied[$description]['adjustment_reason'], $copied[$description]['is_break'], $copied[$description]['sort_order']]);
        }
        $this->assertSame(0, Activity::where('building_id', $copy->id)->whereNotNull('actual_date')->count());
        $this->assertSame(0, Activity::where('building_id', $copy->id)->whereNotNull('notes')->count());
        $this->assertSame('2029-07-01', Activity::where('building_id', $copy->id)->max('planned_date'));

        // the source is untouched
        $this->assertSame($original, $this->plan($source->fresh()));

        // every copied activity names where it came from
        $origin = 'copied from Shaligram Vrundavan › Tower A';
        $this->assertSame(52, ActivityRevision::where('field', 'created')->where('new_value', 'like', "%({$origin})")->count());
        $this->assertSame([['created', null, 'Wing 1', $origin, $this->editor->id]], $this->revisions($copy));
    }

    public function test_copy_needs_a_building_that_still_exists(): void
    {
        $gone = Building::where('name', 'Tower B')->sole();
        $gone->delete();

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", ['name' => 'Wing 1', 'type' => 'building', 'plan' => 'copy'])
            ->assertSessionHasErrors(['copy_from' => 'Choose the building to copy the plan from.']);
        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", ['name' => 'Wing 1', 'type' => 'building', 'plan' => 'copy', 'copy_from' => $gone->id])
            ->assertSessionHasErrors(['copy_from' => 'That building no longer exists. Choose another.']);

        $this->assertSame(0, $this->heights->buildings()->count());
    }

    /**
     * The client's own workbook, uploaded: the sheet list comes back, the chosen sheet is parsed
     * from its formulas, and every computed day — and, starting on the workbook's own date, every
     * date — matches Excel's cached value.
     */
    public function test_importing_from_excel_on_the_workbooks_start_reproduces_it_exactly(): void
    {
        $upload = $this->upload('SV_B3.xlsx');
        $this->assertContains('Tower B', $upload['sheets']);
        $this->assertSame('SV_B3.xlsx', $upload['name']);

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", [
            'name' => 'Tower B copy', 'type' => 'building', 'planned_start_date' => '2026-04-05', 'plan' => 'import', 'import_token' => $upload['token'], 'sheet' => 'Tower B',
        ])->assertSessionHasNoErrors();

        $building = Building::where('name', 'Tower B copy')->sole();
        $original = Building::where('name', 'Tower B')->sole();
        $this->assertSame(
            array_map(fn ($row) => array_diff_key($row, ['actual_date' => 1]), $this->plan($original)),
            array_map(fn ($row) => array_diff_key($row, ['actual_date' => 1]), $this->plan($building)),
        );
        $this->assertSame(0, Activity::where('building_id', $building->id)->whereNotNull('actual_date')->count());
        $this->assertSame(52, ActivityRevision::where('field', 'created')->where('new_value', 'like', '%(imported from Excel › Tower B)')->count());
        $this->assertNull(app(ImportUploads::class)->path($upload['token']), 'The upload is removed once imported.');
    }

    public function test_importing_on_another_start_moves_the_dates_and_keeps_the_days(): void
    {
        $upload = $this->upload('SV_Overall3.xlsx');

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", [
            'name' => 'Site works', 'type' => 'common_work', 'plan' => 'import', 'import_token' => $upload['token'], 'sheet' => 'Overall Timeline',
        ])->assertSessionHasNoErrors();

        $building = Building::where('name', 'Site works')->sole();
        $this->assertSame('2027-01-01', $building->planned_start_date->toDateString());
        $this->assertSame([47, 825], [$building->activities()->count(), (int) $building->activities()->max('planned_day')]);
        $this->assertSame('2029-04-05', $building->activities()->max('planned_date'));
    }

    public function test_a_sheet_that_is_not_a_plan_is_refused_with_the_cell_named_and_nothing_written(): void
    {
        $upload = $this->upload('SV_A3.xlsx');
        $before = Building::withTrashed()->count();

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", [
            'name' => 'Wing 1', 'type' => 'building', 'plan' => 'import', 'import_token' => $upload['token'], 'sheet' => 'TA Chart',
        ])->assertSessionHasErrors('sheet');

        $this->assertSame('Sheet [TA Chart] cannot be imported. Unrecognised date formula in D4: an empty cell', session('errors')->first('sheet'));

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", [
            'name' => 'Wing 1', 'type' => 'building', 'plan' => 'import', 'import_token' => $upload['token'], 'sheet' => 'No such sheet',
        ])->assertSessionHasErrors(['sheet' => 'Sheet [No such sheet] cannot be imported. The workbook has no sheet named [No such sheet].']);
        $this->assertSame($before, Building::withTrashed()->count());
    }

    public function test_an_upload_that_is_not_an_excel_workbook_is_refused(): void
    {
        $fake = UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf');

        $this->actingAs($this->editor)->postJson('/imports', ['file' => $fake])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Choose an Excel workbook (.xlsx).');
    }

    public function test_an_expired_or_invented_upload_token_is_refused(): void
    {
        foreach (['not-a-token', '9f3b6a52-1c4d-4f8e-9a6b-000000000000'] as $token) {
            $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", [
                'name' => 'Wing 1', 'type' => 'building', 'plan' => 'import', 'import_token' => $token, 'sheet' => 'Tower A',
            ])->assertSessionHasErrors(['import_token' => 'The uploaded file has expired. Choose it again.']);
        }
    }

    /* ---------------- names, editing, order, deleting ---------------- */

    public function test_building_names_are_unique_within_a_project_only(): void
    {
        $this->actingAs($this->editor)->post("/projects/{$this->sv->id}/buildings", ['name' => 'Tower A', 'type' => 'building', 'plan' => 'empty'])
            ->assertSessionHasErrors(['name' => 'This project already has a building with that name.']);

        $this->actingAs($this->editor)->post("/projects/{$this->heights->id}/buildings", ['name' => 'Tower A', 'type' => 'building', 'plan' => 'empty'])
            ->assertSessionHasNoErrors();
    }

    public function test_editing_a_building_changes_name_and_type_only_and_is_audited(): void
    {
        $towerA = Building::where('name', 'Tower A')->sole();

        $this->actingAs($this->editor)->patch("/projects/{$this->sv->id}/buildings/{$towerA->id}", [
            'name' => 'Tower A (North)', 'type' => 'common_work', 'planned_start_date' => '2030-01-01',
        ])->assertSessionHasNoErrors();

        $towerA->refresh();
        $this->assertSame(['Tower A (North)', 'common_work', '2026-04-05'], [$towerA->name, $towerA->type->value, $towerA->planned_start_date->toDateString()]);
        $this->assertSame([
            ['name', 'Tower A', 'Tower A (North)', null, $this->editor->id],
            ['type', 'building', 'common_work', null, $this->editor->id],
        ], $this->revisions($towerA));
    }

    public function test_moving_a_building_swaps_it_with_its_neighbour(): void
    {
        $overall = Building::where('name', 'Overall Timeline')->sole();

        $this->actingAs($this->editor)->patch("/projects/{$this->sv->id}/buildings/{$overall->id}/move", ['direction' => 'up'])->assertSessionHasNoErrors();
        $this->assertSame(['Tower A', 'Overall Timeline', 'Tower B'], $this->sv->buildings()->orderBy('sort_order')->pluck('name')->all());

        // the top one cannot go higher
        $towerA = Building::where('name', 'Tower A')->sole();
        $this->actingAs($this->editor)->patch("/projects/{$this->sv->id}/buildings/{$towerA->id}/move", ['direction' => 'up']);
        $this->assertSame(['Tower A', 'Overall Timeline', 'Tower B'], $this->sv->buildings()->orderBy('sort_order')->pluck('name')->all());

        $this->assertSame(2, BuildingRevision::where('field', 'sort_order')->count());
        $this->actingAs($this->editor)->patch("/projects/{$this->sv->id}/buildings/{$towerA->id}/move", ['direction' => 'sideways'])->assertSessionHasErrors('direction');
    }

    /** A soft delete: the timeline leaves the app, its activities and every revision stay. */
    public function test_deleting_a_building_keeps_its_activities_and_history(): void
    {
        $towerB = Building::where('name', 'Tower B')->sole();
        $actuals = Activity::where('building_id', $towerB->id)->whereNotNull('actual_date')->count();

        $this->actingAs($this->editor)->delete("/projects/{$this->sv->id}/buildings/{$towerB->id}")
            ->assertRedirect("/projects/{$this->sv->id}");

        $this->assertSoftDeleted($towerB);
        $this->assertSame([52, 3], [Activity::where('building_id', $towerB->id)->count(), $actuals]);
        $this->assertSame([['deleted', 'Tower B', null, null, $this->editor->id]], $this->revisions($towerB));
        $this->assertSame(['Tower A', 'Overall Timeline'], $this->sv->buildings()->orderBy('sort_order')->pluck('name')->all());

        // its name is free again
        $this->actingAs($this->editor)->post("/projects/{$this->sv->id}/buildings", ['name' => 'Tower B', 'type' => 'building', 'plan' => 'empty'])
            ->assertSessionHasNoErrors();
    }

    /* ---------------- helpers ---------------- */

    /**
     * @return array{token: string, name: string, sheets: list<string>}
     */
    private function upload(string $workbook): array
    {
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        copy(base_path("docs/workbooks/{$workbook}"), $path);

        return $this->actingAs($this->editor)
            ->post('/imports', ['file' => new UploadedFile($path, $workbook, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json();
    }

    /**
     * Each activity by description, with its predecessor by description, so two buildings can be
     * compared row for row.
     *
     * @return array<string, array<string, mixed>>
     */
    private function plan(Building $building): array
    {
        $rows = DB::table('activities')->where('building_id', $building->id)->orderBy('sort_order')->get()->keyBy('id');

        return $rows->mapWithKeys(fn ($row) => [$row->description => [
            'predecessor' => $row->predecessor_id ? $rows[$row->predecessor_id]->description : null,
            'offset_days' => (int) $row->offset_days,
            'date_adjustment_days' => (int) $row->date_adjustment_days,
            'adjustment_reason' => $row->adjustment_reason,
            'is_break' => (bool) $row->is_break,
            'sort_order' => (int) $row->sort_order,
            'planned_day' => (int) $row->planned_day,
            'planned_date' => $row->planned_date,
            'actual_date' => $row->actual_date,
        ]])->all();
    }

    /**
     * @return list<array{string, ?string, ?string, ?string, int}>
     */
    private function revisions(Building $building): array
    {
        return BuildingRevision::where('building_id', $building->id)->orderBy('id')->get()
            ->map(fn ($r) => [$r->field, $r->old_value, $r->new_value, $r->note, $r->user_id])->all();
    }
}
