<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\ReportableType;
use Modules\Stourify\Enums\ReportReason;

/**
 * Validates a new report.
 *
 * The subject is addressed as a friendly `reportable_type` token plus a
 * `reportable_uuid`; the controller resolves it to a model and existence is
 * checked there, since the target table depends on the type. `details` is
 * required when the reason is "other" — an "other" report with no explanation
 * is not actionable.
 */
class ReportStoreRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reportable_type' => ['required', Rule::in(ReportableType::tokens())],
            'reportable_uuid' => ['required', 'uuid'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => [
                Rule::requiredIf(fn (): bool => $this->input('reason') === ReportReason::Other->value),
                'nullable',
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
            'reportable_type.in' => 'That kind of thing cannot be reported.',
            'details.required' => 'Please describe the problem when choosing "other".',
        ];
    }
}
