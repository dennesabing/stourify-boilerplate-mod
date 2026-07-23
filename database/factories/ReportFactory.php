<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Enums\ReportReason;
use Modules\Stourify\Enums\ReportStatus;
use Modules\Stourify\Models\Report;
use Modules\Stourify\Models\Spot;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'reportable_type' => (new Spot)->getMorphClass(),
            'reportable_id' => Spot::factory(),
            'reason' => fake()->randomElement(ReportReason::cases()),
            'details' => fake()->sentence(),
            'status' => ReportStatus::Pending,
        ];
    }

    public function actioned(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportStatus::Actioned,
            'resolved_at' => now(),
        ]);
    }
}
