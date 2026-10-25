<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\InvalidScheduleException;
use App\Models\Activity;
use App\Models\Scope;
use App\Services\ScheduleEditor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The fields that place an activity in the graph, and the cycle check that guards them. The
 * check is ScheduleEditor::preview(), so it is the recalculator's own walk, run on the proposed
 * graph without writing. It runs on save as well as on preview: the preview is a courtesy, not
 * the protection.
 */
trait ValidatesChain
{
    abstract protected function scope(): Scope;

    abstract protected function activity(): ?Activity;

    /**
     * @return array<string, list<mixed>>
     */
    protected function chainRules(): array
    {
        return [
            'predecessor_id' => [
                'nullable', 'integer',
                Rule::exists('activities', 'id')->where('scope_id', $this->scope()->id),
                ...($this->activity() ? [Rule::notIn([$this->activity()->id])] : []),
            ],
            'offset_days' => ['required', 'integer', 'min:0', 'max:65535'],
            'date_adjustment_days' => ['required', 'integer', 'min:-32768', 'max:32767'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function chainMessages(): array
    {
        return [
            'predecessor_id.exists' => 'Choose an activity from this scope.',
            'predecessor_id.not_in' => 'An activity cannot follow itself.',
            'offset_days.min' => 'Offset days cannot be negative: an activity cannot come before the one it follows.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['predecessor_id', 'offset_days', 'date_adjustment_days'])) {
                return;
            }

            try {
                $this->preview = app(ScheduleEditor::class)->preview($this->scope(), $this->chain(), $this->activity());
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
        return [
            'predecessor_id' => $this->filled('predecessor_id') ? (int) $this->input('predecessor_id') : null,
            'offset_days' => (int) $this->input('offset_days'),
            'date_adjustment_days' => (int) $this->input('date_adjustment_days'),
        ];
    }
}
