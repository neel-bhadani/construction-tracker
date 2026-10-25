<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hand a project, with its buildings, activities and history, to another account: for when the
 * client registers and takes over a project set up for them. Ownership is the only thing that
 * changes. The audit trails keep naming whoever made each change.
 */
class TransferProject extends Command
{
    protected $signature = 'project:transfer
                            {project : The project\'s id}
                            {email : The email of the account that will own it}';

    protected $description = 'Move a project to another account';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No account has the email [{$this->argument('email')}]. Nothing was moved.");

            return self::FAILURE;
        }

        return DB::transaction(function () use ($user) {
            $project = Project::lockForUpdate()->find($this->argument('project'));

            if ($project === null) {
                $this->error("There is no project with the id [{$this->argument('project')}]. Nothing was moved.");

                return self::FAILURE;
            }

            if ($project->isOwnedBy($user)) {
                $this->info("[{$project->name}] already belongs to [{$user->email}]. Nothing was moved.");

                return self::SUCCESS;
            }

            if ($user->projects()->where('name', $project->name)->lockForUpdate()->exists()) {
                $this->error("[{$user->email}] already has a project named [{$project->name}]. Rename one of them first. Nothing was moved.");

                return self::FAILURE;
            }

            $from = $project->owner()->value('email');
            $project->owner_id = $user->id;
            $project->save();

            $this->info("[{$project->name}] moved from [{$from}] to [{$user->email}].");

            return self::SUCCESS;
        });
    }
}
