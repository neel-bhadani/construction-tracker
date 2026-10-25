<?php

namespace App\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Workbooks uploaded for "Import from Excel", held between choosing the file and choosing the
 * sheet. Each is addressed by a random token, never by a name the user supplied, and anything
 * older than an hour is removed whenever a new one arrives.
 */
class ImportUploads
{
    private const LIFETIME_SECONDS = 3600;

    /** Defaults to storage/app/private/imports; tests pass a temporary directory. */
    public function __construct(private readonly ?string $directory = null) {}

    public function store(UploadedFile $file): string
    {
        $this->prune();

        $token = (string) Str::uuid();
        $file->move($this->directory(), "{$token}.xlsx");

        return $token;
    }

    public function path(string $token): ?string
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $path = $this->directory()."/{$token}.xlsx";

        return is_file($path) ? $path : null;
    }

    public function forget(string $token): void
    {
        if ($path = $this->path($token)) {
            unlink($path);
        }
    }

    private function prune(): void
    {
        foreach (glob($this->directory().'/*.xlsx') ?: [] as $path) {
            if (filemtime($path) < time() - self::LIFETIME_SECONDS) {
                unlink($path);
            }
        }
    }

    private function directory(): string
    {
        $directory = $this->directory ?? storage_path('app/private/imports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
