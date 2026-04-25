<?php

namespace Modules\Stourify;

use App\Providers\ModuleBaseServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Stourify\Models\Spot;
use Modules\Stourify\Policies\SpotPolicy;

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
        return [
            Spot::class => SpotPolicy::class,
        ];
    }

    public function boot(): void
    {
        parent::boot();
        $this->registerListeners();
    }

    private function registerListeners(): void
    {
        Event::listen(
            \Modules\Social\Events\PostCreated::class,
            \Modules\Stourify\Listeners\CreateOrLinkSpotFromPost::class,
        );
    }
}
