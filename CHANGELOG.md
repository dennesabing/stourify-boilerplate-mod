# Changelog

All notable changes to the Stourify module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.8.0] - 2026-07-24

### Added

- **A Bruno API collection** in `bruno/` exercising the core loop end to end — auth, profile,
  spots, posts, feed, likes, reviews, helpful votes, follows, wishlist, search, reports and media.
  Plain-text `.bru` files, version-controlled and diffable, satisfying the M1 milestone's
  "collection exercises the full core loop against a seeded DB" gate. Building it surfaced the
  media host-id/UUID gap fixed in `saas-boilerplate` v1.22.0.

### Changed

- `FollowApiController::requests()` documents why it is deliberately uncached (a notifications-style
  surface needs an accepted request to vanish immediately), distinguishing it from the cached
  followers/following lists — a post-implementation sweep note, not a behavior change.

## [0.7.0] - 2026-07-24

### Added

- **Post likes and review "helpful" votes**, on the platform's existing reaction subsystem — the
  writes go through the boilerplate's `POST /api/v1/reactions` endpoint (toggle, one per explorer
  per host, deduplicated by its unique index), so the module adds no like/unlike routes of its own.
- `Review` now uses `HasReactions`. Its "helpful" vote rides on a reaction (`helpful`) rather than a
  bare counter, because per-user votes need a per-user record — a bare counter cannot stop one
  person voting many times. `helpful_count` remains as the denormalized column the Reviews screen
  sorts on, kept truthful from those reactions.
- Each host narrows the reaction set it accepts: a `Post` accepts only `like`, a `Review` only
  `helpful` (`supportedReactions()`), enforced by the platform's `ReactionCrudService`. Liking a
  post with `love`, or a review with `like`, is a 422.
- **`ReactionCountObserver` keeps `sto_posts.likes_count` and `sto_reviews.helpful_count`
  truthful.** These are denormalized because the feed and Reviews screen render and sort on them
  across many rows — counting per row on read would be an N+1. It observes the platform `Reaction`
  model (so it fires for reactions on any host; `instanceof` guards limit it to posts and reviews)
  and *recomputes* the count from an indexed aggregate rather than incrementing, so it cannot drift
  across a toggle, a switch, a cascade or a rolled-back transaction. Counters are updated with a
  direct column write (no `updated_at` churn, no event recursion) plus an explicit cache clear.
- **`is_liked` / `marked_helpful`** on the post and review resources — whether the *caller* has
  reacted, which is per-viewer and cannot be denormalized. The read paths (feed, post & review
  index/show) eager-load the caller's own reactions via `LoadsViewerReactions`, one query per page
  rather than one per row; the flags are absent, not false, when not evaluated.
- 10 feature tests driving the real reaction endpoint: like/unlike a post, mark/unmark a review,
  the per-viewer flag, feed integration, the per-host type constraints, and that a directly-deleted
  reaction still corrects the counter (proving the recompute, not an increment).

## [0.6.0] - 2026-07-24

### Added

- **Discovery search** — `GET /api/v1/discover/search?q=&type=`, across spots, cities and people.
  `type` returns one paginated result set for its tab (`spots` / `cities` / `people`); omitting it
  returns a capped preview of all three for the "All" tab. Runs through Scout (Meilisearch in
  production, the collection driver in tests), org-scoped by `OrganizationSearchable`.
- **Only discoverable spots are returned** — a search is a discovery surface, so a draft never
  appears, the same rule the map and nearby follow (enforced via a Scout `->query()` published
  constraint). People and cities carry no such filter: a profile header is public even for a
  private account (you can find someone to request to follow), and cities are public reference
  data. A private account is findable by handle; a test asserts it.
- **`ExplorerProfile` is now Scout-searchable** (`OrganizationSearchable`, registered in
  `StourifyModule::searchableModels()`), indexing the handle and bio — the two things a person
  types to find someone. Email is deliberately not indexed: it lives on `User`, not the profile,
  and must not become searchable through the back door.
- `PersonResource` — the compact people-search card, the profile-sourced counterpart to
  `ExplorerResource`. No follower counts (a search list does not need them and computing them per
  hit is a fan-out) and never an email.
- 11 feature tests: per-type matching, the draft exclusion, the private-account-findable rule, the
  grouped preview and its cap, and validation/permission gating.

### Notes

- **This is deliberately not the boilerplate's generic `/api/v1/search`.** That endpoint already
  pulls every module's `searchableModels()`, but it applies no domain filtering — it would surface
  draft spots — and returns a flat, type-agnostic shape rather than the tabbed spots/cities/people
  result the Discover screen needs. The module owns `/discover/search` for that reason; the
  generic endpoint is left untouched.
- Tags are not a search section yet. Tags are the boilerplate's attachable, not a module model, so
  tag search is a later addition rather than part of this slice.

## [0.5.0] - 2026-07-24

### Added

- **The wishlist API** — `GET|POST /api/v1/wishlist`, `GET|PATCH|DELETE /api/v1/wishlist/{uuid}`,
  filterable by city and by offline-download state. A wishlist is private by nature — there is no
  beta surface where anyone sees anyone else's — so it is a flat owner-only policy, and the list
  is scoped to the caller in SQL as well as in the policy.
- `city_id` is denormalized off the spot at save time and never accepted from the client, so the
  Wishlist screen's group-by-city query stays single-table and cannot disagree with the spot's
  actual city. Saving the same spot twice is a 422 rather than a 500 from the unique
  `(user_id, spot_id)` index; unsaving hard-deletes, so a soft-delete tombstone can't make
  re-saving fail forever (`WishlistItem` uses no `SoftDeletes`).
- **The profile API** — `GET /api/v1/profile` (the caller's own, returning `null` data before one
  exists rather than 404), `PATCH /api/v1/profile` (an upsert serving both the onboarding create
  and every later edit), and `GET /api/v1/profiles/{user}` (anyone's public header).
- Username uniqueness is platform-wide and case-insensitive: the handle is normalized to lowercase
  and validated `unique` ignoring the caller's own row, so re-saving an unchanged username is not
  a self-conflict. Illegal characters and uppercase are rejected with a message, not silently
  coerced.
- A profile header is public even for a private account — privacy gates content, not identity, so
  `is_private` is exposed precisely so a client can render "Requested" vs "Following".
  `shows_location_on_spots` is a setting shown only to the owner.
- **The three header counts (spots, followers, following) are computed on read**, not read from
  the denormalized `sto_explorer_profiles` columns. Keeping those columns truthful would need
  `Follow` and `Spot` observers plus an initial-compute path for follows that predate a profile —
  a drift-prone amount of machinery for three indexed `COUNT`s on a single-record read. Followers
  and following count active edges only; a pending request is not a follower. Spots count
  published spots only. The columns remain for a later pass if the header ever gets hot.
- `ProfileResource`, `WishlistItemResource`, `ExplorerProfilePolicy`, `WishlistItemPolicy`, and
  the store/update/index form requests for each.
- 30 feature tests across the two resources.
- **The reports API** — `GET|POST /api/v1/reports`, `GET /api/v1/reports/{uuid}`, and
  `POST /api/v1/reports/{uuid}/resolve`. One polymorphic flow covers reporting spots, posts,
  reviews and users. Filing is open to `stourify.reports.create`; the queue and every resolution
  are moderators only (`stourify.reports.manage` or a platform override role) — the two audiences
  never overlap in what they can see.
- **A dedicated `ReportableType` allowlist (`spot`/`post`/`review`/`user`), separate from the
  platform's attachable-morph registry.** Reportability and attachability are different questions
  — a user is reportable but is not an attachment host — so reports own their list. The API speaks
  in those short tokens, never a morph alias or a `Modules\…` FQCN, so the reportable surface can
  grow without leaking internal identifiers. Comments are intentionally excluded for now; comment
  moderation is its own later surface.
- Filing is **idempotent** per `(reporter, subject)`: a unique index means one report per person
  per target, and re-reporting returns the existing report rather than erroring or stacking —
  what "report" should feel like when tapped twice. Two *different* explorers may still each
  report the same thing.
- Resolution stamps who resolved a report and when for the terminal outcomes (`actioned`,
  `dismissed`), requiring a resolution note for the audit trail; moving a report back to
  `reviewing` clears those stamps, and `pending` is not a target a moderator can push to.
- A report is **anonymous to the reported party**: the reporter's identity reaches moderators
  through the queue but never rides on any subject-facing surface, and no report response exposes
  an email.
- `ReportResource`, `ReportPolicy`, and the store / resolve / index form requests.
- 21 feature tests, including the polymorphic subject across all four reportable kinds, the
  alias-vs-FQCN storage distinction, idempotency, the moderator/reporter split and anonymity.
- **The home feed** — `GET /api/v1/feed?cursor=`, a cursor-paginated stream of the posts a viewer
  is entitled to see, newest first. Published posts only: your own drafts belong on your profile,
  not your feed.
- **`Post::scopeVisibleTo()` now holds the single definition of the post audience rule**, shared
  by the feed and the post index so the two enforcement surfaces cannot drift — the classic way a
  feed leaks is a visibility rule that the list and the feed each reimplement slightly
  differently. The post index layers its moderator bypass on top of the scope; the feed grants no
  such bypass, because a moderator's home feed is a consumption surface, not a moderation tool.
  Tested directly: a moderator's feed excludes a stranger's private post.
- **Ranking is recency (`published_at` desc, `id` desc), deliberately, not as a placeholder.** A
  cursor is only stable against a fixed, indexed, monotonic ordering; an engagement- or
  recency-decay score is none of those and would make a cursor skip or repeat posts as scores
  shifted between pages. Personalized relevance is an explicit post-beta concern (the "For You"
  engine). Recency is what composes correctly with cursors and the client's offline page cache.
- **The feed deliberately does not use `getCachedList()`** — the one justified exception to the
  module's read-through-cache rule. It is personalized per follow graph and cursor-keyed, so a
  server cache would be high-cardinality and would need busting on every new post across every
  viewer. The offline design puts feed persistence on the client (React Query, last N pages), per
  technical-spec.md §7; the controller documents the exception.
- 12 feed feature tests: composition, newest-first order, the drafts/private/followers visibility
  rules end to end, the no-moderator-bypass distinction, and cursor pagination proven to neither
  drop nor repeat a post across three pages.

## [0.4.0] - 2026-07-24

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
