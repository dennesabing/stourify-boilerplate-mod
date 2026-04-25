<?php

namespace Modules\Stourify;

use App\Contracts\Module;
use App\Enums\ContentRefresh;
use App\Enums\ContentType;
use App\Enums\ContentZone;
use App\Support\InjectedContent;
use Illuminate\Http\Request;

class StourifyModule implements Module
{
    public function name(): string
    {
        return 'stourify';
    }

    public function permissions(): array
    {
        return [
            'stourify.spots.manage',
            'stourify.moderation.manage',
            'stourify.spots.view',
            'stourify.spots.create',
            'stourify.spots.update',
            'stourify.spots.delete',
            'stourify.spots.moderate',
        ];
    }

    public function navigationItems(): array
    {
        return [
            [
                'label'        => 'Spots',
                'icon'         => 'map-pin',
                'route'        => 'stourify.admin.spots.index',
                'permission'   => 'stourify.spots.manage',
                'children'     => [],
                'badge'        => null,
                'sort'         => 100,
                'section'      => 'main',
                'requires_org' => false,
            ],
            [
                'label'        => 'Moderation',
                'icon'         => 'shield',
                'route'        => 'stourify.admin.moderation.queue',
                'permission'   => 'stourify.moderation.manage',
                'children'     => [],
                'badge'        => null,
                'sort'         => 101,
                'section'      => 'main',
                'requires_org' => false,
            ],
        ];
    }

    public function injectedContent(): array
    {
        return [
            new InjectedContent(
                key: 'stourify.users.follow_graph',
                module: 'stourify',
                target: 'admin.users.show',
                zone: ContentZone::Tab,
                type: ContentType::Tab,
                label: 'Follow Graph',
                icon: 'users',
                component: 'stourify:FollowGraph',
                dataEndpoint: 'stourify.admin.users.follow-graph',
                position: 50,
                afterKey: null,
                rules: [],
                defaultVisible: true,
                collapsible: false,
                allowedZones: [],
                sortable: false,
                refresh: ContentRefresh::None,
                refreshIntervalSeconds: null,
                width: null,
            ),
        ];
    }

    public function searchableModels(): array { return []; }
    public function settingsGroups(): array { return []; }
    public function webhookEvents(): array { return []; }
    public function quotaKeys(): array { return []; }
    public function importExportHandlers(): array { return []; }
    public function seeders(): array { return []; }
    public function organizationTabs(): array { return []; }
    public function userSettingsTabs(): array { return []; }
    public function injectedFormFields(): array { return []; }

    public function headerComponents(Request $request): array
    {
        return [];
    }

    public function inertiaSharedProps(Request $request): array
    {
        return [];
    }
}
