<?php

namespace Modules\Stourify\Services;

use App\Services\Crud\CrudService;
use Illuminate\Support\Str;

class SpotCrudService extends CrudService
{
    protected function beforeCreate(array $data): array|false|null
    {
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']).'-'.Str::random(4);
        }

        return $data;
    }
}
