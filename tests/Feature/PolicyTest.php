<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every role against every ability. These are what will protect the endpoints; a hidden button
 * protects nothing. An unapproved account is refused everything, whatever its role.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    /** ability => [viewer, editor, admin] */
    private const MATRIX = [
        'activity.viewAny' => [true, true, true],
        'activity.view' => [true, true, true],
        'activity.recordActual' => [false, true, true],
        'activity.create' => [false, false, true],
        'activity.update' => [false, false, true],
        'activity.delete' => [false, false, true],
        'project.view' => [true, true, true],
        'project.updateDates' => [false, false, true],
        'user.viewPending' => [false, false, true],
        'user.approve' => [false, false, true],
    ];

    /**
     * @return array<string, array{Role, bool, string, bool}>
     */
    public static function cases(): array
    {
        $cases = [];
        $roles = [Role::Viewer, Role::Editor, Role::Admin];

        foreach (self::MATRIX as $ability => $allowed) {
            foreach ($roles as $index => $role) {
                $cases["{$role->value}: {$ability}"] = [$role, true, $ability, $allowed[$index]];
                $cases["unapproved {$role->value}: {$ability}"] = [$role, false, $ability, false];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_role_against_ability(Role $role, bool $approved, string $ability, bool $expected): void
    {
        $factory = User::factory()->role($role);
        $user = ($approved ? $factory : $factory->unapproved())->create();

        [$subject, $method] = explode('.', $ability);

        $this->assertSame($expected, $user->can($method, $this->argument($subject, $method)), "{$role->value} {$ability}");
    }

    public function test_the_matrix_covers_every_policy_method(): void
    {
        $methods = fn (string $policy) => array_values(array_diff(
            get_class_methods($policy), ['before'],
        ));

        $covered = fn (string $subject) => array_values(array_map(
            fn (string $ability) => explode('.', $ability)[1],
            array_filter(array_keys(self::MATRIX), fn (string $ability) => str_starts_with($ability, "{$subject}.")),
        ));

        $this->assertEqualsCanonicalizing($methods(ActivityPolicy::class), $covered('activity'));
        $this->assertEqualsCanonicalizing($methods(ProjectPolicy::class), $covered('project'));
        $this->assertEqualsCanonicalizing($methods(UserPolicy::class), $covered('user'));
    }

    private function argument(string $subject, string $method): mixed
    {
        return match ($subject) {
            'activity' => in_array($method, ['viewAny', 'create'], true) ? Activity::class : $this->activity(),
            'project' => $this->project(),
            'user' => $method === 'viewPending' ? User::class : User::factory()->unapproved()->create(),
        };
    }

    private function project(): Project
    {
        return Project::firstOrCreate(['name' => 'Test project'], ['planned_start_date' => '2026-04-05']);
    }

    private function activity(): Activity
    {
        $scope = Scope::create(['project_id' => $this->project()->id, 'name' => 'Tower A']);

        return Activity::findOrFail(DB::table('activities')->insertGetId([
            'scope_id' => $scope->id,
            'description' => 'A',
            'planned_date' => '2026-04-05',
        ]));
    }
}
