<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Crud\CrudService;
use App\Services\OrganizationContext;
use App\Traits\ApiResponses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Stourify\Enums\ReportableType;
use Modules\Stourify\Enums\ReportStatus;
use Modules\Stourify\Http\Requests\ReportIndexRequest;
use Modules\Stourify\Http\Requests\ReportResolveRequest;
use Modules\Stourify\Http\Requests\ReportStoreRequest;
use Modules\Stourify\Http\Resources\ReportResource;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Policies\ReportPolicy;

/**
 * Reports — the moderation flow.
 *
 * Filing is open to any explorer with `stourify.reports.create`; the queue and
 * every resolution are moderators only (`ReportPolicy`). The two audiences
 * never overlap in what they can see: a reporter gets their own report back
 * from `store` and nothing more, a moderator sees the queue.
 *
 * Filing is idempotent. A unique `(user_id, reportable_type, reportable_id)`
 * index means one open report per person per target — re-reporting the same
 * thing returns the existing report rather than erroring or stacking, which is
 * what "report" should feel like when tapped twice.
 *
 * @see ReportPolicy
 */
class ReportApiController extends Controller
{
    use ApiResponses;

    /**
     * The moderation queue. Moderators only, open reports by default.
     */
    public function index(ReportIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Report::class);

        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);

        $cacheKey = sprintf(
            'api:stourify:reports:index:org:%d:%s',
            app(OrganizationContext::class)->id() ?? 0,
            hash('sha256', json_encode($filters) ?: ''),
        );

        $reports = Report::getCachedList($cacheKey, fn (): LengthAwarePaginator => Report::query()
            ->with(['reportable', 'reporter'])
            ->when(! empty($filters['open']), fn (Builder $q) => $q->open())
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['reason']), fn (Builder $q) => $q->where('reason', $filters['reason']))
            ->when(! empty($filters['type']), fn (Builder $q) => $q->where(
                'reportable_type', ReportableType::from($filters['type'])->morphClass(),
            ))
            ->latest()
            ->paginate($perPage));

        return ReportResource::collection($reports);
    }

    public function show(Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        return $this->success(
            new ReportResource($report->load(['reportable', 'reporter', 'resolvedBy'])),
        );
    }

    /**
     * File a report. Idempotent per (reporter, subject).
     */
    public function store(ReportStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Report::class);

        $data = $request->validated();
        $subject = $this->resolveSubject($data['reportable_type'], $data['reportable_uuid']);

        $existing = Report::query()
            ->where('user_id', $request->user()->id)
            ->where('reportable_type', $subject->getMorphClass())
            ->where('reportable_id', $subject->getKey())
            ->first();

        if ($existing !== null) {
            return $this->success(
                new ReportResource($existing->load(['reportable'])),
                200,
                'You have already reported this.',
            );
        }

        $report = CrudService::for(Report::class)->create([
            'user_id' => $request->user()->id,
            'reportable_type' => $subject->getMorphClass(),
            'reportable_id' => $subject->getKey(),
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'status' => ReportStatus::Pending->value,
        ]);

        return $this->success(
            new ReportResource($report->load(['reportable'])),
            201,
            'Report submitted. Thank you.',
        );
    }

    /**
     * Resolve a report — pick it up, action it or dismiss it. Moderators only.
     *
     * The terminal outcomes stamp who resolved it and when; `reviewing` clears
     * those, because a report someone is still looking at is not resolved.
     */
    public function resolve(ReportResolveRequest $request, Report $report): JsonResponse
    {
        $this->authorize('update', $report);

        $status = ReportStatus::from($request->validated('status'));
        $isTerminal = $status !== ReportStatus::Reviewing;

        $report = CrudService::for(Report::class)->update($report, [
            'status' => $status->value,
            'resolution_note' => $request->validated('resolution_note'),
            'resolved_by_id' => $isTerminal ? $request->user()->id : null,
            'resolved_at' => $isTerminal ? now() : null,
        ]);

        return $this->success(
            new ReportResource($report->load(['reportable', 'reporter', 'resolvedBy'])),
            200,
            'Report updated.',
        );
    }

    /**
     * Resolve a friendly reportable token + uuid to the model it names.
     *
     * 404s rather than 422s on a missing subject: the type is already
     * validated, so an absent row is "that thing is gone", not "you sent
     * nonsense". `firstOrFail()` on the type-specific query keeps a client from
     * probing which uuids exist across tables.
     */
    private function resolveSubject(string $token, string $uuid): Model
    {
        $modelClass = ReportableType::from($token)->modelClass();

        return $modelClass::query()->where('uuid', $uuid)->firstOrFail();
    }
}
