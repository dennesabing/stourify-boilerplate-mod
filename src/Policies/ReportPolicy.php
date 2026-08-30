<?php

declare(strict_types=1);

namespace Modules\Stourify\Policies;

use App\Enums\RoleEnum;
use App\Models\User;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Policies\Concerns\ChecksModeratorAccess;

/**
 * Authorization for reports.
 *
 * Two distinct audiences, and the split is the point:
 *
 *   - **Anyone** with `stourify.reports.create` may file a report. That is the
 *     Report Content button, reachable from every spot, post, review and
 *     profile.
 *   - **Only moderators** — `stourify.reports.manage` or a platform override
 *     role — may see the queue or resolve anything. A report is a private
 *     channel to the moderators, never a public list.
 *
 * There is deliberately no "view my own reports" ability: a report is
 * fire-and-forget, and surfacing a reporter's history back to them is a
 * feature the beta does not need and a privacy surface it does not want.
 * `view` is moderator-only; the reporter still receives their own report in
 * the `store` response.
 */
class ReportPolicy
{
    use ChecksModeratorAccess;

    /**
     * @var list<string>
     */
    private const OVERRIDE_ROLES = [
        RoleEnum::ORG_OWNER->value,
        RoleEnum::ORG_ADMIN->value,
        RoleEnum::SUPER_ADMIN->value,
        RoleEnum::SITE_ADMIN->value,
    ];

    /**
     * The module permission that also confers moderator standing here.
     */
    private const MANAGE_PERMISSION = 'stourify.reports.manage';

    public function viewAny(User $user): bool
    {
        return $this->isModerator($user);
    }

    public function view(User $user, Report $report): bool
    {
        return $this->isModerator($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'stourify.reports.create');
    }

    /**
     * Resolving a report — setting its status, recording who and when — is
     * moderation, so it maps to `update`. A reporter never edits a filed
     * report; they file a new one.
     */
    public function update(User $user, Report $report): bool
    {
        return $this->isModerator($user);
    }

    public function delete(User $user, Report $report): bool
    {
        return $this->holdsAnyRole($user, [RoleEnum::SUPER_ADMIN->value]);
    }
}
