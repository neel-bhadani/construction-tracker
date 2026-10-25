<?php

namespace App\Services;

use App\Exceptions\PlanImportException;
use App\Exceptions\ProjectHasBuildingsException;
use App\Import\ScheduleWorkbook;
use App\Models\Activity;
use App\Models\Building;
use App\Models\BuildingRevision;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use UnexpectedValueException;

/**
 * Every user change to projects and buildings: one transaction each, one audit row per changed
 * field. A building's start dates are not here — they move planned dates, so they belong to
 * ScheduleEditor::updateBuildingDates().
 */
class ProjectEditor
{
    private const PROJECT_FIELDS = ['name', 'details', 'planned_start_date', 'timezone'];

    private const BUILDING_FIELDS = ['name', 'type'];

    public function __construct(private readonly PlanWriter $writer) {}

    /**
     * @param  array{name: string, details: ?string, planned_start_date: string, timezone?: string}  $data
     */
    public function createProject(array $data, User $user): Project
    {
        return DB::transaction(function () use ($data, $user) {
            $project = new Project($data);
            $project->created_by = $user->id;
            $project->save();

            $this->projectRevision($project, $user, 'created', null, $project->name);

            return $project->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProject(Project $project, array $data, User $user): Project
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $before = $this->values($project, self::PROJECT_FIELDS);

            $project->fill(array_intersect_key($data, array_flip(self::PROJECT_FIELDS)))->save();

            foreach ($this->values($project, self::PROJECT_FIELDS) as $field => $after) {
                if ($after !== $before[$field]) {
                    $this->projectRevision($project, $user, $field, $before[$field], $after);
                }
            }

            return $project->refresh();
        });
    }

    /**
     * @throws ProjectHasBuildingsException
     */
    public function deleteProject(Project $project, User $user): void
    {
        DB::transaction(function () use ($project, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $buildings = $project->buildings()->orderBy('sort_order')->orderBy('id')->pluck('name')->all();

            if ($buildings !== []) {
                throw new ProjectHasBuildingsException($project->name, $buildings);
            }

            $this->projectRevision($project, $user, 'deleted', $project->name, null);
            $project->delete();
        });
    }

    /**
     * A new building, its plan empty, copied from another building, or imported from Excel.
     * Copied and imported plans bring the chain, durations, adjustments and break flags — never
     * actual dates or notes, which belong to the site they were recorded on — and their dates are
     * computed from this building's own start.
     *
     * @param  array{name: string, type: string, planned_start_date?: ?string}  $data
     *
     * @throws PlanImportException
     */
    public function createBuilding(Project $project, array $data, User $user, Building|ScheduleWorkbook|null $plan = null, ?string $origin = null): Building
    {
        return DB::transaction(function () use ($project, $data, $user, $plan, $origin) {
            $last = Building::where('project_id', $project->id)->lockForUpdate()->max('sort_order');

            $building = Building::create([
                'project_id' => $project->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'planned_start_date' => $data['planned_start_date'] ?? $project->planned_start_date,
                'sort_order' => $last + 1,
            ]);

            $this->buildingRevision($building, $user, 'created', null, $building->name, $origin);

            if ($plan instanceof Building) {
                $this->writer->write($building, $this->copiedPlan($plan), $user, $origin);
            } elseif ($plan instanceof ScheduleWorkbook) {
                $this->writer->write($building, $plan->plan(withActualDates: false), $user, $origin);

                $mismatches = $plan->mismatches($building, compareDates: $building->planned_start_date->toDateString() === $plan->startDate);
                if ($mismatches !== []) {
                    throw new PlanImportException("The imported plan does not match the workbook's own figures:\n".implode("\n", $mismatches));
                }
            }

            return $building->refresh();
        });
    }

    /**
     * @param  array{name: string, type: string}  $data
     */
    public function updateBuilding(Building $building, array $data, User $user): Building
    {
        return DB::transaction(function () use ($building, $data, $user) {
            $building = Building::lockForUpdate()->findOrFail($building->id);
            $before = $this->values($building, self::BUILDING_FIELDS);

            $building->fill(array_intersect_key($data, array_flip(self::BUILDING_FIELDS)))->save();

            foreach ($this->values($building, self::BUILDING_FIELDS) as $field => $after) {
                if ($after !== $before[$field]) {
                    $this->buildingRevision($building, $user, $field, $before[$field], $after);
                }
            }

            return $building->refresh();
        });
    }

    /** Swap a building with its neighbour in the project's order. */
    public function moveBuilding(Building $building, string $direction, User $user): void
    {
        DB::transaction(function () use ($building, $direction, $user) {
            $siblings = Building::where('project_id', $building->project_id)
                ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $siblings->search(fn (Building $sibling) => $sibling->id === $building->id);
            $neighbour = $siblings->get($direction === 'up' ? $index - 1 : $index + 1);

            if ($neighbour === null) {
                return;
            }

            // renumber first, so two buildings that shared a position still end up apart
            $siblings->each(function (Building $sibling, int $position) {
                $sibling->sort_order = $position + 1;
            });
            $self = $siblings[$index];
            [$self->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $self->sort_order];

            foreach ($siblings as $sibling) {
                $old = (string) $sibling->getOriginal('sort_order');
                if ($sibling->isDirty('sort_order')) {
                    $sibling->save();
                    if ($sibling->is($self) || $sibling->is($neighbour)) {
                        $this->buildingRevision($sibling, $user, 'sort_order', $old, (string) $sibling->sort_order);
                    }
                }
            }
        });
    }

    /**
     * A soft delete: the timeline leaves the app, its activities and audit trail stay.
     */
    public function deleteBuilding(Building $building, User $user): void
    {
        DB::transaction(function () use ($building, $user) {
            $building = Building::lockForUpdate()->findOrFail($building->id);

            $this->buildingRevision($building, $user, 'deleted', $building->name, null);
            $building->delete();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function copiedPlan(Building $source): array
    {
        return Activity::where('building_id', $source->id)->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (Activity $activity) => [$activity->id => [
                'description' => $activity->description,
                'predecessor' => $activity->predecessor_id,
                'offset_days' => $activity->offset_days,
                'date_adjustment_days' => $activity->date_adjustment_days,
                'adjustment_reason' => $activity->adjustment_reason,
                'is_break' => $activity->is_break,
                'sort_order' => $activity->sort_order,
            ]])
            ->all();
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, ?string>
     */
    private function values(Project|Building $model, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $model->getAttribute($field);
            $values[$field] = match (true) {
                $value === null => null,
                $value instanceof CarbonInterface => $value->toDateString(),
                $value instanceof \BackedEnum => (string) $value->value,
                default => (string) $value,
            };
        }

        return $values;
    }

    private function projectRevision(Project $project, User $user, string $field, ?string $old, ?string $new): void
    {
        ProjectRevision::create(['project_id' => $project->id, 'user_id' => $user->id, 'field' => $field, 'old_value' => $old, 'new_value' => $new]);
    }

    private function buildingRevision(Building $building, User $user, string $field, ?string $old, ?string $new, ?string $note = null): void
    {
        BuildingRevision::create(['building_id' => $building->id, 'user_id' => $user->id, 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'note' => $note]);
    }

    /**
     * Read an uploaded workbook's sheet, turning a layout or formula the parser does not know into
     * the same refusal as a mismatch.
     *
     * @throws PlanImportException
     */
    public static function readWorkbook(string $path, string $sheet): ScheduleWorkbook
    {
        try {
            return ScheduleWorkbook::readFile($path, $sheet);
        } catch (UnexpectedValueException $exception) {
            throw new PlanImportException("Sheet [{$sheet}] cannot be imported. {$exception->getMessage()}", previous: $exception);
        } catch (SpreadsheetException) {
            throw new PlanImportException('The file could not be read as an Excel workbook.');
        }
    }
}
