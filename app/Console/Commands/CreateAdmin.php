<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The first admin, and any admin after it. Never through sign-up: nobody can approve anyone
 * until an admin exists, and an admin must not be something a stranger can request.
 */
class CreateAdmin extends Command
{
    protected $signature = 'user:create-admin';

    protected $description = 'Create an approved admin account';

    public function handle(): int
    {
        $name = text('Name', required: true, validate: ['name' => 'max:255']);
        $email = text('Email', required: true, validate: ['email' => 'email|max:255']);

        if (User::where('email', $email)->exists()) {
            $this->error("A user with the email [{$email}] already exists. Nothing was created.");

            return self::FAILURE;
        }

        $password = password('Password', required: true, validate: ['password' => 'min:8'], hint: 'At least 8 characters.');
        $confirmation = password('Confirm password', required: true);

        if (! hash_equals($password, $confirmation)) {
            $this->error('The two passwords do not match. Nothing was created.');

            return self::FAILURE;
        }

        // re-checked against the final values; the prompts' own validation is only the first pass
        Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ])->validate();

        $user = new User(compact('name', 'email', 'password'));
        $user->forceFill(['role' => Role::Admin, 'approved_at' => now()])->save();

        $this->info("Admin [{$user->email}] created and approved.");

        return self::SUCCESS;
    }
}
