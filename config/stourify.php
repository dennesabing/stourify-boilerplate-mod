<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The public organization
    |--------------------------------------------------------------------------
    |
    | Stourify is a consumer app with no natural tenant, but the platform is
    | multi-tenant: every tenant table carries organization_id and every API
    | call carries X-Organization-Id. All consumer content therefore belongs
    | to one system organization, provisioned idempotently by
    | StourifyPublicOrganizationSeeder.
    |
    | Clients never hardcode the UUID — it comes back in the login response.
    | See docs/mobile-delivery/technical-spec.md §6.
    |
    */

    'public_organization' => [
        'slug' => env('STOURIFY_PUBLIC_ORG_SLUG', 'stourify-public'),
        'name' => env('STOURIFY_PUBLIC_ORG_NAME', 'Stourify Public'),
        'system_user_email' => env('STOURIFY_SYSTEM_USER_EMAIL', 'system@stourify.app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery defaults
    |--------------------------------------------------------------------------
    */

    'discovery' => [
        // Default radius for /spots/nearby, in kilometres.
        'default_radius_km' => (float) env('STOURIFY_DEFAULT_RADIUS_KM', 5.0),

        // Hard ceiling — the bounding-box + planar-distance approach in
        // Spot::scopeNearby() loses accuracy well beyond city scale.
        'max_radius_km' => (float) env('STOURIFY_MAX_RADIUS_KM', 50.0),
    ],

];
