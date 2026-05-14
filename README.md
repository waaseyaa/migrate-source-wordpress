# waaseyaa/migrate-source-wordpress

> Migrate your WordPress site to Waaseyaa.

First-party WordPress source reader for the [Waaseyaa migration platform](https://github.com/waaseyaa/framework). Imports a WordPress site — posts, pages, users, taxonomies, attachments, comments — from a WXR (WordPress eXtended RSS) export into a Waaseyaa-powered application.

## 30-second quick start

```bash
composer require waaseyaa/migrate-source-wordpress
```

In your application's migration wiring:

```php
use Waaseyaa\Migrate\Source\WordPress\Migration\WpUsersToAccounts;
use Waaseyaa\Migrate\Source\WordPress\Migration\WpPostsToArticles;
use Waaseyaa\Migrate\Source\WordPress\Wxr\WxrReader;

$reader = new WxrReader('/path/to/wp-export.xml');

return [
    (new WpUsersToAccounts($reader, $yourAccountDestination))->definition(),
    (new WpPostsToArticles($reader, $yourArticleDestination))->definition(),
    // …users → terms → media → posts → comments, per FR-024
];
```

Then:

```bash
bin/waaseyaa import:run-all
```

The runner walks the dependency chain — users → terms → media → posts → comments — and writes idempotently to your destination. See the [operator guide](docs/migrating-from-wordpress.md) for the full step-by-step.

## What ships

- **`WxrReader`** — streaming WXR XML parser (WXR 1.0 / 1.1 / 1.2) with skip-with-warning recovery.
- **Five source plugins**: `WordPressUserSource`, `WordPressTaxonomySource`, `WordPressMediaSource`, `WordPressPostSource`, `WordPressCommentSource`.
- **Three process plugins**: `WordPressShortcodeStrip` (with custom rewriter hooks), `WordPressOembedExpand` (opt-in remote resolution for YouTube/Vimeo/Twitter/Instagram), `WordPressMediaRewriteUrl` (CDN host allowlist).
- **Five default migration factories**: `WpUsersToAccounts` (discards WP passwords + forces reset), `WpTermsToTaxonomy`, `WpMediaToEntities`, `WpPostsToArticles` (renameable example), `WpCommentsToEngagement`.
- **`MediaCopier`** — idempotent local + HTTP media copy primitive with sha256 verification.

Full inventory: [`public-surface-map.md`](public-surface-map.md).

## Compatibility

| `waaseyaa/migrate-source-wordpress` | `waaseyaa/migration` substrate | PHP |
|---|---|---|
| `0.1.x` | `^0.1.0-alpha.179` | `>= 8.5` |

## Verifying a clean install

```bash
docker run --rm -it php:8.5-cli bash
apt-get update && apt-get install -y git unzip libxml2-dev
docker-php-ext-install xml xmlreader
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
mkdir /tmp/smoke && cd /tmp/smoke
composer init -q --name=test/smoke --no-interaction
composer require waaseyaa/migrate-source-wordpress
php -r "require 'vendor/autoload.php'; echo class_exists('Waaseyaa\\\\Migrate\\\\Source\\\\WordPress\\\\Wxr\\\\WxrReader') ? 'OK' : 'FAIL';"
```

## Documentation

- **[Operator guide](docs/migrating-from-wordpress.md)** — for WordPress site owners migrating their content.
- **[Customization guide](docs/customization.md)** — for developers wiring the reader into a Waaseyaa app.
- **[Public surface map](public-surface-map.md)** — every stable symbol the package ships, plus error-code constants.
- **[CHANGELOG](CHANGELOG.md)**.

## Mission

This package implements [M-005](https://github.com/waaseyaa/framework/blob/main/kitty-specs/waaseyaa-migrate-source-wordpress-01KRCDEG/spec.md) of the Waaseyaa framework, the first first-party source reader for the migration substrate ([ADR 012a](https://github.com/waaseyaa/framework/blob/main/docs/adr/012a-migration-substrate-in-core.md)).

## License

GPL-2.0-or-later.
