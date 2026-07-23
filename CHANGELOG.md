# Changelog

All notable changes to the Stourify module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **The follow graph** — `GET|POST /api/v1/follows`, `GET|DELETE /api/v1/follows/{uuid}`,
  `POST /api/v1/follows/{uuid}/accept`, and `GET /api/v1/follows/requests`. The same table backs
  both the Followers and Following screens, read from opposite sides via `?direction=`.
- **Following a private account creates a request; following a public one takes effect
  immediately — and the client does not get to say which.** The status is derived from the
  target's `ExplorerProfile.is_private`, never from the payload; accepting `status` from the
  request would let any caller walk straight past a private account's gate.
- The two parties hold different rights, which is the shape of `FollowPolicy`. Either party may
  `delete` — the follower unfollowing, the followee rejecting a request or removing an existing
  follower, all the same row and so one endpoint. Only the followee may `accept`; a follower who
  could accept their own request would make private accounts meaningless.
- **A private account's follower and following lists are visible only to that account and its
  accepted followers.** Without this, `is_private` would hide someone's posts while leaving their
  social graph an open book. A *pending* follower does not qualify.
- `GET /follows/requests` is its own route rather than an index filter: "requests addressed to me"
  is a different question from "who follows this account", is always about the caller, and is
  never visible to anyone else. Pending edges are excluded from public follower lists entirely.
- `ExplorerResource` — the public face of an explorer. It deliberately does not extend
  `BaseResource`: the abilities that matter belong to the *edge*, not the person, and a `can`
  block on a user invites a client to read it as authority over that account. Email is never
  exposed, and a user who has never opened the app has no profile row, so every profile-sourced
  field degrades to null.
- `AttachesExplorerProfiles` — resolves each user's `ExplorerProfile` in one query per page via
  `setRelation()`. `User` lives in the boilerplate, which must contain zero module references, so
  there is no `User::stourifyProfile()` relationship to eager-load and there must never be one;
  resolving the profile inside the resource instead would be an N+1.
- 28 feature tests, including the directionality of the edge, the privacy of the graph, and a
  regression guard described below.

### Fixed

- **`FollowPolicy::update()` denied the very write `accept` depends on.** `update` was written to
  return `false` on the reasoning that a follow edge has no editable fields — but `accept()` goes
  through `CrudService`, which authorizes `update`, so the accept endpoint returned 403 for the
  one person entitled to use it. `update` and `accept` are now deliberately the same rule, since
  the followee accepting is a follow edge's only legal mutation. A test asserts the two abilities
  agree, so they cannot drift apart again.

### Notes

- Unfollowing **hard-deletes** the edge. `sto_follows` carries a unique index on
  `(follower_id, followee_id)`, so a soft-delete tombstone would make re-following the same person
  fail forever. `Follow` accordingly does not use `SoftDeletes`.
- Blocking is not part of this slice — it arrives with the privacy settings in M5. Until then a
  follow can be removed but not prevented from recurring.

## [0.3.0] - 2026-07-24

### Added

- **The spots API** — the first slice of M1. `GET|POST /api/v1/spots`,
  `GET|PATCH|PUT|DELETE /api/v1/spots/{uuid}`, and `GET /api/v1/spots/nearby`, behind
  `auth:sanctum` + `set_organization_from_header`, UUID-bound, every response carrying the `can`
  index from `BaseResource`.
- `SpotPolicy` with two tiers — moderator (`stourify.spots.manage` or a platform override role)
  and contributor (the spot's `user_id`, holding the matching `stourify.spots.*` permission).
  Writes are authorized by `CrudService`; reads authorize in the controller, which `CrudService`
  never sees.
- **Draft visibility is enforced at the query level, not only the policy.** A policy runs per
  model, after rows are selected and paginated, so it cannot stop a list from surfacing another
  explorer's draft. `SpotApiController::visibleTo()` constrains the SQL — discoverable spots plus
  your own — and the new `SpotPolicy::viewAnyDraft()` ability keeps the moderator test in the
  policy rather than duplicated in a controller. Covered by tests from both directions.
- `SpotResource` and `CityResource`. `distance_km` appears only on nearby responses, and is
  computed in PHP rather than SQL: `scopeNearby()` deliberately orders by *squared* planar
  distance to avoid `SQRT`, which many SQLite builds lack, so taking the root in PHP restores
  kilometres without reintroducing the portability problem the scope exists to avoid.
- Form requests for index, store, update and nearby. `sort` is whitelisted to indexed or
  denormalized columns, `per_page` is capped at 100, and the nearby `radius` is capped from
  `config('stourify.discovery.max_radius_km')` — beyond city scale the planar approximation stops
  holding. `status` accepts only `draft` and `published` on write; `under_review` and `removed`
  are moderation outcomes, and `is_verified` is not a writable field at all.
- 25 feature tests: happy path, permission denial, cross-author denial, draft leakage in both
  directions, validation, pagination and slug collisions.
- **The reviews API** — `/api/v1/reviews` CRUD, filterable by spot, author and rating, sortable by
  `created_at`, `rating` or `helpful_count`. `ReviewPolicy` mirrors `SpotPolicy`'s moderator /
  author tiers, minus any visibility test: a review has no draft state, so it is public from the
  moment it is written and the list needs no query-level scoping.
- **`ReviewObserver` maintains `sto_spots.rating_average` and `reviews_count`.** It lives in an
  observer rather than the controller because the same numbers must hold for reviews arriving via
  sync push, seeders, factories or an artisan command — a controller covers only the API. It
  recomputes from an indexed aggregate rather than incrementing a counter, which cannot drift
  across restores, bulk deletes or rolled-back transactions. It writes the two derived columns
  without an authorization check, deliberately: routing it through `CrudService` would authorize
  the *reviewer* against the spot, so every review by anyone but the spot's author would fail.
- The one-review-per-explorer-per-spot rule now returns a 422 naming the field instead of a 500
  from the unique index. The index stays authoritative — it is what holds under a concurrent
  double-submit from a retrying offline client.
- **The posts API** — `/api/v1/posts` CRUD plus `POST /api/v1/posts/{uuid}/publish`. Publishing is
  its own route with its own ability rather than a PATCH field, so a general field update cannot
  quietly push a draft into the feed; it is idempotent, so a retrying offline client may send it
  twice without moving the post up the feed.
- **Post audience rules**, enforced in two places because they have to be. `PostPolicy::view()`
  gates a single record; `PostApiController::visibleTo()` constrains the query so a list cannot
  page through posts the viewer was never entitled to. A post is visible to a stranger only if it
  is *both* published and permitted by its visibility — `public` to everyone, `followers` to the
  author's accepted followers, `private` to nobody else. A `pending` follow request does not
  unlock followers-only content, and the follow edge is directional: being followed by an author
  grants nothing. A dataset-driven test asserts the list and the record agree across all seven
  combinations, since whichever is more permissive would be the leak.
- `published_at` is set from the server clock, never accepted from the client — a device with a
  skewed or altered clock must not be able to backdate itself up the feed.
- 43 further feature tests across reviews and posts.

### Fixed

- **`Spot`, `Post` and `Review` used `HasOrganizationMedia` without implementing
  `Spatie\MediaLibrary\HasMedia`**, which the trait's own docblock requires. The media library
  binds a model-event listener typed against that interface, so *deleting* any of the three threw
  a `TypeError` before the row was removed. M0 never exercised a delete, so this only surfaced
  once the API had a destroy endpoint.
- `Spot`, `Review` and `Post` had no `user()` relationship despite `user_id` being the contributor
  and the column every ownership rule keys on. Added to all three, plus `Spot::owner()` for the
  post-beta business claim — the two are distinct: the contributor never changes, the owner
  governs only the commercial surface.

### Notes

- **Likes and helpful votes are not implemented yet, and `likes_count` / `helpful_count` are
  therefore always 0.** Both are reactions in platform terms, and the boilerplate already owns
  reactions end to end — the `Reaction` model, `HasReactions`, and a core `/api/v1/reactions`
  controller. The right implementation is to let that surface handle the writes and have the
  module maintain its denormalized counters from `Reaction` events, which also needs the
  `{host}.reactions.*` permissions to be discovered from `Post` and `Review`. That is its own
  slice, deliberately not bolted onto this one. A bare `increment()` endpoint was rejected: with
  no per-user record it would let one client vote arbitrarily many times.

### Added

- Module scaffold: `StourifyModule` (permissions, searchable models, seeders) and
  `StourifyServiceProvider`, auto-discovered from `../modules/` — no boilerplate edit.
- Migrations for the beta data model, all tables prefixed `sto_`: `sto_cities`,
  `sto_explorer_profiles`, `sto_spots`, `sto_posts`, `sto_reviews`, `sto_follows`,
  `sto_wishlist_items`, `sto_reports`. Scout index names follow the table names.
- Morph aliases deliberately keep the longer `stourify_` namespace (`stourify_spot`, singular).
  They are not table names — they are written into polymorphic columns shared with the rest of
  the platform, where being unambiguous outweighs being short.
- Models with `HasUuid` + `Cacheable` + `BelongsToOrganization`, and a factory for each.
- Backed enums: `SpotStatus`, `PostVisibility`, `FollowStatus`, `ReportReason`, `ReportStatus`.
- `StourifyPublicOrganizationSeeder` — provisions the single system organization all consumer
  content belongs to. Idempotent; reuses an existing user as owner rather than seeding a
  synthetic account, and never writes a known credential.
- `Spot::scopeNearby()` — bounding-box prefilter against the `(latitude, longitude)` index,
  ordered by squared planar distance. No trig, no `SQRT`, so it behaves identically on MySQL 8
  and SQLite; monotonic with true distance, so the ordering is exact.
- `Spot::scopePublished()` and `Post::scopePublished()`.
- A morph map registered from the service provider, so polymorphic columns store
  `stourify_spot` rather than the FQCN — a namespace change can no longer orphan attachments.
- 21 module permissions under the `stourify.*` namespace.
- `config/stourify.php` — public-organization identity and discovery radius defaults.
- Feature tests covering module registration, morph aliases, seeder idempotency, the nearby
  and published scopes, attachable wiring, and the one-review-per-explorer constraint.

### Notes

- **Comments, media, reactions and tags are the boilerplate's attachables, not module tables.**
  `Spot` and `Post` opt in via `HasComments`, `HasOrganizationMedia`, `HasReactions` and
  `HasTags`; their permissions are discovered from the host model. The module must never grow
  parallel tables for them — see
  `saas-boilerplate/docs/system-wide-docs/system-attachables.md`.
- The prior implementation of this module (spots, moderation, follow graph) was cleared at
  `2980ef8` and remains recoverable at `21bf198`.
