# Stourify Core-Loop API Collection (Bruno)

A [Bruno](https://www.usebruno.com/) collection that exercises the Stourify core loop end to end
against a running server — the M1 milestone's "collection exercises the full core loop against a
seeded DB" gate.

Bruno stores each request as a plain-text `.bru` file, so this collection is version-controlled
with the module and diffs cleanly in review — unlike a Postman JSON blob.

## Setup

1. **Run the server** (dev): `php artisan serve` in `saas-boilerplate/` (default
   `http://127.0.0.1:8001`, matching `SERVER_PORT`).
2. **Seed a login**: `php artisan db:seed --class=E2eSeeder` provides
   `owner@example.com` / `password`.
3. **Open the collection**: point Bruno at `modules/Stourify/bruno/` and select the `local`
   environment. Adjust `base_url`, `email`, `password` there if needed.

## Run order

The folders are numbered; run them in order — earlier requests capture variables that later ones
use (`token`, `org_uuid`, `spot_uuid`, `post_uuid`, `review_uuid`).

| # | Folder | What it exercises |
|---|--------|-------------------|
| 00 | auth | Mobile token login; resolve the organization UUID sent on every request |
| 01 | profile | Upsert the caller's profile; read it back with computed counts |
| 02 | spots | Create a spot; nearby discovery; show |
| 03 | posts | Create + publish a post; the ranked home feed |
| 04 | reactions | Like a post (the shared reaction endpoint, not a Stourify route) |
| 05 | reviews | Write a review; mark a review helpful |
| 06 | follows | Follow; pending requests; accept |
| 07 | wishlist | Save a spot |
| 08 | discover | Search spots / cities / people |
| 09 | reports | File a report |
| 10 | media | Presigned upload URL + attach (boilerplate feature) |

Every request after `00-auth` sends `Authorization: Bearer {{token}}` and
`X-Organization-Id: {{org_uuid}}`.

## Notes

- **Follows** need a second account: set `followee_uuid` to another seeded user's UUID (read one
  from `GET /api/v1/profiles/{user}`), and run `06/03-accept` while authenticated as that user.
- **Likes and helpful votes** are the platform's reaction subsystem (`POST /api/v1/reactions`),
  not Stourify routes — a post accepts only `like`, a review only `helpful`.
- **Media (folder 10)** addresses the host by `model_uuid`, consistent with reactions and reports.
  Requires `MEDIA_DISK=spaces` with credentials configured; between the two steps the client PUTs
  the file bytes to the presigned URL directly (not through the app server).
- Reacting requires the discovered `posts.reactions.create` / `reviews.reactions.create`
  permissions granted to the explorer's role at seed time.
