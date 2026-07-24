<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Modules\Stourify\Enums\ReportableType;
use Modules\Stourify\Enums\ReportReason;
use Modules\Stourify\Enums\ReportStatus;

/**
 * Validates the moderation-queue list query.
 *
 * `open=1` is the queue's default working set — pending plus reviewing — so a
 * moderator does not page through already-resolved reports to find live ones.
 */
class ReportIndexRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'open' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::enum(ReportStatus::class)],
            'reason' => ['nullable', Rule::enum(ReportReason::class)],
            'type' => ['nullable', Rule::in(ReportableType::tokens())],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
