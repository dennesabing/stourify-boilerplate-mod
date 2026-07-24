<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\ReportStatus;

/**
 * Validates a moderator's decision on a report.
 *
 * A moderator may move a report to `reviewing` (picking it up), `actioned`
 * (the content was dealt with) or `dismissed` (no action). `pending` is not a
 * target — that is the state a report is born in, not one it can be pushed
 * back to. `resolution_note` is required for the two terminal outcomes so the
 * queue keeps an audit trail of *why*, and disallowed for `reviewing`, which
 * resolves nothing.
 */
class ReportResolveRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isTerminal = in_array($this->input('status'), [
            ReportStatus::Actioned->value,
            ReportStatus::Dismissed->value,
        ], true);

        return [
            'status' => ['required', Rule::in([
                ReportStatus::Reviewing->value,
                ReportStatus::Actioned->value,
                ReportStatus::Dismissed->value,
            ])],
            'resolution_note' => [
                $isTerminal ? 'required' : 'prohibited',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'A report can only be moved to reviewing, actioned or dismissed.',
            'resolution_note.required' => 'Record why the report was actioned or dismissed.',
            'resolution_note.prohibited' => 'A resolution note only belongs on an actioned or dismissed report.',
        ];
    }
}
