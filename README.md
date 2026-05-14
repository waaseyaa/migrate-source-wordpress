# waaseyaa/migrate-source-wordpress

> Migrate your WordPress site to Waaseyaa.

First-party WordPress source reader for the [Waaseyaa migration platform](https://github.com/waaseyaa/framework). Imports WordPress sites — posts, pages, users, taxonomies, attachments, comments — from a WXR (WordPress eXtended RSS) export into a Waaseyaa-powered application.

**Status:** scaffold (M-005 work in progress; first stable release v0.1.0 ships at WP10).

## Quick install

```bash
composer require waaseyaa/migrate-source-wordpress
```

## Quick usage

After installing, register the package's migrations and run:

```bash
bin/waaseyaa import:run-all
```

See the operator guide for full setup, source-path configuration, and verification — link added when [`docs/migrating-from-wordpress.md`](docs/migrating-from-wordpress.md) ships in WP10.

## Compatibility

| `waaseyaa/migrate-source-wordpress` | `waaseyaa/migration` substrate | PHP |
|---|---|---|
| `0.1.x` | `^0.1.0-alpha.179` | `>= 8.5` |

## Documentation

- [Operator guide](docs/migrating-from-wordpress.md) — for WordPress site owners. *(WP10)*
- [Customization guide](docs/customization.md) — for developers integrating the reader into a Waaseyaa app. *(WP10)*
- [Public surface map](public-surface-map.md) — stable API listing.
- [CHANGELOG](CHANGELOG.md)

## Mission

This package implements [M-005](https://github.com/waaseyaa/framework/blob/main/kitty-specs/waaseyaa-migrate-source-wordpress-01KRCDEG/spec.md) of the Waaseyaa framework, the first first-party source reader for the migration substrate ([ADR 012a](https://github.com/waaseyaa/framework/blob/main/docs/adr/012a-migration-substrate-in-core.md)).

## License

GPL-2.0-or-later.
