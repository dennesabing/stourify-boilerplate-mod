# Stourify Module

The local spot discovery domain: spots, posts, reviews, the follow graph, wishlists, and the
moderation queue. Everything the Stourify apps are built on lives here — the boilerplate knows
nothing about it.

## Where things are

| Path | What |
|---|---|
| `src/Models/` | Spot, Post, Review, City, Follow, WishlistItem, ExplorerProfile, Report |
| `src/Enums/` | `SpotStatus`, `PostVisibility`, `FollowStatus`, `ReportReason`, `ReportStatus` |
| `src/StourifyModule.php` | What the module publishes: permissions, searchable models, seeders |
| `src/StourifyServiceProvider.php` | Wiring: routes, migrations, policies, morph aliases |
| `database/migrations/` | The eight beta tables, all prefixed `sto_` |
| `database/factories/` | One per model |
| `database/seeders/` | `StourifyPublicOrganizationSeeder` |
| `config/stourify.php` | Public-organization identity, discovery radius defaults |

## Enabling it

```bash
MODULE_STOURIFY_ENABLED=true   # in saas-boilerplate/.env
```

Discovery is automatic — the module is found in `../modules/`, its provider registered from its
own `composer.json`. When the flag is off, nothing loads: no routes, no migrations, no policies.

## Three rules that are not negotiable here

**1. Comments, media, reactions and tags are the boilerplate's, not ours.** `Spot` and `Post` opt
in with `HasComments`, `HasOrganizationMedia`, `HasReactions` and `HasTags`; the permissions
(`spots.comments.view`, …) are discovered from the host model. Never add a parallel table or
hand-declare those permissions. See
[`system-attachables.md`](../../saas-boilerplate/docs/system-wide-docs/system-attachables.md).

**2. Writes go through `CrudService::for(Model::class)`.** Writing through a relationship
produces a valid row and consults no policy — that is a silent authorization hole, not a
shortcut. Reads use the cached helpers.

**3. Every endpoint authorizes in its FormRequest.** Route middleware is not sufficient.

## Tenancy

Stourify is a consumer app on a multi-tenant platform. All consumer content belongs to one
system organization, `Stourify Public`, provisioned by the seeder. Clients resolve its UUID from
the login response and send it as `X-Organization-Id` — never hardcoded. The reasoning, and the
alternatives rejected, are in
[`docs/mobile-delivery/technical-spec.md`](../../docs/mobile-delivery/technical-spec.md) §6.

## Geo

No PostGIS. `Spot::scopeNearby()` bounds a lat/lng box against the composite index, then orders
by squared planar distance with the longitude axis scaled by `cos(latitude)`. It contains no trig
and no square root, so it runs identically on MySQL 8 and SQLite — many SQLite builds ship
without those functions. Accurate well past city scale; revisit beyond a few hundred kilometres.

## Tests

```bash
cd ../saas-boilerplate && php artisan test --testsuite=Modules --filter=Stourify
```
