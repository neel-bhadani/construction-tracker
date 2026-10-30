<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Work IDs: "{project code}-{number}", "SV-001". Numbered per project from the project's own
 * counter, which only goes up: a deleted activity's number is never given again, and nothing is
 * ever renumbered. Changing the project's code changes only the IDs given after it.
 *
 * Must run inside the caller's transaction: the project row stays locked until it commits, so two
 * activities created at once in the same project wait for each other and get different numbers.
 */
class WorkIds
{
    /**
     * The next $count Work IDs of the project, in order, keyed by their number.
     *
     * @return array<int, string>
     */
    public function take(int $projectId, int $count = 1): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Work IDs are taken inside the transaction that writes them.');
        }

        $project = Project::withTrashed()->lockForUpdate()->findOrFail($projectId, ['id', 'code', 'next_activity_number']);
        $first = $project->next_activity_number + 1;

        DB::table('projects')->where('id', $projectId)->update(['next_activity_number' => $project->next_activity_number + $count]);

        $ids = [];
        for ($number = $first; $number < $first + $count; $number++) {
            $ids[$number] = self::format($project->code, $number);
        }

        return $ids;
    }

    /** SV-001; past 999 the number simply grows: SV-1000. */
    public static function format(string $code, int $number): string
    {
        return $code.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
