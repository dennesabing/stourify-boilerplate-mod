# Changelog

All notable changes to the Stourify module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-07-23

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
