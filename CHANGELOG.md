# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`WordPressPostSource` trash-skip default (G-021)**: records with `wp:status` === `trash` are now skipped by default. Pass `includeTrashed: true` to the constructor to opt back in to importing them.
- **`WordPressPostSource` status contract pinned (G-021)**: the `status` field was already emitted as the raw WordPress status string (`publish`, `draft`, `pending`, `private`, `future`, `trash`, ...) — this is now covered by a regression test and documented as a stable contract. Consumers that need a boolean "published" flag must add an explicit status→bool mapping step in their own process map; this source will never flatten status itself.
- **`WordPressPostSource` post_type allowlist filter (G-027)**: constructor gains `?array $postTypes = null`. When provided (a non-empty list of non-empty `post_type` strings), only matching records are emitted — enabling a per-bundle migration split (e.g. separate `WordPressPostSource` instances for `page`, `post`, and a custom `event` type feeding three distinct migrations). `null` (default) preserves the existing behavior of emitting every post type.

### Fixed

- **BREAKING: `WordPressMediaSource::derivePath()` now emits uploads-relative `file_path` values** (G-017). Previously the method preferred the site-root-relative `<wp:attachment_url>` path (e.g. `/wp-content/uploads/2024/06/foo.pdf`) over the uploads-relative `_wp_attached_file` postmeta, so joining the emitted `file_path` to the operator guide's documented `storage/imports/uploads/` layout (`docs/migrating-from-wordpress.md` Option A) produced a path that never resolved — every attachment's local file copy silently no-op'd (381/381 in the originating field report). The connector now:
  - Prefers postmeta `_wp_attached_file` (already uploads-relative per WordPress convention), returned verbatim minus any leading slash — e.g. `2024/06/foo.pdf`.
  - Falls back to `<wp:attachment_url>` only when that postmeta is absent, stripping everything through the URL's `wp-content/uploads/` segment (case-insensitive, percent-decoded, and correct for both year/month and flat upload layouts) instead of returning the full site-root path.
  - Returns `''` when neither source yields a resolvable uploads-relative path (e.g. an off-site/CDN-only `attachment_url` with no `wp-content/uploads/` segment) — consumers must treat `''` as "no file to copy."

  **Migration for existing consumers:** any destination/process plugin that previously compensated for the old shape (e.g. stripping a `wp-content/uploads/` prefix itself before joining to a local uploads root) should remove that workaround — `file_path` now composes directly with `storage/imports/uploads/<file_path>` as documented.
- `Media\MediaCopier` now logs a `warning` (with the resolved absolute `source` and `target` paths) immediately before throwing `WordPressMediaCopyException::sourceNotFound()`, so a missing/unreadable local media source is never silent even if a caller's logger is the only place the failure is observed before the exception is caught/discarded upstream.

## [0.1.0-alpha.1] - 2026-05-14

First pre-release. Cut alongside the `waaseyaa/migration ^0.1.0-alpha.179` substrate so consumers can `composer require waaseyaa/migrate-source-wordpress:^0.1.0-alpha.1` instead of pinning `dev-main`.

### Added
- **Streaming WXR parser** (`Wxr\WxrReader`) supporting WXR 1.0/1.1/1.2 with skip-with-warning recovery and a strict-mode opt-in.
- **Five source plugins** implementing M-002's `SourcePluginInterface`, each with a `SourceConformanceTestCase`-derived contract test:
  - `Source\WordPressUserSource` (FR-006)
  - `Source\WordPressTaxonomySource` (FR-009)
  - `Source\WordPressMediaSource` (FR-008)
  - `Source\WordPressPostSource` (FR-005) — single source handles posts, pages, and custom post types
  - `Source\WordPressCommentSource` (FR-007)
- **Three process plugins** implementing M-002's `ProcessPluginInterface`:
  - `Process\WordPressShortcodeStrip` (FR-013) with per-tag rewriter callbacks
  - `Process\WordPressOembedExpand` (FR-014, FR-015, FR-016) — opt-in remote resolution via `Process\OembedFetcherInterface`
  - `Process\WordPressMediaRewriteUrl` (FR-013, FR-034, FR-035) with CDN host allowlist
- **Idempotent media copy primitive**:
  - `Media\MediaCopier` (FR-026..FR-029) — local + HTTP source with size + sha256 verification, atomic temp-rename writes
  - `Media\MediaCopyResult` + `Media\MediaCopyOperation` outcome envelope
  - `Media\MediaFetcherInterface` — pluggable HTTP fetcher (no PSR-18 dep)
- **Five default `MigrationDefinition` factory classes** in `Migration\` (FR-018..FR-025) covering the canonical FR-024 chain users → terms → media → posts → comments. `WpPostsToArticles` is shipped as a renameable example.
- **Stable exception types** with string error-code constants (FR-034, FR-045):
  - `Exception\WxrParseException`
  - `Exception\WordPressMediaCopyException`
  - `Exception\WordPressOembedResolutionException`
- **Operator guide** (`docs/migrating-from-wordpress.md`) and **developer guide** (`docs/customization.md`).
- **Test fixtures**: small-site (2 users / 6 terms / 5 posts / 3 attachments / 4 comments), edge-cases/rtl-language.xml (Hebrew + Arabic + bidi marks), edge-cases/large-entries.xml (~120 KB streaming smoke), and per-source 5000-record large fixtures generated on demand for the conformance C5 memory bound.
- **Integration tests** including end-to-end import against the small-site fixture using an in-memory destination, idempotency proof, and known-value round-trip including password discard.

### Compatibility

- PHP: requires `>= 8.5`.
- Substrate: requires `waaseyaa/migration ^0.1.0-alpha.179` and `waaseyaa/foundation ^0.1.0-alpha.179`.

### Notes

- Kernel-boot end-to-end import (real `EntityDestination` + sqlite `MigrationIdMap` + `MigrationRunner`) is intentionally out-of-package — that wiring lives in the consumer's application boot. The package ships factories + plugins; consumers compose them at runtime.
- `user_login` on comment records is left as a string field; resolving the destination account UUID is operator-controlled because `WordPressUserSource` keys `SourceId` by `wp_user_id`, not by login. See [docs/customization.md](docs/customization.md).
