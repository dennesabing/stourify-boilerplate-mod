<?php

namespace Modules\Stourify\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SpotAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
