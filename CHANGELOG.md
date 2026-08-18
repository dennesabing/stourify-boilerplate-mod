# Changelog

All notable changes to the Stourify module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **A new post starts Private instead of Public** (STOURIFY-105). Creating a post used to make it
  visible to everyone unless the author said otherwise — like a notebook page pinned to a public
  noticeboard the moment you start writing it. Now a post nobody assigned a visibility to is
  private, and the author chooses to share it.

  Two places said "public" and both moved: `PostApiController::store()`'s fallback for a request
  that omits `visibility`, and the `sto_posts.visibility` column's own default, changed by a new
  migration. An explicit `visibility` in the request still wins in every case.

  **No post that already exists was touched.** The migration changes the column's default and
  reads and writes zero rows, so anything anyone has already shared stays exactly as they left it.

### Fixed

- **Registering now creates the explorer profile, instead of leaving the account without one.**
  (STOURIFY-82) Signing up created a user and nothing else. The Stourify half of someone's identity —
  handle, bio, home city, interests — lives in `sto_explorer_profiles`, and no path on registration
  ever wrote that row: 24 of the 31 accounts on the dev database had none, and a brand-new user
  tapping their own Profile tab was told they had not set up a profile. It went unnoticed because
  every seeder and test fixture builds one explicitly, so no automated path ever registered fresh and
  then looked.

  It is now created in `JoinPublicOrganizationAsExplorer::enrol()`, beside the organization
  membership it belongs with — the same shared method `StourifyExplorerBackfillSeeder` calls, so the
  two cannot drift. Since the account has not been asked what it wants to be called yet, a new
  `Support/ExplorerUsernameGenerator` derives a starter handle from the display name and makes it
  free, the way a hotel gives you a room number on arrival; Edit Profile changes it. The generator
  satisfies all four rules the handle has to meet at once — 3 to 30 characters, `^[a-z0-9_.]+$`, and
  unique across the whole table — because a handle that fails any of them is a profile whose owner is
  locked out of their own edit form.

  Nothing in `saas-boilerplate` changed: the event this hangs off, `UserRegistered`, was already
  published and already listened to. Seeded and factory users are untouched, because that event fires
  only for the `registration` source.

  **Existing profile-less accounts are not backfilled by this change** — it is forward-only, and a
  write across records that already exist is a separate decision on its own card.

### Changed

- **The privacy policy's photo-metadata paragraph now describes what the app does, not what it used
  to.** (STOURIFY-40) `resources/legal/privacy.md` §2.5 carried a warning that the app did **not**
  strip metadata from image files, and that warning was honest when it was written. The mobile app now
  removes it on the device before a photo is uploaded, so the warning had become the opposite of the
  truth — which is the same defect as overclaiming, just in the other direction.

  The replacement explains what the hidden information is and where it goes, and keeps naming the
  gaps: PNG and HEIC stills and **video** are not stripped, so anyone uploading one should still
  remove the metadata themselves. `tests/Feature/LegalDocumentsTest.php` pins both halves — the new
  claim must be present and the old disclaimer must be absent, so the two can never sit on the page
  contradicting each other. The document's `isPlaceholder` handling is untouched.

- **`GET /spots/nearby` distance ordering is now asserted over a dataset a shuffle cannot pass by
  luck.** (STOURIFY-8) The existing coverage compared two spots, which a random order clears half
  the time. `tests/Feature/SpotApiTest.php` adds a five-spot General Santos cluster, all on one
  meridian so the separations are arithmetic — 0, ~1.1, ~2.6, ~5.3, ~8.1 km — created out of order
  on purpose, and asserts the full returned sequence plus that the reported `distance_km` values
  are themselves sorted and land in the expected bands. The endpoint itself is unchanged; what
  changed is that its contract is now pinned. Mobile had never called this route (see the mobile
  changelog, STOURIFY-8), so nothing had exercised the ordering in practice.

### Added

- **Privacy policy, terms of service and an account-deletion page, published at stable public URLs**
  (STOURIFY-34). Google Play will not accept a listing without a privacy-policy URL and a
  web-reachable account-deletion URL, and a user-generated-content app without terms does not pass
  review. The three documents now live as markdown under `resources/legal/` and are registered into
  the platform's `LegalDocumentRegistry` during boot, so they are served at `/privacy`, `/terms` and
  `/account-deletion` — unauthenticated, server-rendered, no new infrastructure and no separate
  deployment. Markdown rather than PHP because a lawyer's revision should be a diff to prose.

  **The content is a placeholder and every page says so, visibly.** A banner at the top of each
  document states that the text awaits legal review, and every value no agent should invent — the
  legal entity, the address, the contacts, the governing law, the minimum age, the liability cap —
  is left as a `[BRACKETED]` token. A placeholder that does not announce itself is worse than no
  page at all, because it invites being shipped by accident.

  What is *not* placeholder is the factual half, which was written from the code rather than from a
  template, because Play's Data safety form must match the app's actual behaviour or the build is
  rejected. The policy therefore names foreground-only location (`ACCESS_FINE_LOCATION`,
  `ACCESS_COARSE_LOCATION`, and explicitly no background location or geofencing), camera and photo
  library access, media stored on DigitalOcean Spaces in `sgp1` with **public** file visibility,
  the offline WatermelonDB copy held on the device, and the follow/block/report graph. It also
  discloses that **EXIF metadata is not stripped from uploads**, which is true today and can embed
  the coordinates a photo was taken at. Equally load-bearing are the honest negatives: no analytics
  SDK, no crash reporter, no advertising or ad identifiers, no device identifiers, no push tokens and
  no third-party sign-in — all verifiable in the source, and all things Data safety asks about.

  The deletion facts are stated in plain language, including the consequence a departing user will
  actually hit: because the account row survives the 18-month retention window and `users.email` is
  unique, **a deleted account's email address cannot be re-registered until that window elapses.**
  The window in the prose is asserted against `config('prune.retention_months')` by a test, so the
  page cannot quietly drift away from what the code does.

- **The profile header now says what the CALLER's relationship to it is** (STOURIFY-35).
  `ProfileResource` gains a `viewer` block — `{is_self, is_following, follow_status, follow_uuid}` —
  computed by `ProfileApiController` from the caller's own outgoing edge. Nothing in the platform
  exposed this before, which had two consequences on the client: a Follow button that always read
  "Follow" whatever the relationship, and no way at all to unfollow, because `DELETE /follows/{uuid}`
  addresses the *edge* and the client had no way to learn its uuid short of paging the whole follow
  list. `follow_status` is three-valued (`null` / `pending` / `active`) rather than a boolean pair:
  a pending request to a private account renders "Requested", which is neither following nor
  not-following, and collapsing it would offer to send a request that already exists. Deliberately
  the caller's *outgoing* edge only — the reverse ("they follow me") belongs to the followers list,
  and a button reading it would offer to unfollow somebody never followed. One indexed lookup,
  skipped entirely when the caller is the subject.

- **`GET /posts?user_uuid=` — one explorer's posts**, for the other-user profile grid (STOURIFY-35).
  A filter on the existing index rather than a new `/users/{uuid}/posts` route, applied *after*
  `visibleTo()`, so it narrows an already-scoped query and can never surface another explorer's
  unpublished or followers-only work. Validated rather than silently ignored: an unvalidated filter
  Laravel discards would list every visible post while the client believed it was showing one
  person's.

- **Blocking — an explorer can now block another, and it holds on both sides of the graph**
  (STOURIFY-36). New `sto_blocks` table and `Block` model, and a deliberately narrow API at
  `/api/v1/blocks`: list your own blocks, add one (idempotent, so a double-tap is not an error),
  lift one. The row is directed — only the blocker may lift it — but its *effect* is symmetric, and
  that is the whole design. Everything downstream asks the undirected question through
  `Block::hiddenUserIdsFor()` / `isHiddenFrom()`, so no caller has to remember which way round the
  row was written. A block enforced in one direction only is not a block: the blocked party would
  go on reading, searching and following the person who blocked them.

  Enforced at six places, which is what it takes for the feature to be real rather than cosmetic:
  `Post::scopeVisibleTo()` (the home feed and the post index share it), `PostPolicy::view()` as its
  per-record twin, `SpotApiController::visibleTo()` plus the nearby query, both the spots and the
  people sections of `/discover/search`, `ProfileApiController::show()`, and
  `FollowApiController::store()`.

  Creating a block **deletes the follow edges between the two in both directions**, pending requests
  included. Leaving the reverse edge intact would keep the blocked party inside the blocker's
  followers-only audience and keep the blocker on their following list. Lifting a block does not
  restore them — they were hard-deleted, not suspended, and re-creating them would silently
  re-follow on someone's behalf. The removals go one at a time through `CrudService` rather than as
  a mass delete, for the reason STOURIFY-32 documents below: a mass delete skips model events, so
  `SyncTombstoneObserver` never fires and an offline client keeps the edge forever.

  **The blocked party is never told**, which extends the rule reports already follow — a report is
  anonymous to the reported party. There is no endpoint that answers "who has blocked me": the index
  is constrained to the caller's own rows, `BlockResource` never renders a blocker, and `BlockPolicy`
  gives the blocked party no ability on the resource at all. The profile header's refusal carries one
  neutral message thrown from one place, identical in both directions and checked ahead of the
  `firstOrFail()`, so neither the wording nor a 403-vs-404 difference reveals which side of the block
  the caller is on.

  No new permission. Blocking rides on `stourify.follows.manage`, which every explorer already holds
  — blocking *is* an operation on the follow graph, and the module already publishes one participant
  capability for that graph rather than a create/update/delete triple. A `stourify.blocks.manage`
  granted to every explorer without exception would carry no information.

- **An explorer's content is withdrawn the moment they delete their account** (STOURIFY-32).
  `RemoveExplorerContentOnUserDeleted` listens for the platform's new `UserDeleted` and soft-deletes
  the departing explorer's spots, posts and reviews, and hard-deletes their wishlist items, explorer
  profile and follow edges in **both** directions. Without it the cascading foreign keys would still
  do the job eventually — but "eventually" is the platform's retention window, six months by
  default, during which the app would be telling somebody their account is deleted while continuing
  to publish their photographs to everybody else. So the cascade remains what *erases*; this
  listener is what *withdraws*. The rows are deleted one model at a time rather than by a mass
  query, which costs extra statements and buys the only thing that makes the deletion reach devices
  that already hold the data: a mass delete skips model events, so `SyncTombstoneObserver` never
  fires and a client that was offline during the deletion would keep the content forever.

### Changed

- **`StourifyDemoContentSeeder` now gates itself on `config('stourify.seed_demo_content')`, which is
  off when `APP_ENV=production`** (STOURIFY-17). Deploys now run every seeder a module publishes
  (`php artisan modules:seed`), and that command deliberately cannot special-case one — a
  module-agnostic runner that knew which of a module's seeders were "the demo one" would not be
  module-agnostic. So the decision has to live in the seeder. Without it, the fix for the
  fresh-install defect would have started writing fixture spots into live content on every deploy.
  Set `STOURIFY_SEED_DEMO_CONTENT=true` on a demo host that actually wants the General Santos
  samples. The public-organization and explorer-backfill seeders are unconditional, as before —
  they provision structure, not content.

### Fixed

- **The module's create endpoints validated before they authorized, so an unpermitted caller was
  answered with 422 instead of 403** (STOURIFY-23). Laravel runs a FormRequest's `authorize()`
  ahead of its `rules()`, and these five requests left `authorize()` at the `BaseFormRequest`
  default of `true` — so the only gate was `CrudService`'s `Gate::authorize()`, which fires after
  the controller has been reached and therefore after the whole rule set has run. A caller holding
  none of the module's create permissions got a field-by-field description of the payload the
  server wanted, and only reached 403 if their body happened to validate. `FollowStoreRequest`'s
  `user_uuid` rule made it worse than cosmetic: an `exists` lookup on `users` told an unauthorized
  caller whether an account exists. `PostStoreRequest`, `SpotStoreRequest`, `ReviewStoreRequest`,
  `WishlistStoreRequest` and `FollowStoreRequest` now each override `authorize()` against their
  policy's `create` ability, per the root `CLAUDE.md`'s *authorize in the FormRequest (preferred)*.
  `CrudService` keeps its central gate as the backstop; no permission, policy or role grant
  changed.

  **What this card did not find:** the reported defect. STOURIFY-23 was raised on a live
  observation that `POST /api/v1/posts` returned 201 for a user without `stourify.posts.create`.
  It does not — the audit swept every write path in `src/Http/Controllers/Api/V1` and all of them
  route through `CrudService`, which gates them. The probe read `hasPermissionTo()` without a
  Spatie team id set, which reports no roles for any user in a team-scoped install; a real request
  sets that context via `set_organization_from_header`. The regression test added here asserts the
  valid-payload 403 that already held, so the claim cannot be re-lost.

- **Media uploads 403'd for everyone, so no photo could be attached to a post or a spot**
  (STOURIFY-22). The platform resolves an attachable's permission as
  `{host::permissionPrefix()}.media.{verb}` — `posts.media.create`, `spots.media.create` — and
  permission discovery creates those rows, but nothing granted them: not the `explorer` role, not
  any user. `POST /api/v1/media/upload-url` and `POST /api/v1/media/attach` therefore answered
  `403 This action is unauthorized.` for every caller, which is what silently dropped PostCompose's
  photos (STOURIFY-18) and blocked the spot photo path (STOURIFY-5). `posts.media.view`,
  `posts.media.create`, `spots.media.view`, `spots.media.create` are now granted to `explorer` in
  `StourifyModule::EXPLORER_PERMISSIONS`, alongside the discovered reaction permissions already
  there — a seeder/sync path, so a fresh database has them. `update`/`delete` are deliberately not
  granted: an uploader can already mutate their own media through `MediaPolicy`'s `uploaded_by_id`
  ownership rule.

### Added

- Regression coverage for create-endpoint authorization (STOURIFY-23) in `PostApiTest`,
  `SpotApiTest`, `ReviewApiTest`, `WishlistApiTest` and `FollowApiTest` — each asserts 403 and an
  unchanged row count for a caller without the permission, with a **valid** body and again with an
  invalid one. The invalid-body half is the one that was red before the fix; the valid-body half
  pins the behaviour the card mistakenly reported as broken.

- **`StourifyMediaPolicy`** — media rights on this module's photo hosts now follow *write* rights on
  the host. A role grant is not scoped to a host instance, so `posts.media.create` on `explorer`
  said "explorers may attach media to posts", not "only to their own", and
  `App\Policies\MediaPolicy::create()` never consults the host's owner — an explorer could attach
  photos to anyone's post or spot. The subclass overrides `create()` alone, requiring
  `Gate::allows('update', $host)` when the host is a `Post` or a `Spot` and deferring to the parent
  for every other host, and is registered for `App\Models\Media` in
  `StourifyServiceProvider::policyMap()`. Delegating to the host's own `update` ability keeps the
  rule in `PostPolicy`/`SpotPolicy`, moderator tier included, instead of forking a second copy of it.
  Deliberately not fixed in `saas-boilerplate`: `AttachablePolicy` is generic platform code other
  projects consume, and every attachable would inherit the new rule.

- **`tests/Feature/MediaAttachmentApiTest.php`** — covers both endpoints against both hosts: an
  explorer succeeds on what they authored, is forbidden on someone else's, and a moderator still
  reaches both. Its users are provisioned from `StourifyModule::EXPLORER_PERMISSIONS` itself rather
  than a hand-written permission list, so the suite fails if the role ever stops granting what the
  media endpoints require — the gap that let this defect through in the first place.

- **Media conversions on `Spot` and `Post`** — both now register `thumb` (400x400) and `medium`
  (1080x1080) conversions, scoped to the `attachments` collection (`HasOrganizationMedia`'s default
  and the only collection either model's photos land in), matching `User::registerMediaConversions()`'s
  idiom (`width().height().sharpen(10)`). `SpotResource`/`PostResource` already computed
  `thumb_url` from `hasGeneratedConversion('thumb')`, but it was always `null` — neither model
  registered any conversion, so every grid, gallery strip, and feed row downloaded the full-size
  original. `thumb` feeds grid/gallery/feed rows; `medium` is the spot/post hero view. `medium` is
  not yet surfaced on either resource — only `thumb_url` and `url` are — a client wanting the hero
  size still gets the original for now.

- **`GET`/`POST /api/v1/posts/{post}/comments`** — a module-owned, uuid-addressed adapter over the
  boilerplate's generic, polymorphic `Comment` (`HasComments` on `Post`). The mobile client already
  called these two routes (`mobile/src/shared/api/comments.ts`) and got 404s: comments only existed
  on `GET/POST /api/v1/comments?commentable_type=<FQCN>&commentable_id=<int>`, and `PostResource`
  never returns a numeric id, so that generic surface was unreachable from the app. Comments on
  `PostDetailScreen` were broken end to end. `PostCommentApiController` delegates authorization to
  `PostPolicy::view()` before either endpoint touches a comment — a viewer who cannot see the post
  (unpublished, private, or a followers-only post by someone they don't follow) gets 403 on both,
  not just a filtered list. Listing is newest-first, paginated, with the commenter eager-loaded;
  `parent_id` is validated to belong to the *same* post's thread, not just to exist. Writes go
  through `CrudService::for(Comment::class)` (resolves to the boilerplate's own
  `CommentCrudService`); reads through `Comment::getCachedList()`, tagged
  `Post:{uuid}:comments` to match `Comment::$invalidatesRelationCachesOf`'s own tag naming.
  - **Deviation, not a workaround for this module:** `commentable_type` is written and queried as
    the FQCN (`Modules\Stourify\Models\Post::class`), not the registered `stourify_post` morph
    alias every other Stourify attachment uses. `CommentCrudService::beforeCreate()` calls static
    methods directly on the raw `commentable_type` string without first resolving it through
    `Relation::getMorphedModel()` — unlike `ReactionCrudService::assertSupportedType()`, which does.
    Passing the alias throws `Class "stourify_post" not found`. That asymmetry is a pre-existing
    boilerplate defect affecting every caller of the generic comment write path, not something
    Task 2 introduces; fixing it means editing `saas-boilerplate`, which is out of scope here (the
    boilerplate stays module-agnostic and untouched). Tracked for a future boilerplate-side fix.
  - New: `Http/Controllers/Api/V1/PostCommentApiController.php`,
    `Http/Requests/PostCommentStoreRequest.php`, `tests/Feature/PostCommentApiTest.php`.

### Added

- **`media` on `SpotResource` and `PostResource`** — the photo gallery M3c needs, and the reason the
  Home Feed rendered no images despite posts having photos: neither resource exposed the media both
  models have carried since M1's presigned upload flow (`HasOrganizationMedia`). Each entry is
  `{uuid, url, thumb_url}`, following `App\Http\Resources\MediaResource`'s existing shape —
  `thumb_url` is `null` unless a `thumb` conversion has actually been generated (Spot and Post
  register no media conversions today, so `thumb_url` is always `null` for now; `url` is the only
  field mobile can rely on until a conversion is added). Always an array, never `null`, even with no
  photos. `media` is eager-loaded by every caller (`SpotApiController@index/@show/@nearby`,
  `PostApiController@index/@show`, `FeedApiController@index`) so a page costs one query total, not
  one per row — the same pattern `1321dcc` established for `PostResource::author`.

### Fixed

- **`ReviewResource` exposes reviewer identity** — a nested `author` object (`{uuid, name, username,
  avatar_url}`), `whenLoaded('user')`, sourced via `AttachesExplorerProfiles` exactly as
  `PostResource::author` is. Closes the identical N+1-across-the-network gap `PostResource` had
  before this milestone: a reviews list needed one `GET /api/v1/profiles/{user}` per row to show who
  wrote each review. `author_uuid` is kept for backward compatibility.
  `ReviewApiController@index/@show` now eager-load `user.media` and attach explorer profiles for the
  whole page in one query, not per row.
- **`PostResource` exposes author identity** — a nested `author` object (`{uuid, name, username,
  avatar_url}`), `whenLoaded('user')`, sourced from the user's `ExplorerProfile` (via
  `AttachesExplorerProfiles`, the existing follow-graph pattern) and avatar media. Closes an
  N+1-across-the-network gap where rendering a feed row's header required a
  `GET /api/v1/profiles/{user}` per post — the app's most-scrolled screen. `author_uuid` is kept
  for backward compatibility. `FeedApiController@index` and `PostApiController@index/@show` now
  eager-load `user.media` and attach explorer profiles for the whole page in one query, not per
  row. See `docs/superpowers/specs/2026-07-29-m3-feed-and-spot-hub-design.md` §3.1.
- **`is_liked` is now present (not absent) on write responses** — `store`, `update` and `publish`
  reload the post through the same `withViewerReaction()` mechanism the feed and index use, so an
  optimistic client UI has a value to reconcile against immediately after a write rather than only
  on the next read. See design spec §3.2.

## [0.10.0] - 2026-07-29

### Added

- **M2a — the backend offline-sync contract**: `GET /api/v1/stourify/sync/delta?since=` (pull) and
  `POST /api/v1/stourify/sync/push` (drain), the server half of the offline-sync spine the mobile
  WatermelonDB client syncs against. Implements the frozen contract in
  `docs/superpowers/specs/2026-07-25-m2a-backend-sync-contract-design.md`; the wire protocol is
  `packages/offline-sync-core` §5. Both routes sit in the existing
  `auth:sanctum` + `set_organization_from_header` group and add **no new permission** — the delta
  returns only the caller's own data, and push authorizes every operation through the policies a
  single write already goes through.
  - **`SyncRegistry`** — the one source of truth for the six synced tables (`sto_spots`,
    `sto_reviews`, `sto_wishlist_items`, `sto_follows`, `sto_explorer_profiles`, `sto_cities`),
    each with its model, its caller scope, and whether it is pushable. Controller, serializer,
    observer registration and tests all read it; nothing hardcodes the table list. Posts are
    deliberately excluded — they are server-composed feed content, browsed, never delta-synced.
  - **Delta** returns `{ created, updated, deleted }` per table plus an authoritative `server_time`
    the client stores verbatim as its next cursor. A row appears in exactly one of created/updated.
    Absent `since` → the full scoped set in `created` (first sync). The body is the payload
    directly, **not** a `data` envelope. (offline-sync-core reads it with `client.get`, not
    `getRaw` — `syncEngine.ts:70`. `get` unwraps `data` only when present and otherwise returns
    the body, so an unwrapped delta deserializes either way.)
  - **`SyncSerializer`** emits a flat row from an explicit per-table column allowlist — integer `id`
    and integer FKs preserved (the client keys them as `server_id`), JSON columns as arrays,
    timestamps ISO8601. Never the API-Resource shape: no `can`, no nested relations, no column
    outside the allowlist.
  - **Push** resolves every row by its **client-generated uuid**, then upserts. That is the
    "create offline → reconnect → no duplicates" guarantee: replaying a push is a no-op. Each row
    runs the *existing* resource FormRequest rules and writes through `CrudService`, with `user_id`
    (or `follower_id`) forced to the caller regardless of what the row claims. Operations are
    independent — a rejected row returns `{ status: "rejected", reason, errors }` in
    `data.results` while its siblings still apply; nothing 4xx's the batch. Each result carries the
    server's canonical `record` so the client can reconcile computed fields (slug, status,
    counters, server id).
  - **Tombstones** (`sto_sync_tombstones` + `SyncTombstoneObserver`) give deletes a delta
    representation, uniformly across hard deletes (follows, wishlist items) and soft deletes
    (spots, reviews, cities) — a delete on one device reaches another device's next pull. A follow
    records a tombstone for **both** parties; a city's is global (`user_id` null).
  - Bruno folder `11-sync` covering both endpoints, including the run-it-twice idempotency check.
  - 17 feature tests: delta scope and cursor, the created/updated split, row shape, tombstones
    (both-parties follow included), push create + idempotency, per-op validation and authorization
    rejection, update-by-uuid, delete idempotency, cross-device delete, unauthenticated rejection,
    and cities being unpushable.

### Notes

- **`delta()` is deliberately not cached.** Every other read endpoint here uses `getCachedList()`
  because many viewers issue the identical request; a delta is keyed by caller *and* an
  ever-advancing cursor, so an entry would be used at most once. Same exception the feed documents.
- **Tier-2 conflict merge is deferred to M2c.** MVP conflict resolution is server-authoritative
  last-write-wins on scalars through `CrudService`. The user-visible merge for genuinely contended
  shared records (spec §8 — e.g. one spot's hours edited by two contributors) ships with the Sync
  Status UI; owned single-user data rarely contends.
- **Deferred cross-field checks on push.** Push validates with each FormRequest's `rules()` through
  a manual `Validator`, which does not run their `withValidator()` hooks — the duplicate-review and
  duplicate-wishlist-save guards. A re-add still fails, but as the table's unique constraint caught
  per-row and returned as `reason: "error"` rather than `reason: "validation"` with a field message.
  Correct and isolated, just a coarser error shape than a single request gets; worth lifting into
  the registry if the mobile client needs to distinguish them. (Self-follow and a missing target are
  checked explicitly in `pushFollow`, so those do come back as validation.)

## [0.9.0] - 2026-07-25

### Added

- **Explorer onboarding — a registered user becomes an explorer of the `Stourify Public`
  organization.** Stourify is a single-org consumer app: all content lives in one organization,
  and `SetOrganizationFromHeader` requires the caller to be a *member* of the org whose UUID they
  send (permissions alone are not enough). So without this a registered user could do nothing at
  all. Three parts:
  - An **`explorer` role** (`StourifyModule::roles()`, org-scoped, inheriting `user`) granting the
    consumer permission set — the `stourify.*` view/create/update/delete permissions plus the
    *discovered* reaction permissions on the Post and Review hosts (`posts.reactions.*`,
    `reviews.reactions.*`), so an explorer can like a post and mark a review helpful. Moderator
    abilities (`.manage`, `cities.manage`, `reports.manage`) are deliberately excluded.
  - A **`JoinPublicOrganizationAsExplorer` listener** on `UserRegistered` (dispatched only for the
    `registration` source, by both the web and mobile register endpoints) that enrols the new user
    into the public org with the `explorer` role and points their `current_organization_id` at it.
    Idempotent, and a no-op if the public org is not provisioned yet.
  - A **`StourifyExplorerBackfillSeeder`** that enrols pre-existing accounts (the platform's seeded
    users, anyone who registered before this shipped) the same way, so a seeded login can exercise
    the app immediately. Runs after the public-org seeder and shares its one enrolment path.
- Verified against the real seed chain: the `explorer` role ends up with 34 permissions including
  the discovered reaction ones, and every seeded user is enrolled. 7 feature tests, including a
  freshly-registered explorer creating a spot and liking a post end to end, and a non-member being
  refused by the organization middleware.

### Notes

- Deployment: this project sets `ORG_AUTO_CREATE_PERSONAL=false` — explorers belong only to the
  public org, not a personal one (business claims become real organizations post-beta).

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
