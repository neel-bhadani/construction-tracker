<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\BuildingRevision;
use App\Models\Floor;
use App\Models\Project;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adding, renaming and deleting floors, putting activities on them, and copying them with a plan.
 */
class FloorsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Building $building;

    private int $slab;

    private int $holi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-03-01 12:00');
        $this->project = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Hand-made', 'planned_start_date' => '2026-01-01']);
        $this->building = Building::create(['project_id' => $this->project->id, 'name' => 'Tower', 'sort_order' => 1]);
        $this->slab = DB::table('activities')->insertGetId(['building_id' => $this->building->id, 'description' => 'Slab', 'offset_days' => 10, 'planned_date' => '2026-01-01', 'sort_order' => 1]);
        $this->holi = DB::table('activities')->insertGetId(['building_id' => $this->building->id, 'description' => 'Holi', 'predecessor_id' => $this->slab, 'offset_days' => 5, 'is_break' => true, 'planned_date' => '2026-01-01', 'sort_order' => 2]);
        app(ScheduleRecalculator::class)->recalculate($this->building);
    }

    public function test_floor_names_for_levels(): void
    {
        $this->assertSame(
            ['Basement 2', 'Basement 1', 'Ground floor', '1st floor', '2nd floor', '3rd floor', '4th floor', '11th floor', '12th floor', '13th floor', '21st floor', '22nd floor', '111th floor'],
            array_map(Floor::nameForLevel(...), [-2, -1, 0, 1, 2, 3, 4, 11, 12, 13, 21, 22, 111]),
        );
    }

    public function test_adding_a_floor_is_audited(): void
    {
        $this->floors('post', '', ['name' => 'Ground floor', 'level' => 0])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Added [Ground floor].');

        $this->assertSame([['Ground floor', 0]], $this->floorList());
        $this->assertSame([['floor_added', null, 'Ground floor']], $this->buildingRevisions());
    }

    public function test_ground_plus_1_to_13_adds_fourteen_floors_once(): void
    {
        $this->floors('post', '/range', ['up_to' => 13])->assertSessionHasNoErrors()->assertSessionHas('success', 'Added 14 floors.');

        $this->assertSame([
            ['Ground floor', 0], ['1st floor', 1], ['2nd floor', 2], ['3rd floor', 3], ['4th floor', 4], ['5th floor', 5], ['6th floor', 6],
            ['7th floor', 7], ['8th floor', 8], ['9th floor', 9], ['10th floor', 10], ['11th floor', 11], ['12th floor', 12], ['13th floor', 13],
        ], $this->floorList());

        // again: every one is there already
        $this->floors('post', '/range', ['up_to' => 13])->assertSessionHas('success', 'Every one of those floors is already there. Nothing was added.');
        $this->assertSame(14, Floor::count());
    }

    public function test_the_range_keeps_existing_floors_and_can_add_basements(): void
    {
        Floor::create(['building_id' => $this->building->id, 'name' => '1ST FLOOR', 'level' => 1]);

        $this->floors('post', '/range', ['up_to' => 2, 'basements' => 1])->assertSessionHasNoErrors()->assertSessionHas('success', 'Added 3 floors.');

        $this->assertSame([['Basement 1', -1], ['Ground floor', 0], ['1ST FLOOR', 1], ['2nd floor', 2]], $this->floorList());
    }

    public function test_floor_names_are_unique_in_a_building_only(): void
    {
        Floor::create(['building_id' => $this->building->id, 'name' => 'Ground floor', 'level' => 0]);
        $other = Building::create(['project_id' => $this->project->id, 'name' => 'Tower 2', 'sort_order' => 2]);

        $this->floors('post', '', ['name' => 'ground floor', 'level' => 0])
            ->assertSessionHasErrors(['name' => 'This building already has a floor with that name.']);
        $this->actingAs($this->owner())->post("/projects/{$this->project->id}/buildings/{$other->id}/floors", ['name' => 'Ground floor', 'level' => 0])
            ->assertSessionHasNoErrors();
        $this->floors('post', '', ['name' => '', 'level' => 'top'])->assertSessionHasErrors(['name' => 'Give the floor a name.', 'level']);
    }

    public function test_renaming_a_floor(): void
    {
        $floor = Floor::create(['building_id' => $this->building->id, 'name' => 'GF', 'level' => 0]);

        $this->floors('patch', "/{$floor->id}", ['name' => 'Ground floor', 'level' => 0])->assertSessionHasNoErrors();

        $this->assertSame('Ground floor', $floor->fresh()->name);
        $this->assertSame([['floor_renamed', 'GF', 'Ground floor']], $this->buildingRevisions());
    }

    public function test_a_floor_of_another_building_is_not_found_under_this_one(): void
    {
        $other = Building::create(['project_id' => $this->project->id, 'name' => 'Tower 2', 'sort_order' => 2]);
        $floor = Floor::create(['building_id' => $other->id, 'name' => 'Ground floor', 'level' => 0]);

        $this->floors('patch', "/{$floor->id}", ['name' => 'X', 'level' => 0])->assertNotFound();
        $this->floors('delete', "/{$floor->id}")->assertNotFound();
        $this->assertNotSoftDeleted($floor);
    }

    public function test_deleting_a_floor_moves_its_activities_to_the_whole_building_and_frees_its_name(): void
    {
        $floor = Floor::create(['building_id' => $this->building->id, 'name' => 'Ground floor', 'level' => 0]);
        DB::table('activities')->where('id', $this->slab)->update(['floor_id' => $floor->id]);
        $dates = DB::table('activities')->pluck('planned_date', 'id')->all();

        $this->floors('delete', "/{$floor->id}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Deleted [Ground floor]. Its 1 activity is now Whole building.');

        $this->assertSoftDeleted($floor);
        $this->assertNull(Activity::find($this->slab)->floor_id);
        $this->assertSame($dates, DB::table('activities')->pluck('planned_date', 'id')->all());
        $this->assertSame([['floor_id', (string) $floor->id, null]],
            ActivityRevision::where('activity_id', $this->slab)->get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all());
        $this->assertSame('1 activity moved to Whole building', BuildingRevision::where('field', 'floor_deleted')->sole()->note);

        // the name is free again
        $this->floors('post', '', ['name' => 'Ground floor', 'level' => 0])->assertSessionHasNoErrors();
    }

    public function test_the_dialog_puts_an_activity_on_a_floor(): void
    {
        $floor = Floor::create(['building_id' => $this->building->id, 'name' => 'Ground floor', 'level' => 0]);

        $this->actingAs($this->owner())->post("/buildings/{$this->building->id}/activities", [
            'description' => 'GF walls', 'predecessor_id' => $this->slab, 'offset_days' => 10, 'is_break' => false, 'floor_id' => $floor->id,
        ])->assertSessionHasNoErrors();
        $walls = Activity::where('description', 'GF walls')->sole();
        $this->assertSame($floor->id, $walls->floor_id);
        $this->assertContains(['floor_id', null, (string) $floor->id], ActivityRevision::where('activity_id', $walls->id)->get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all());

        $this->actingAs($this->owner())->patch("/activities/{$walls->id}", [
            'description' => 'GF walls', 'predecessor_id' => $this->slab, 'offset_days' => 10, 'is_break' => false, 'floor_id' => null,
        ])->assertSessionHasNoErrors();
        $this->assertNull($walls->fresh()->floor_id);
    }

    public function test_an_activity_can_only_be_on_a_live_floor_of_its_own_building_and_a_break_on_none(): void
    {
        $other = Building::create(['project_id' => $this->project->id, 'name' => 'Tower 2', 'sort_order' => 2]);
        $elsewhere = Floor::create(['building_id' => $other->id, 'name' => 'Ground floor', 'level' => 0]);
        $gone = Floor::create(['building_id' => $this->building->id, 'name' => '1st floor', 'level' => 1]);
        $gone->delete();
        $floor = Floor::create(['building_id' => $this->building->id, 'name' => 'Ground floor', 'level' => 0]);

        foreach ([$elsewhere->id, $gone->id] as $floorId) {
            $this->actingAs($this->owner())->patch("/activities/{$this->slab}/floor", ['floor_id' => $floorId])
                ->assertSessionHasErrors(['floor_id' => 'Choose a floor of this building.']);
        }
        $this->actingAs($this->owner())->patch("/activities/{$this->holi}/floor", ['floor_id' => $floor->id])
            ->assertSessionHasErrors(['floor_id' => 'A break is on no floor.']);
        $this->actingAs($this->owner())->post("/buildings/{$this->building->id}/activities", [
            'description' => 'Diwali', 'predecessor_id' => $this->slab, 'offset_days' => 10, 'is_break' => true, 'floor_id' => $floor->id,
        ])->assertSessionHasErrors(['floor_id' => 'A break is on no floor.']);

        $this->assertNull(Activity::find($this->slab)->floor_id);
    }

    public function test_move_to_floor_and_back_to_the_whole_building(): void
    {
        $floor = Floor::create(['building_id' => $this->building->id, 'name' => 'Ground floor', 'level' => 0]);
        $dates = DB::table('activities')->pluck('planned_date', 'id')->all();

        $this->actingAs($this->owner())->patch("/activities/{$this->slab}/floor", ['floor_id' => $floor->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Moved [Slab] to Ground floor.');
        $this->assertSame($floor->id, Activity::find($this->slab)->floor_id);

        $this->actingAs($this->owner())->patch("/activities/{$this->slab}/floor", ['floor_id' => null])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Moved [Slab] to Whole building.');
        $this->assertNull(Activity::find($this->slab)->floor_id);

        $this->assertSame($dates, DB::table('activities')->pluck('planned_date', 'id')->all());
        $this->assertSame([['floor_id', null, (string) $floor->id], ['floor_id', (string) $floor->id, null]],
            ActivityRevision::where('activity_id', $this->slab)->orderBy('id')->get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all());
    }

    public function test_copying_a_building_copies_its_floors_and_which_floor_each_activity_is_on(): void
    {
        $ground = Floor::create(['building_id' => $this->building->id, 'name' => 'Ground floor', 'level' => 0]);
        Floor::create(['building_id' => $this->building->id, 'name' => 'Basement 1', 'level' => -1]);
        $gone = Floor::create(['building_id' => $this->building->id, 'name' => 'Old', 'level' => 9]);
        $gone->delete();
        DB::table('activities')->where('id', $this->slab)->update(['floor_id' => $ground->id]);

        $this->actingAs($this->owner())->post("/projects/{$this->project->id}/buildings", ['name' => 'Copy', 'type' => 'building', 'plan' => 'copy', 'copy_from' => $this->building->id])
            ->assertSessionHasNoErrors();

        $copy = Building::where('name', 'Copy')->sole();
        $floors = Floor::where('building_id', $copy->id)->orderBy('level')->get();
        $this->assertSame([['Basement 1', -1], ['Ground floor', 0]], $floors->map(fn (Floor $f) => [$f->name, $f->level])->all());

        $copied = Activity::where('building_id', $copy->id)->orderBy('sort_order')->get();
        $this->assertSame([$floors[1]->id, null], $copied->pluck('floor_id')->all());
        // the source keeps its own
        $this->assertSame($ground->id, Activity::find($this->slab)->floor_id);
    }

    private function floors(string $method, string $path, array $body = []): TestResponse
    {
        return $this->actingAs($this->owner())->{$method}("/projects/{$this->project->id}/buildings/{$this->building->id}/floors{$path}", $body);
    }

    /**
     * @return list<array{string, int}>
     */
    private function floorList(): array
    {
        return Floor::where('building_id', $this->building->id)->orderBy('level')->get()->map(fn (Floor $f) => [$f->name, $f->level])->all();
    }

    /**
     * @return list<array{string, ?string, ?string}>
     */
    private function buildingRevisions(): array
    {
        return BuildingRevision::where('building_id', $this->building->id)->orderBy('id')->get()->map(fn ($r) => [$r->field, $r->old_value, $r->new_value])->all();
    }
}
