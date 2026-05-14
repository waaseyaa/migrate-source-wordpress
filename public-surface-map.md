# Public Surface Map — `waaseyaa/migrate-source-wordpress`

This document enumerates the package's stable public surface per the Waaseyaa stability charter §5.8 (extension-author obligations).

`present: true` means the symbol exists in the current package version. WP10 finalised this file when v0.1.0 shipped.

## Stable surface (per [spec §4](https://github.com/waaseyaa/framework/blob/main/kitty-specs/waaseyaa-migrate-source-wordpress-01KRCDEG/spec.md#4-stable-surface-deliverables))

### Service provider

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\ServiceProvider` | Service provider class | true | WP01 |

### WXR parser

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader` | Concrete class | true | WP02 |
| `Waaseyaa\Migrate\Source\WordPress\Wxr\WxrVersion` | Enum | true | WP02 |

### Source plugins

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource` | Source plugin | true | WP03 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource` | Source plugin | true | WP04 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource` | Source plugin | true | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource` | Source plugin | true | WP06 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressCommentSource` | Source plugin | true | WP07 |

### Process plugins

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip` | Process plugin | true | WP08 |
| `Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand` | Process plugin | true | WP08 |
| `Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl` | Process plugin | true | WP08 |
| `Waaseyaa\Migrate\Source\WordPress\Process\OembedFetcherInterface` | Pluggable HTTP fetcher | true | WP08 |

### Media copy

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Media\MediaCopier` | Concrete class | true | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Media\MediaCopyResult` | DTO | true | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Media\MediaCopyOperation` | Enum | true | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Media\MediaFetcherInterface` | Pluggable HTTP fetcher | true | WP05 |

### Default migrations (factory classes)

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts` | MigrationDefinition factory | true | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy` | MigrationDefinition factory | true | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities` | MigrationDefinition factory | true | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles` | MigrationDefinition factory (example) | true | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpCommentsToEngagement` | MigrationDefinition factory | true | WP09 |

### Exceptions

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException` | Exception | true | WP02 |
| `Waaseyaa\Migrate\Source\WordPress\Exception\WordPressMediaCopyException` | Exception | true | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Exception\WordPressOembedResolutionException` | Exception | true | WP08 |

## Stable error codes

Per FR-045, each exception class declares string constants for its error codes. Tools and log analyzers index on the code constants, not on exception class FQCNs.

### `WxrParseException` (WP02)

| Constant | Value |
|---|---|
| `CODE_UNSUPPORTED_VERSION` | `wxr.unsupported_version` |
| `CODE_RECORD_PARSE_FAILURE` | `wxr.record_parse_failure` |
| `CODE_FILE_NOT_FOUND` | `wxr.file_not_found` |
| `CODE_FILE_NOT_READABLE` | `wxr.file_not_readable` |

### `WordPressMediaCopyException` (WP05)

| Constant | Value |
|---|---|
| `CODE_SOURCE_NOT_FOUND` | `wp_media.source_not_found` |
| `CODE_TARGET_WRITE_FAILED` | `wp_media.target_write_failed` |
| `CODE_HTTP_FETCH_FAILED` | `wp_media.http_fetch_failed` |
| `CODE_HTTP_FETCHER_MISSING` | `wp_media.http_fetcher_missing` |
| `CODE_HASH_MISMATCH` | `wp_media.hash_mismatch` |

### `WordPressOembedResolutionException` (WP08)

| Constant | Value |
|---|---|
| `CODE_PROVIDER_UNSUPPORTED` | `wp_oembed.provider_unsupported` |
| `CODE_HTTP_FAILURE` | `wp_oembed.http_failure` |
| `CODE_INVALID_RESPONSE` | `wp_oembed.invalid_response` |

### Source-type constants

Each source plugin pins its `SourceId::$sourceType` value as a class constant so consumers can construct `LookupProcessor` references without re-typing the literal:

| Plugin | Constant | Value |
|---|---|---|
| `WordPressUserSource` | `SOURCE_TYPE` | `wp_user` |
| `WordPressTaxonomySource` | `SOURCE_TYPE` | `wp_term` |
| `WordPressMediaSource` | `SOURCE_TYPE` | `wp_media` |
| `WordPressPostSource` | `SOURCE_TYPE` | `wp_post` |
| `WordPressCommentSource` | `SOURCE_TYPE` | `wp_comment` |

### Migration id constants

| Factory | `MIGRATION_ID` |
|---|---|
| `WpUsersToAccounts` | `wp_users_to_accounts` |
| `WpTermsToTaxonomy` | `wp_terms_to_taxonomy` |
| `WpMediaToEntities` | `wp_media_to_entities` |
| `WpPostsToArticles` | `wp_posts_to_articles` |
| `WpCommentsToEngagement` | `wp_comments_to_engagement` |

## Versioning

Semantic versioning. Pre-1.0 minor bumps may include breaking changes; from v1.0 onward, breaking changes require a major bump and a corresponding upgrade-guide entry under `docs/upgrades/`.
