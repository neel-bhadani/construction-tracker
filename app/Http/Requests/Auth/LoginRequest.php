<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public const AWAITING_APPROVAL = 'Your account is awaiting approval.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * An unapproved account is never signed in, not even for a moment: approved_at is part of
     * the credentials Auth::attempt matches, so the session is never created.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'approved_at' => fn ($query) => $query->whereNotNull('approved_at'),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            /*
             | Under its own key when it is the approval message, so the page shows
             | it as a notice rather than as a red line under the email field: the
             | person typed nothing wrong.
             */
            throw ValidationException::withMessages(
                $this->awaitingApproval() ? ['approval' => self::AWAITING_APPROVAL] : ['email' => trans('auth.failed')],
            );
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The one failed sign-in that may say why, and only once the password has checked out.
     * Without the right password the generic message stands, so this cannot be used to ask
     * whether an address exists or is waiting for approval.
     */
    private function awaitingApproval(): bool
    {
        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials(['email' => $this->string('email')->toString()]);

        return $user !== null
            && $user->approved_at === null
            && $provider->validateCredentials($user, ['password' => $this->string('password')->toString()]);
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
