<?php

namespace Modules\Stourify;

use App\Providers\ModuleBaseServiceProvider;

class StourifyServiceProvider extends ModuleBaseServiceProvider
{
    protected function moduleClass(): string
    {
        return StourifyModule::class;
    }

    protected function moduleBasePath(): string
    {
        return dirname(__DIR__);
    }

    protected function policyMap(): array
    {
        return [];
    }
}
