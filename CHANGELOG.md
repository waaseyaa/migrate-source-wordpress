# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial implementation of `waaseyaa/migrate-source-wordpress` on `main`. Consumers pin `dev-main` until the Waaseyaa framework exits the alpha cycle and the package cuts its first tagged release.
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
