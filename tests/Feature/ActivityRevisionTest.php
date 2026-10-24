<?php

namespace Tests\Feature;

use App\Models\ActivityRevision;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivityRevisionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * notes is text and audited like any other field; an overwritten note must survive whole.
     */
    public function test_a_note_longer_than_255_characters_is_kept_whole_in_the_audit_trail(): void
    {
        $project = Project::create(['name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        $scope = Scope::create(['project_id' => $project->id, 'name' => 'Tower A']);
        $activityId = DB::table('activities')->insertGetId(['scope_id' => $scope->id, 'description' => 'A', 'planned_date' => '2026-04-05']);
        $oldNote = Str::repeat('Shuttering delayed by rain. ', 40);
        $newNote = Str::repeat('Concrete poured on site. ', 50);

        $revision = ActivityRevision::create([
            'activity_id' => $activityId,
            'user_id' => User::factory()->editor()->create()->id,
            'field' => 'notes',
            'old_value' => $oldNote,
            'new_value' => $newNote,
        ]);

        $this->assertGreaterThan(255, strlen($oldNote));
        $this->assertSame([$oldNote, $newNote], [$revision->fresh()->old_value, $revision->fresh()->new_value]);
    }
}
