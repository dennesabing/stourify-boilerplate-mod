<?php

namespace Modules\Stourify\Http\Requests\Admin;

use App\Http\Requests\BaseFormRequest;

class MergeSpotRequest extends BaseFormRequest
{
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
