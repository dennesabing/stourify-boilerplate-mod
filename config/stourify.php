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
    | Demo content
    |--------------------------------------------------------------------------
    |
    | StourifyDemoContentSeeder fills the public organization with fixture
    | spots so a dev box or a demo build is not an empty app. Every deploy runs
    | module-published seeders (`php artisan modules:seed`), so the seeder must
    | decide for itself whether it belongs in the target environment: on
    | production, content is real and fixtures must never appear. Off in
    | production unless STOURIFY_SEED_DEMO_CONTENT explicitly says otherwise.
    |
    */

    'seed_demo_content' => (bool) env(
        'STOURIFY_SEED_DEMO_CONTENT',
        env('APP_ENV', 'production') !== 'production',
    ),

    /*
    |--------------------------------------------------------------------------
    | The test rig's login
    |--------------------------------------------------------------------------
    |
    | One ordinary explorer account that live runs sign the app in with. A
    | spare key kept in a known drawer: nobody's personal key, and nobody
    | uses it to test what happens without one.
    |
    | Both values are read from the gitignored saas-boilerplate/.env and
    | nowhere else. Leave them unset and StourifyRigAccountSeeder does
    | nothing, which is what every deploy needs. It also refuses to run in
    | production whatever these say, because a login whose password sits in
    | a developer's .env does not belong on a live install.
    |
    | Recreate it from nothing with:
    |   php artisan db:seed --class="Modules\Stourify\Database\Seeders\StourifyRigAccountSeeder"
    |
    */

    'rig_account' => [
        'email' => env('STOURIFY_RIG_EMAIL'),
        'password' => env('STOURIFY_RIG_PASSWORD'),
        'name' => env('STOURIFY_RIG_NAME', 'Stourify Rig'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery defaults
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Sharing a spot outside the app
    |--------------------------------------------------------------------------
    |
    | Where a spot's public page lives: `<base_url>/s/<spot-uuid>`, served by
    | web-front on the apex host (STOURIFY-301). The app's Share button hands
    | out this link, so it must be the address of the public site on the tier
    | the app is talking to. Dev sets its own in `.env`; production uses the
    | default, because its deploy does not edit `.env`.
    |
    */

    'share' => [
        'base_url' => env('STOURIFY_SHARE_BASE_URL', 'https://stourify.com'),
    ],

    'discovery' => [
        // Default radius for /spots/nearby, in kilometres.
        'default_radius_km' => (float) env('STOURIFY_DEFAULT_RADIUS_KM', 5.0),

        // Hard ceiling — the bounding-box + planar-distance approach in
        // Spot::scopeNearby() loses accuracy well beyond city scale.
        'max_radius_km' => (float) env('STOURIFY_MAX_RADIUS_KM', 50.0),
    ],

];
