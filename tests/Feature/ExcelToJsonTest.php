<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Step 1 on the client's workbooks (CLIENT_WORKBOOKS_DIR; skipped without them), into a temporary
 * file, never the repository, then step 2's dry run on what it wrote: the whole round trip must
 * reproduce every planned date and Chart figure, and write nothing.
 */
class ExcelToJsonTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireClientWorkbooks();
        $this->out = sys_get_temp_dir().'/timeline-excel-to-json-'.getmypid().'/project.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        @rmdir(dirname($this->out));

        parent::tearDown();
    }

    public function test_the_workbooks_become_a_valid_document_that_imports_exactly(): void
    {
        $this->artisan('timeline:excel-to-json', ['--out' => $this->out])
            ->expectsOutputToContain('3 buildings, 151 activities')
            ->assertSuccessful();

        $document = json_decode(file_get_contents($this->out), true);
        $this->assertSame(['Tower A', 'Tower B', 'Overall Timeline'], array_column($document['buildings'], 'name'));
        $this->assertSame(['building', 'building', 'common_work'], array_column($document['buildings'], 'type'));
        // common work has no floors; each tower has the ground floor and the slabs it names
        $this->assertSame([], $document['buildings'][2]['floors']);
        $this->assertSame('Ground floor', $document['buildings'][0]['floors'][0]['name']);
        $this->assertSame('Ground floor', $document['buildings'][0]['activities'][0]['floor']);
        $this->assertNull(collect($document['buildings'][0]['activities'])->firstWhere('is_break', true)['floor']);

        $this->artisan('timeline:import-json', ['file' => $this->out, '--owner' => $this->owner()->email, '--dry-run' => true])
            ->expectsOutputToContain('Planned dates equal to the file (Excel): 151 of 151')
            ->expectsOutputToContain('DRY RUN rolled back: nothing written.')
            ->assertSuccessful();

        $this->assertSame(0, Project::count());
        $this->assertSame(0, DB::table('activities')->count());
    }

    public function test_an_existing_file_is_not_overwritten_without_force(): void
    {
        $this->artisan('timeline:excel-to-json', ['--out' => $this->out])->assertSuccessful();
        file_put_contents($this->out, '{"corrected": true}');

        $this->artisan('timeline:excel-to-json', ['--out' => $this->out])
            ->expectsOutputToContain('is already there, perhaps with your corrections')
            ->assertFailed();
        $this->assertSame('{"corrected": true}', file_get_contents($this->out));

        $this->artisan('timeline:excel-to-json', ['--out' => $this->out, '--force' => true])->assertSuccessful();
    }

    public function test_a_missing_workbook_writes_nothing(): void
    {
        $this->artisan('timeline:excel-to-json', ['--dir' => sys_get_temp_dir().'/no-workbooks-here', '--out' => $this->out])
            ->expectsOutputToContain('Cannot read')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->out);
    }
}
