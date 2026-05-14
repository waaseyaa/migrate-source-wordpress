# Public Surface Map — `waaseyaa/migrate-source-wordpress`

This document enumerates the package's stable public surface per the Waaseyaa stability charter §5.8 (extension-author obligations).

`present: true` means the symbol exists in the current package version. `present: false` means it is planned by an upcoming work package. WP10 (T081) finalizes this file when v0.1.0 ships.

## Stable surface (per [spec §4](https://github.com/waaseyaa/framework/blob/main/kitty-specs/waaseyaa-migrate-source-wordpress-01KRCDEG/spec.md#4-stable-surface-deliverables))

### Service provider

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\ServiceProvider` | Service provider class | true | WP01 |

### WXR parser

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader` | Concrete class | false | WP02 |
| `Waaseyaa\Migrate\Source\WordPress\Wxr\WxrVersion` | Enum | false | WP02 |

### Source plugins

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressUserSource` | Source plugin | false | WP03 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource` | Source plugin | false | WP04 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressMediaSource` | Source plugin | false | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressPostSource` | Source plugin | false | WP06 |
| `Waaseyaa\Migrate\Source\WordPress\Source\WordPressCommentSource` | Source plugin | false | WP07 |

### Process plugins

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Process\WordPressShortcodeStrip` | Process plugin | false | WP08 |
| `Waaseyaa\Migrate\Source\WordPress\Process\WordPressOembedExpand` | Process plugin | false | WP08 |
| `Waaseyaa\Migrate\Source\WordPress\Process\WordPressMediaRewriteUrl` | Process plugin | false | WP08 |

### Media copy

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Media\MediaCopier` | Concrete class | false | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Media\MediaCopyResult` | DTO | false | WP05 |

### Default migrations

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts` | MigrationDefinition | false | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy` | MigrationDefinition | false | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpMediaToEntities` | MigrationDefinition | false | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles` | MigrationDefinition (example) | false | WP09 |
| `Waaseyaa\Migrate\Source\WordPress\Migration\WpCommentsToEngagement` | MigrationDefinition | false | WP09 |

### Exceptions

| Symbol | Kind | Present | Source WP |
|---|---|---|---|
| `Waaseyaa\Migrate\Source\WordPress\Exception\WxrParseException` | Exception | false | WP02 |
| `Waaseyaa\Migrate\Source\WordPress\Exception\WordPressMediaCopyException` | Exception | false | WP05 |
| `Waaseyaa\Migrate\Source\WordPress\Exception\WordPressOembedResolutionException` | Exception | false | WP08 |

## Stable error codes

Error-code constants on the exception classes above. WP02/WP05/WP08 each defines its own codes; WP10 consolidates the full table here.

## Versioning

Semantic versioning. Pre-1.0 minor bumps may include breaking changes; from v1.0 onward, breaking changes require a major bump and a corresponding upgrade-guide entry under `docs/upgrades/`.
