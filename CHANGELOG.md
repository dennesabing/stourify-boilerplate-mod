# Changelog

All notable changes to the Stourify module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Spots read from the cache come back as spots again, instead of 500-ing the request**
  (STOURIFY-216).

  A cache is a coat check: you hand an object over and later a ticket brings it back. PHP adds a
  rule to that — it rebuilds a stored object only if the object's class is on a guest list, so that
  whoever can write into the cache cannot name any of the several hundred classes in `vendor/` and
  have PHP run that class's start-up code. The platform builds that guest list by asking every
  switched-on module for its own names. **This module never answered.**

  So ten of its models were being written into the cache and refused on the way out. Refusal is
  silent — PHP does not throw, it hands back an object-shaped hole that survives every `try`/`catch`
  and only explodes later, somewhere else, on the first property read. `GET /api/v1/spots` answered
  its very first request from the database and returned a 500 on every request after it, on any tier
  with a real cache store. The module now publishes `Block`, `City`, `ExplorerProfile`, `Follow`,
  `Post`, `Report`, `Review`, `Spot`, `SpotAbout` and `WishlistItem` through
  `StourifyModule::serializableCacheClasses()`. `SyncTombstone` is deliberately left out: it is
  write-once and read by cursor, so nothing ever caches one.

  It also un-blocks every backend card. The platform's drift guard,
  `Tests\Feature\Cache\SerializableClassCoverageTest`, had been failing on `master` for exactly this
  reason, which meant the backend gate was red no matter what a card changed.

### Added

- **A test that keeps the published list honest as models are added** (STOURIFY-216).

  `tests/Feature/SerializableCacheClassesTest.php` scans this module for classes that use the
  `Cacheable` trait and fails in both directions — a cacheable model missing from the published
  list, and a name on the list that no longer earns its place. The platform has its own version of
  this check and it works; this one exists because it lives in the repository the person adding the
  eleventh model is actually looking at.

## [0.12.1] - 2026-08-26

### Fixed

- **A spot's photo now actually reaches the phone** (STOURIFY-208).

  Spot rows were taught to carry a photo to the device in 0.12.0, and the photo never arrived. The
  offline sync only sends down a row whose "last changed" time has moved, and **attaching a photo
  did not change the spot** — photos live in a separate table.

  That is the exact sequence every spot in this app goes through: the spot is written, the phone
  fetches it (correctly, with no photo yet), and the photos upload a second or two later without
  moving the timestamp. The row was then never sent again — not late, never, because there was no
  future moment at which it became newer than what the phone had already asked for.

  A spot's photos changing now counts as the spot changing. It listens for three things rather than
  one: the upload, the thumbnail finishing, and a photo being removed. The middle one is the one
  that matters — the thumbnail is generated after the upload, so a fix that fired only on upload
  would have shipped the full-size original once and never corrected it.

## [0.12.0] - 2026-08-26

### Added

- **The offline sync now carries a spot's photo down to the phone** (STOURIFY-192).

  A spot's photos live in a separate table from the spot itself, and the sync speaks in flat rows
  of columns — so the phone received every fact about a spot except what it looks like. The app's
  own "My spots" list could only ever draw grey rectangles, which reads as a broken image rather
  than as a missing feature.

  Spot rows in the sync now carry `cover_photo_url`, preferring the thumbnail over the full image.
  A list draws a 96-pixel square; the originals run to megabytes each, so a list of twenty would
  have pulled tens of megabytes over a phone connection to show a column of thumbnails.

- **The spot list can be narrowed to one category** (STOURIFY-193).

  `GET /spots` now accepts a `category` parameter and returns only spots filed under it. A spot in
  several categories answers to any of them.

  It is deliberately not restricted to a fixed list of categories. Spots are created with free-text
  categories, so a list here would reject values the same server accepted when the spot was written.
  The app owns the vocabulary; this accepts what the app produces.

  The filter runs on a query already narrowed to what the caller may see, so it can only narrow
  further — filtering by category cannot surface another explorer's draft.

### Fixed

- **The search controller no longer claims production runs Meilisearch** (STOURIFY-204).

  Its documentation said "Meilisearch in production, the collection driver in tests". Production has
  never run Meilisearch — no such process, nothing on its port, no setting in the environment. The
  sentence described an arrangement that was never set up, which is how someone loses an afternoon
  debugging an index that is not there.

  Every tier runs the collection driver, and that is now a recorded decision rather than an
  accident, with the trade-off and the conditions for revisiting it written down.

- **Building a photo's web address no longer fetches its owner back from the database.**

  Storage paths here are organisation-scoped and built from whatever the photo hangs off, so asking
  a photo for its address quietly loaded that record again — one query per photo, and each record
  loaded that way pulled its own contributor profile, making it two. It went unnoticed because it
  only shows up where many photos are handled at once, which until now was nowhere.

  A sync of ten spots with photos cost 33 queries and now costs 13, and the count no longer grows
  with the number of spots.

  Note this was only ever *reached* by the change above; the cause is older and lives in the
  boilerplate's path generator. Other places that build many photo addresses at once may still pay
  it — see the follow-up card.

## [0.11.1] - 2026-08-25

### Fixed

- **A contributor who hides the location of their spots is now actually hidden** (STOURIFY-185).

  `shows_location_on_spots` was a curtain rail with no curtain. The runners slid — the column was
  accepted by the API, cast on the model, returned to its owner and copied to every device — and
  **nothing read it**, so every caller received exact coordinates regardless.

  A spot's position now disappears from every response a non-owner can reach. The keys are
  **absent**, not blurred and not null: rounding to a coarse grid is a lie the client cannot detect,
  because the response still looks like a position, so the app draws a pin somewhere plausible and
  wrong while the user believes it is hidden.

  The half that is easy to miss: a hidden spot **leaves the nearby result entirely**, rather than
  merely losing its `distance_km`. Membership of a radius result *is* a position — a row that
  answers "is this spot within 2 km of here?" gives up the same fact in three requests instead of
  one. The consequence is real and deliberate: hiding your location makes your spots undiscoverable
  by proximity. They still appear in Discover, in search, on your profile and by direct link.

  A contributor always sees their own coordinates, and so does a moderator — a report about a spot is
  frequently a report about where it is. A contributor with no profile row at all still shows
  location, because the flag defaults to on and treating absence as "hidden" would have stripped most
  of the catalogue.

  Nothing user-visible changes yet: the flag defaults to on and there is still no control for it.
  The toggle is STOURIFY-186 and is deliberately held until STOURIFY-187 closes the last leak — the
  offline sync delta still ships coordinates to every device, and cannot stop until the app's local
  database accepts a spot with no position.


## [0.11.0] - 2026-08-25

### Added

- **A hashtag an administrator has taken down stops being a way in** (STOURIFY-174). Five surfaces
  present a hashtag to a reader, and each now skips a suppressed one: the lookup answers `404`, the
  word leaves `?type=tags` search and the combined preview, `?tag=` on the post and spot listings
  returns nothing, and it is no longer listed among a record's tags.

  **The posts and spots carrying it are completely untouched** — still published, still in every
  listing they were in, still reportable. Suppressing a word is a judgement about the word, not about
  everybody who ever used it, and a post is still moderated as a post. `HashtagSuppressionTest`
  asserts that directly rather than leaving it to be inferred, because a suite that only checked the
  word had vanished would pass just as happily over a change that took a dozen posts out of the feed
  with it.

  The lookup answering a plain `404` rather than a new state is deliberate: from where the reader
  stands, a word nobody typed and a word that has been taken down are the same fact — there is nothing
  here to open — and the app already has a correct, tested sentence for it.

  The flag and the administrator's control live in `saas-boilerplate`, which owns the `tags` table.

### Added

- **Hashtags are now a way in, not just a thing you typed** (STOURIFY-172). The previous card made
  the words real; this one makes them findable. Three things become possible:

  - `GET /api/v1/posts?tag=streetfood` and `GET /api/v1/spots?tag=viewpoint` return one hashtag's
    content.
  - `GET /api/v1/discover/tags/{slug}` looks a hashtag up by the word itself, answering `404` when
    no such word exists.
  - `GET /api/v1/discover/search?q=street&type=tags` returns hashtags instead of the `422` it used
    to. That is the whole of STOURIFY-25, which asked whether tag search was a product requirement
    at all; it is, and `Tag` already carried the searchable projection that card wondered about.

  **The filters are conditions on the existing listings, not endpoints of their own**, and that is
  the design rather than a shortcut. Both listings already run their query through a `visibleTo()`
  that hides other people's drafts, other people's unpublished work and anybody the viewer has
  blocked. A separate tag endpoint would have to re-derive all of that, and the day the two copies
  disagree the tag listing is the one that leaks. As one more `where` on a query that has already
  been scoped, a tag listing physically cannot be more permissive than the ordinary one.

  **The lookup exists so a tag page can say three things instead of two.** A listing alone answers
  both *no such tag* and *a tag with nothing on it yet* with an empty array — and an app that treats
  a failed request as "no results" answers a third situation the same way. STOURIFY-85 to
  STOURIFY-90 are a cluster of cards about exactly that confusion. The `404` is what makes the
  distinction available to a client at all.

  Everything user-facing matches on `type = 'hashtag'`. The `tags` table is shared with the admin
  panel's own tag manager, whose labels are internal, and two tests fail on purpose if that
  condition is removed from either the filter or the search.

- **A hashtag typed in a caption is now a real, shared tag** (STOURIFY-171). Write
  `great noodles #streetfood` on a post, or put `#viewpoint` in a spot's description, and the word
  becomes a row in the platform's `tags` table that everything else mentioning it points at too.
  `#Food` and `#food` are one tag; `#café` and `#cafe` are two.

  The comparison is the subject catalogue in a library. The book does not carry a copy of the
  subject — there is one card in the drawer for "street food", and every book about it is listed on
  that one card.

  Nothing browses by tag yet; that is STOURIFY-172. What is true now is that the tags exist, they
  are shared, and they stay in step with the text: edit a caption and the tags follow it.

  **The parse hangs off the model's write, not a controller, and that is the whole design.** A spot
  written with no signal never reaches `SpotApiController` — the app sends it later through
  `POST /api/v1/stourify/sync/push`. Code in the controller would have tagged spots created online,
  skipped spots created in a tunnel, and reported nothing either way. New:
  `Support/Hashtags/HashtagParser` (a pure function of a string, with every rule as a test),
  `Support/Hashtags/HashtagSynchronizer`, `Support/Hashtags/RendersTags` and
  `Observers/HashtagObserver`, registered on `Post` and `Spot`.

  Two smaller decisions worth knowing before changing anything here. Tags are minted with a plain
  `create()` rather than through `CrudService`, because `CrudService` authorises `tags.create` — an
  organisation-admin permission no ordinary explorer holds — and enforcing it would make hashtags
  silently vanish for every normal user; `SyncTombstoneObserver` documents the same exception for
  the same kind of side-effect write. And attaching computes a difference rather than calling
  `sync()`, so a tag an administrator attached from the admin panel is not destroyed by an author
  fixing a typo.

  `PostResource` and `SpotResource` gain a `tags` array, eager-loaded on every read path that
  renders them, with a test pinning that five times the rows does not cost more queries.

- **Line endings are settled here now, so a formatter stops rewriting the whole module**
  (STOURIFY-167): new `.gitattributes` carrying `* text=auto eol=lf`.

  Git can store a file one way and hand it to you another. With `core.autocrlf=true` — the normal
  default on Windows, which this project is developed on — it stores Unix line endings and writes
  Windows ones into your folder. Invisible in an editor, invisible in a diff, and visible to every
  tool that reads the file: Laravel Pint reported **72 of this module's 144 PHP files** as needing
  changes on a tree nobody had touched, because it was proposing to undo a conversion git had just
  done and would redo at the next checkout. A five-file bug fix nearly landed as a whole-module
  reformat inside its own pull request.

  This is the same line `saas-boilerplate/.gitattributes` has always carried, which is why the
  boilerplate never had the problem. **No file's stored content changed** — all 176 tracked files
  were already stored with Unix endings, so this is one new file rather than 176 rewritten ones.
  With it in place, pointing pint at the entire module reports `passed` with zero findings: the
  other rules that appeared to disagree were all knock-on effects of the line endings, and the
  module was already written in exactly the style pint wants.

- **`POST /posts` recognises a retry instead of making a second post** (STOURIFY-166).

  The post's id is decided here, on the server, so a client only learns it from the reply. If that
  reply is lost — a dropped radio, the app killed — the client cannot tell whether the post was made,
  so it has to try again, and the second attempt used to create another post. Posting a letter and
  only writing the tracking number down once the receipt arrives: drop the receipt and you post it
  twice.

  The endpoint now takes an optional `idempotency_key`, a name the caller puts on the request. A
  request carrying a key already seen from that author gets back the post the first attempt made,
  with `200` instead of `201`, and nothing new is created. It is the same mechanism
  `POST /media/attach` already uses for photos, applied to the post itself.

  Two details worth knowing before changing it. The guarantee is held by a unique index on
  `(user_id, idempotency_key)`, not by the lookup — two retries arriving at once both read nothing
  and both insert, and the loser reads the winner's post. And the lookup deliberately sees
  soft-deleted posts, because a deleted post keeps its key and the index keeps covering it; a lookup
  that skipped them would turn a retry into a `500`.

  New migration `2026_08_23_000001_add_idempotency_key_to_sto_posts_table.php`. Callers that send no
  key are completely unaffected, and posts that already exist simply have none.

- **A test that asks whether a real explorer can comment, rather than whether a permission works**
  (STOURIFY-154). `tests/Feature/ExplorerPostCommentPermissionTest.php` builds its users from the
  `explorer` role exactly as `StourifyModule` publishes it and never names a permission itself.

  This matters more than a fourth test file usually would. Every other comment test in this module
  hands its users a written-out list — `posts.comments.view`, `posts.comments.create` — and then
  checks the controller and the policy behave correctly for somebody holding them. Those tests are
  right, and no arrangement of them could ever have noticed that the only role real users have held
  neither name. It is the difference between checking that a key turns a lock and checking that
  anybody was ever given the key. The suite stayed green for months while every real comment was
  refused.

- **Spot About — you can now reply to somebody's note instead of pinning a second one beside it**
  (STOURIFY-146). Each About entry gets its own comment thread:
  `GET|POST /api/v1/spot-abouts/{about}/comments`, addressed by the entry's UUID, and a
  `comments_count` on the entry's list and show responses.

  New: `SpotAboutCommentApiController`, `SpotAboutCommentStoreRequest`, the two routes, and the
  `spot_abouts.comments.view` / `spot_abouts.comments.create` grants on the `explorer` role. The
  comments themselves are the platform's — the same shared table, service and policy every other
  commentable model uses. Removing one needs no route here: the platform's
  `DELETE /api/v1/comments/{uuid}` is already addressed by a UUID, so there is nothing to translate.

  Two things a reader should not have to reverse-engineer:

  - **Why there is a controller at all.** The platform's generic comment surface wants the row's
    numeric database id, and no Stourify response contains one — this module addresses everything by
    UUID. So this is an adapter: it takes the UUID the client has and hands the platform the id
    underneath. `PostCommentApiController` is the same adapter for posts, for the same reason.
  - **Why `SpotAbout` overrides the `comments()` relation the `HasComments` trait already gives it.**
    A comment row records what it is attached to as text, and there are two ways to spell this model
    there: the short alias `stourify_spot_about` or the full class name. The write path can only use
    the class name, because `CommentCrudService` calls a static method on that string as-is and the
    alias throws `Class "stourify_spot_about" not found` — that is the pre-existing boilerplate
    defect STOURIFY-12, and a module must not patch `saas-boilerplate` around it. But the trait's
    relation looks for the alias. Written one way and read the other, `withCount('comments')` would
    return **zero forever with nothing failing to say so**. The override makes the relation look for
    what this module actually writes, and its docblock says to delete it once STOURIFY-12 lands and
    the existing rows are migrated. Full write-up in `specs/2026-08-22-spot-about-design.md` §5.6.

- **Spot About — visitors can write their own notes about a spot, and vote the useful ones up**
  (STOURIFY-145). A spot has always had exactly one piece of descriptive text, written by whoever
  created it. That is the brass plaque beside a landmark: one author, one text. This adds the
  corkboard next to it — anybody who has been to a spot can pin up what they know, each note carries
  who wrote it and when, and the list orders itself so the notes other visitors endorsed sit at the
  top.

  New: the `sto_spot_abouts` table, `Models\SpotAbout`, `SpotAboutPolicy`, the
  `stourify.spot_abouts.*` permissions, and `GET|POST /api/v1/spot-abouts` plus
  `GET|PUT|PATCH|DELETE /api/v1/spot-abouts/{about}`. The list takes `spot_uuid` (required),
  `per_page`, `page`, `sort` and `direction`, exactly like the module's other index endpoints.

  **Liking one needed no new endpoint at all**, and that is the part worth knowing. The platform
  already keeps every reaction in one shared table addressed by a short type name plus a UUID, so an
  entry became likeable the moment the model picked up `HasReactions` and its alias
  `stourify_spot_about` went into the morph map. `POST|DELETE /api/v1/reactions` has worked on it
  ever since. The same is true of comments, which the sibling card STOURIFY-146 exposes.

  Two decisions inside it that a reader should not have to reverse-engineer:

  - **An entry accepts a `like` and nothing else.** `supportedReactions()` narrows the platform's
    six-type default to one, the way `Post` and `Review` already narrow it. With six types there is
    no single "number of likes" to sort a list by — only an unmade decision about which ones count.
  - **The sort is `likes_count DESC, created_at DESC, id DESC`, and the last key is not decoration.**
    `likes_count` is not unique, and paging through a list ordered by a non-unique key is unstable:
    two rows that tie can come back in a different order on each query, so one shows up on page 1
    *and* page 2 while another shows up on neither. `id` is unique, so appending it makes the
    ordering total and the paging deterministic.

  `likes_count` is a stored column rather than a `COUNT()` computed per request, for the reason the
  module already stores `sto_posts.likes_count` and `sto_reviews.helpful_count`: an aggregate on
  every page of every request gets slower exactly as a spot gets popular. `ReactionCountObserver`
  gained a third arm and remains the column's only writer — the field is deliberately not fillable,
  so a wrong value can only have come from one place.

  Full argument, including the four alternatives that lost: `specs/2026-08-22-spot-about-design.md`
  in the root repo.

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

- **A comment thread on a post or an About entry asked the database two extra questions per
  comment** (STOURIFY-153). Both comment controllers now eager-load `commentable` and
  `visibilityRules` alongside the `parent` that STOURIFY-152 added, inside the cached closure so the
  relations are stored with the paginator rather than fetched only on a cache miss. The fix itself
  is in `saas-boilerplate`'s `CommentResource`/`CommentPolicy` pair; these two adapters are the
  read paths that have to load what it reads. Measured: a seven-comment About thread went from 29
  queries to 16, flat as the thread grows.

  Each endpoint gains two guards — the whole request under `Model::preventLazyLoading()`, and a
  count assertion comparing a long thread against a short one — both acting as an ordinary
  commenter, because `AttachablePolicy::view()` returns early for an override role and a guard
  written under an admin's identity never reaches the reads it exists to catch.

- **Nobody could comment on a post: the `explorer` role held no `posts.comments.*` permission**
  (STOURIFY-154). Every ordinary user who typed a comment on a post and pressed send got
  **403** — `Authorization failed for 'create' operation on [Comment]` — from a screen that offered
  a composer regardless. A shop with a counter, an assistant and a till, and no licence to sell
  anything.

  The permission itself was never the problem. `AttachablePolicy` builds a comment permission out of
  the thing being commented on — `{host prefix}.comments.{verb}`, so `posts.comments.create` — and
  `PermissionDiscoveryService` mints exactly that row from `Post`'s `HasComments` trait, and
  `permissions:sync` writes it. `StourifyModule::EXPLORER_PERMISSIONS` simply never listed it. The
  lock existed, the key existed, and nobody was given the key. `posts.comments.view` and
  `posts.comments.create` are now granted to `explorer`.

  This is the same defect STOURIFY-22 fixed for media one attachable over, and the grant is
  deliberately the same narrow pair. `update` and `delete` are **not** granted: `CommentPolicy`
  already lets people edit and remove their own comment through its ownership rule, so those two
  names would buy nothing except reach over *other people's* replies, which is a moderator's ability.

  **Existing databases need no backfill.** `deploy.sh` already runs `permissions:sync` and then
  `RolesAndPermissionsSeeder`, and that seeder re-syncs each role's permissions from the module
  definition in full and then copies them down to each user's own grants. Deploying is all this takes.

- **A post's comment thread was readable by anyone who could see the post** (STOURIFY-154).
  `PostCommentApiController::index` checked only that you could view the post, so the endpoint
  returning fifteen comments asked *less* than `CommentPolicy` asks for a single one of them, and
  `posts.comments.view` was a permission no read path ever consulted. It now also authorizes
  `viewAny` on comments, which is what `SpotAboutCommentApiController::index` has always done — the
  card asked for the asymmetry to be settled, and the stricter of the two is the one with an argument
  behind it. No caller loses access: the explorer role is granted the permission in the same change,
  and the override tier passes before any permission is read.

- **A reply on a post's or an About entry's thread never appeared in the app** (STOURIFY-152). The
  cause is in `saas-boilerplate` and is fixed there; what changed here is the door it comes through.
  `PostCommentStoreRequest` and `SpotAboutCommentStoreRequest` now take `parent_id` as the parent
  comment's **UUID** rather than its numeric database id — still scoped to the same host, with the
  same message when a parent is borrowed from another thread — and both controllers eager-load
  `parent:id,uuid` so reading the parent's UUID does not cost a query per comment.

  **Breaking for any client that sends `parent_id` as an integer.** No response from these
  endpoints has ever carried a numeric comment id, so no client could have obtained one; that was
  the bug.

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
