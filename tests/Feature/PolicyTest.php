<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\BuildingPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every ability, for the owner and for another account. The owner may do everything; to anyone
 * else the project, its buildings and their activities do not exist, so the refusal is a 404.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private const ABILITIES = [
        'activity.view', 'activity.recordActual', 'activity.create', 'activity.update', 'activity.delete',
        'project.viewAny', 'project.view', 'project.create', 'project.update', 'project.delete',
        'building.view', 'building.create', 'building.update', 'building.rename', 'building.move', 'building.delete', 'building.updateDates',
    ];

    /** Not about one project: any account may list its own and make a new one. */
    private const ANY_ACCOUNT = ['project.viewAny', 'project.create'];

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::ABILITIES as $ability) {
            $cases["owner: {$ability}"] = [$ability, true];
            $cases["another account: {$ability}"] = [$ability, false];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_ability(string $ability, bool $asOwner): void
    {
        $user = $asOwner ? $this->owner() : User::factory()->create();
        [$subject, $method] = explode('.', $ability);

        $response = Gate::forUser($user)->inspect($method, $this->argument($subject, $method));

        if ($asOwner || in_array($ability, self::ANY_ACCOUNT, true)) {
            $this->assertTrue($response->allowed(), $ability);
        } else {
            $this->assertTrue($response->denied(), $ability);
            $this->assertSame(404, $response->status(), "{$ability} must look like a missing record");
        }
    }

    public function test_the_list_covers_every_policy_method(): void
    {
        $covered = fn (string $subject) => array_values(array_map(
            fn (string $ability) => explode('.', $ability)[1],
            array_filter(self::ABILITIES, fn (string $ability) => str_starts_with($ability, "{$subject}.")),
        ));
        $methods = fn (string $policy) => array_values(array_diff(get_class_methods($policy), ['owns']));

        $this->assertEqualsCanonicalizing($methods(ActivityPolicy::class), $covered('activity'));
        $this->assertEqualsCanonicalizing($methods(ProjectPolicy::class), $covered('project'));
        $this->assertEqualsCanonicalizing($methods(BuildingPolicy::class), $covered('building'));
    }

    /**
     * @return mixed the policy's argument list
     */
    private function argument(string $subject, string $method): mixed
    {
        return match ("{$subject}.{$method}") {
            'project.viewAny', 'project.create' => Project::class,
            'building.create' => [Building::class, $this->project()],
            'activity.create' => [Activity::class, $this->building()],
            default => match ($subject) {
                'project' => $this->project(),
                'building' => $this->building(),
                'activity' => $this->activity(),
            },
        };
    }

    private function project(): Project
    {
        return Project::firstOrCreate(['name' => 'Test project'], ['owner_id' => $this->owner()->id, 'planned_start_date' => '2026-04-05']);
    }

    private function building(): Building
    {
        return Building::firstOrCreate(['project_id' => $this->project()->id, 'name' => 'Tower A']);
    }

    private function activity(): Activity
    {
        return Activity::findOrFail(DB::table('activities')->insertGetId([
            ...$this->workId(), 'building_id' => $this->building()->id,
            'description' => 'A',
            'planned_date' => '2026-04-05',
        ]));
    }
}
