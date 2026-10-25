<?php

namespace Tests\Feature;

use Database\Seeders\Workbook\SeedDataDocument;
use Tests\TestCase;

class WorkbookDocumentTest extends TestCase
{
    public function test_seed_data_section_of_the_docs_matches_the_workbooks(): void
    {
        $this->requireClientWorkbooks();

        $document = file_get_contents(SeedDataDocument::path());

        $this->assertSame(
            $document,
            SeedDataDocument::apply($document),
            'docs/database-structure.md is out of date with the workbooks. Run php artisan workbook:document.',
        );
    }
}
