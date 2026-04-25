<?php

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class SpotUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'string', 'exists:spot_categories,uuid'],
            'address'     => ['nullable', 'string', 'max:500'],
            'latitude'    => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude'   => ['sometimes', 'numeric', 'between:-180,180'],
        ];
    }
}
