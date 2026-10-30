<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Floors are gone for good: no table, no column, no route and no word of them in the pages.
 */
class FloorsRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_floors_table_or_column(): void
    {
        $this->assertFalse(Schema::hasTable('floors'));
        $this->assertFalse(Schema::hasColumn('activities', 'floor_id'));
    }

    public function test_no_route_mentions_floors(): void
    {
        $floorRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route) => ($route->getName() ?? '').' '.$route->uri())
            ->filter(fn (string $route) => str_contains(strtolower($route), 'floor'))
            ->values()->all();

        $this->assertSame([], $floorRoutes);
    }

    public function test_no_page_or_component_mentions_floors(): void
    {
        $files = Finder::create()->files()->in(resource_path('js'))->exclude('__tests__')->name(['*.vue', '*.js']);

        $mentions = [];
        foreach ($files as $file) {
            if (preg_match('/\bfloors?\b/i', $file->getContents())) {
                $mentions[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $mentions);
    }
}
