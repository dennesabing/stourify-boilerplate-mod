<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Modules\Stourify\Enums\ReportableType;
use Modules\Stourify\Models\Report;

/**
 * A report.
 *
 * The subject is rendered as `{type, uuid}` using the friendly reportable
 * token, never the stored morph value — a client sees `spot`, not
 * `stourify_spot` or an FQCN.
 *
 * The reporter's identity is exposed only to moderators (everyone who can
 * reach this resource through the queue is one; `store` returns it to the
 * reporter themselves). A report is anonymous to the reported party by design,
 * so `reporter_uuid` must never ride along on any surface the subject can see.
 *
 * @property Report $resource
 */
class ReportResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;

        return [
            'uuid' => $report->uuid,
            'reason' => $report->reason->value,
            'details' => $report->details,
            'status' => $report->status->value,

            'subject' => $this->subject($report),

            'reporter_uuid' => $this->whenLoaded('reporter', fn () => $report->reporter?->uuid),

            'resolution' => [
                'note' => $report->resolution_note,
                'resolved_at' => $report->resolved_at?->toIso8601String(),
                'resolved_by_uuid' => $this->whenLoaded('resolvedBy', fn () => $report->resolvedBy?->uuid),
            ],

            'created_at' => $report->created_at?->toIso8601String(),

            'can' => $this->resolvePermissions($report),
        ];
    }

    /**
     * The reported thing, as a friendly token plus its uuid. `null` when the
     * subject has since been hard-deleted — a resolved report outlives what it
     * was about.
     *
     * @return array{type: string|null, uuid: string|null}|null
     */
    private function subject(Report $report): ?array
    {
        if (! $report->relationLoaded('reportable') || $report->reportable === null) {
            return null;
        }

        return [
            'type' => ReportableType::tryFromModel($report->reportable)?->value,
            'uuid' => $report->reportable->uuid ?? null,
        ];
    }
}
