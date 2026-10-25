<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * An account made from the command line, for when REGISTRATION_OPEN is false. It is the same as
 * one made at /register: there are no roles to give it.
 */
class CreateUser extends Command
{
    protected $signature = 'user:create';

    protected $description = 'Create an account';

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
            'password' => ['required', 'string', Password::defaults()],
        ])->validate();

        $user = User::create(compact('name', 'email', 'password'));

        $this->info("Account [{$user->email}] created.");

        return self::SUCCESS;
    }
}
