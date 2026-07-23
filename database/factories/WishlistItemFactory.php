<?php

declare(strict_types=1);

namespace Modules\Stourify\Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;

/**
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
{
    protected $model = WishlistItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'city_id' => null,
            'note' => null,
            'is_downloaded_offline' => false,
        ];
    }
}
