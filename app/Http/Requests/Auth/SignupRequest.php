<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A stranger asking for an account. Nothing this form accepts lets anybody in: role and
 * approved_at are set by SignupController, never read from input.
 */
class SignupRequest extends FormRequest
{
    /** Submissions per IP per minute — the same five sign-in allows. */
    private const MAX_ATTEMPTS = 5;

    /** Accounts per IP per hour. */
    private const MAX_ACCOUNTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Before validation, because a failed submission is exactly the one a script working
     * through a list of addresses makes, and the uniqueness rule answers it.
     */
    protected function prepareForValidation(): void
    {
        $this->ensureIsNotRateLimited();

        RateLimiter::hit($this->attemptsKey(), 60);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'That email is already registered.',
            'password.min' => 'The password must be at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }

    /**
     * @return array{name: string, email: string, password: string}
     */
    public function accountAttributes(): array
    {
        return $this->safe()->only(['name', 'email', 'password']);
    }

    public function ensureIsNotRateLimited(): void
    {
        foreach ([[$this->attemptsKey(), self::MAX_ATTEMPTS], [$this->accountsKey(), self::MAX_ACCOUNTS]] as [$key, $max]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'signup' => $seconds < 120
                    ? "Too many sign-up attempts. Please try again in {$seconds} seconds."
                    : 'Too many sign-up attempts. Please try again in '.ceil($seconds / 60).' minutes.',
            ]);
        }
    }

    /** Called by the controller once the row exists. */
    public function recordAccountCreated(): void
    {
        RateLimiter::hit($this->accountsKey(), 3600);
    }

    private function attemptsKey(): string
    {
        return 'signup-attempts|'.$this->ip();
    }

    private function accountsKey(): string
    {
        return 'signup-accounts|'.$this->ip();
    }
}
