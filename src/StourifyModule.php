<?php

namespace Modules\Stourify;

use App\Contracts\Module;
use Illuminate\Http\Request;

class StourifyModule implements Module
{
    public function name(): string { return 'stourify'; }

    public function permissions(): array { return []; }
    public function navigationItems(): array { return []; }
    public function searchableModels(): array { return []; }
    public function settingsGroups(): array { return []; }
    public function webhookEvents(): array { return []; }
    public function quotaKeys(): array { return []; }
    public function importExportHandlers(): array { return []; }
    public function seeders(): array { return []; }
    public function organizationTabs(): array { return []; }
    public function userSettingsTabs(): array { return []; }
    public function injectedContent(): array { return []; }
    public function injectedFormFields(): array { return []; }
    public function headerComponents(Request $request): array { return []; }
    public function inertiaSharedProps(Request $request): array { return []; }
}
