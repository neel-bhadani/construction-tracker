<?php

use Database\Seeders\Workbook\SeedDataDocument;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('workbook:document', function () {
    $path = SeedDataDocument::path();
    file_put_contents($path, SeedDataDocument::apply(file_get_contents($path)));

    $this->info("Regenerated the seed data section of {$path}.");
})->purpose('Regenerate the workbook-derived seed data section of docs/database-structure.md');
