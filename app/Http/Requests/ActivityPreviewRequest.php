<?php

namespace App\Http\Requests;

use App\Data\SchedulePreview;
use App\Http\Requests\Concerns\ValidatesChain;
use App\Models\Activity;
use App\Models\Building;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * What a change would move, before it is saved. Policy-checked like the save itself: another
 * account cannot probe what it would do.
 */
class ActivityPreviewRequest extends FormRequest
{
    use ValidatesChain;

    public ?SchedulePreview $preview = null;

    /** A Response, so the owner's-only refusal stays a 404. */
    public function authorize(): Response
    {
        return $this->activity()
            ? Gate::inspect('update', $this->activity())
            : Gate::inspect('create', [Activity::class, $this->building()]);
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
