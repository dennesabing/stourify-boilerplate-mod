<?php

namespace Modules\Stourify\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

class SpotAdminRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'address'     => ['nullable', 'string', 'max:500'],
            'category_id' => ['nullable', 'integer', 'exists:spot_categories,id'],
            'status'      => ['required', 'in:pending,active'],
        ];
    }
}
