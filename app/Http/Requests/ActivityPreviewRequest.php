<?php

namespace App\Http\Requests;

use App\Data\SchedulePreview;
use App\Http\Requests\Concerns\ValidatesChain;
use App\Models\Activity;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a change would move, before it is saved. Policy-checked like the save itself: someone who
 * may not make the change may not probe what it would do.
 */
class ActivityPreviewRequest extends FormRequest
{
    use ValidatesChain;

    public ?SchedulePreview $preview = null;

    public function authorize(): bool
    {
        return $this->activity()
            ? $this->user()->can('update', $this->activity())
            : $this->user()->can('create', Activity::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->chainRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->chainMessages();
    }

    protected function building(): Building
    {
        return $this->route('building') ?? $this->activity()->building;
    }

    protected function activity(): ?Activity
    {
        return $this->route('activity');
    }
}
