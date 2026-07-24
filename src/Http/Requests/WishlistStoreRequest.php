<?php

declare(strict_types=1);

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Models\WishlistItem;

/**
 * Validates saving a spot.
 *
 * `city_id` is not accepted from the client — it is denormalized off the spot
 * at save time, precisely so the Wishlist's group-by-city query stays
 * single-table. Letting the caller send it would let the grouping disagree
 * with the spot's actual city.
 *
 * The save-once rule is enforced by a unique `(user_id, spot_id)` index; the
 * check here turns a re-save into a 422 naming the field instead of a 500,
 * while the index stays authoritative under a concurrent double-tap.
 */
class WishlistStoreRequest extends BaseFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'spot_uuid' => [
                'required',
                'uuid',
                Rule::exists('sto_spots', 'uuid')->whereNull('deleted_at'),
            ],
            'note' => ['nullable', 'string', 'max:500'],
            'is_downloaded_offline' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $spotId = Spot::query()->where('uuid', $this->input('spot_uuid'))->value('id');

            if ($spotId === null) {
                return;
            }

            $alreadySaved = WishlistItem::query()
                ->where('user_id', $this->user()->id)
                ->where('spot_id', $spotId)
                ->exists();

            if ($alreadySaved) {
                $validator->errors()->add('spot_uuid', 'This spot is already in your wishlist.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'spot_uuid.exists' => 'That spot does not exist.',
        ];
    }
}
