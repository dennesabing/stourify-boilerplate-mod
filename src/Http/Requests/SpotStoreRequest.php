<?php

namespace Modules\Stourify\Http\Requests;

use App\Http\Requests\BaseFormRequest;

class SpotStoreRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'string', 'exists:spot_categories,uuid'],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'address'     => ['nullable', 'string', 'max:500'],
        ];
    }
}
