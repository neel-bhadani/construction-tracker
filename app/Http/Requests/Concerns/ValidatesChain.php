<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\InvalidScheduleException;
use App\Models\Activity;
use App\Models\Building;
use App\Services\ScheduleDays;
use App\Services\ScheduleEditor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The fields that place an activity in the graph, and the cycle check that guards them.
 *
 * The person gives "Comes after" and either the planned date or the days after it; what is stored
 * is the offset, as in the workbook. With a planned date the offset is worked out here:
 *
 *   offset = planned date − (building start + work-day of "Comes after") − this row's adjustment
 *
 * The "Comes after" row's own adjustment is not in it: an adjustment moves only its own row's date
 * (Excel's D column), never the work-day the next row counts from (column C). The date adjustment
 * itself is not editable here any more; an edit can only keep it or clear it.
 *
 * The cycle check is ScheduleEditor::preview(), the recalculator's own walk on the proposed graph,
 * run on save as well as on preview: the preview is a courtesy, not the protection.
 */
trait ValidatesChain
{
    /** @var array{predecessor_id: ?int, offset_days: int, date_adjustment_days: int}|null */
    private ?array $resolvedChain = null;

    abstract protected function building(): Building;

    abstract protected function activity(): ?Activity;

    /**
     * @return array<string, list<mixed>>
     */
    protected function chainRules(): array
    {
        $inBuilding = fn () => Rule::exists('activities', 'id')->where('building_id', $this->building()->id);

        return [
            'predecessor_id' => [
                'nullable', 'integer', $inBuilding(),
                ...($this->activity() ? [Rule::notIn([$this->activity()->id])] : []),
            ],
            // either one; the planned date wins when both are sent
            'planned_date' => ['nullable', 'required_without:offset_days', 'date_format:Y-m-d'],
            'offset_days' => ['nullable', 'required_without:planned_date', 'integer', 'min:0', 'max:65535'],
            'clear_adjustment' => ['sometimes', 'boolean'],
            // where a new activity goes: right below this row, else at the end
            'after_id' => $this->activity() ? ['prohibited'] : ['nullable', 'integer', $inBuilding()],
            'push' => $this->activity() ? ['prohibited'] : ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function chainMessages(): array
    {
        return [
            'predecessor_id.exists' => 'Choose an activity from this building.',
            'predecessor_id.not_in' => 'An activity cannot come after itself.',
            'planned_date.required_without' => 'Enter the planned date.',
            'planned_date.date_format' => 'Enter the planned date as a date.',
            'offset_days.required_without' => 'Enter the planned date.',
            'offset_days.min' => 'The days after cannot be negative: an activity cannot come before the one it comes after.',
            'after_id.exists' => 'Choose a row from this building to insert below.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['predecessor_id', 'planned_date', 'offset_days', 'after_id', 'push', 'clear_adjustment'])) {
                return;
            }

            $chain = $this->resolveChain($validator);
            if ($chain === null) {
                return;
            }

            try {
                $this->preview = app(ScheduleEditor::class)->preview($this->building(), $chain, $this->activity(), ...$this->insertion());
            } catch (InvalidScheduleException $exception) {
                $validator->errors()->add('predecessor_id', $exception->getMessage());
            }
        }];
    }

    /**
     * @return array{predecessor_id: ?int, offset_days: int, date_adjustment_days: int}
     */
    public function chain(): array
    {
        return $this->resolvedChain ?? throw new \LogicException('chain() is only known once the request has been validated.');
    }

    /**
     * Where a new activity goes. Empty for an edit, and for a new activity added at the end.
     *
     * @return array{afterId?: int, push?: bool}
     */
    public function insertion(): array
    {
        return $this->activity() === null && $this->filled('after_id')
            ? ['afterId' => (int) $this->input('after_id'), 'push' => $this->boolean('push', true)]
            : [];
    }

    /**
     * @return array{predecessor_id: ?int, offset_days: int, date_adjustment_days: int}|null null when it fails
     */
    private function resolveChain(Validator $validator): ?array
    {
        $predecessorId = $this->filled('predecessor_id') ? (int) $this->input('predecessor_id') : null;
        $adjustment = $this->activity() === null || $this->boolean('clear_adjustment') ? 0 : $this->activity()->date_adjustment_days;

        if ($this->filled('planned_date')) {
            $predecessorDay = $predecessorId === null ? 0 : (int) Activity::whereKey($predecessorId)->value('planned_day');
            $earliest = ScheduleDays::toDay($this->building()->planned_start_date) + $predecessorDay + $adjustment;
            $offset = ScheduleDays::toDay($this->input('planned_date')) - $earliest;

            if ($offset < 0) {
                $validator->errors()->add('planned_date', 'The planned date cannot be before '.ScheduleDays::toDate($earliest).': an activity cannot come before the one it comes after.');

                return null;
            }
            if ($offset > 65535) {
                $validator->errors()->add('planned_date', 'The planned date is too far after the activity it comes after.');

                return null;
            }
        } else {
            $offset = (int) $this->input('offset_days');
        }

        return $this->resolvedChain = ['predecessor_id' => $predecessorId, 'offset_days' => $offset, 'date_adjustment_days' => $adjustment];
    }
}
