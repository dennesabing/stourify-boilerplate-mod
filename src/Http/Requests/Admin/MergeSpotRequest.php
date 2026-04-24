<?php

namespace Modules\Stourify\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MergeSpotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_spot_uuid' => [
                'required',
                'string',
                'exists:spots,uuid',
                function ($attribute, $value, $fail) {
                    if ($value === $this->route('spot')->uuid) {
                        $fail('Cannot merge a spot into itself.');
                    }
                },
            ],
        ];
    }
}
