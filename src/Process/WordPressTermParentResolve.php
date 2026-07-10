<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migrate\Source\WordPress\Migration\WpTermsToTaxonomy;
use Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Resolve a term's `parent_slug` field (emitted by
 * {@see \Waaseyaa\Migrate\Source\WordPress\Source\WordPressTaxonomySource})
 * into a destination reference to the parent term.
 *
 * WordPress terms reference their parent by slug within the same taxonomy —
 * this plugin composes the same `"{taxonomy}:{slug}"` key
 * {@see WordPressTermsResolve} uses (via `$slugToTermId`, built from
 * {@see WordPressTaxonomySource::slugIndex()}), reading the record's own
 * `taxonomy_name` field to disambiguate same-named slugs across taxonomies.
 *
 * A record with no parent (`parent_slug === null`) resolves to `null`
 * without invoking `$onMiss` — that is the normal, expected shape for a
 * top-level term, not a miss.
 *
 * @api
 *
 * @spec G-019 — id-map reference resolution (term hierarchy)
 */
final class WordPressTermParentResolve implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_term_parent_resolve';

    public const string ON_MISS_NULL = 'null';
    public const string ON_MISS_FAIL = 'fail';

    /**
     * @param array<string, int|string> $slugToTermId `"{taxonomy}:{slug}"` → `wp:term_id`.
     * @param \Closure(string, string): (int|string|null)|null $refResolve Resolves `(destinationEntityType, destinationUuid)` to a storage identifier. `null` leaves the value as a destination UUID string.
     * @param string $taxonomyField Source field carrying the record's own taxonomy name. Non-empty.
     * @param string $migration Sibling migration id whose id-map is consulted. Non-empty.
     * @param string $sourceType `SourceId::$sourceType` for the term lookup. Non-empty.
     * @param string $destinationEntityType Destination entity type id passed to `$refResolve`. Non-empty.
     * @param string $onMiss One of {@see self::ON_MISS_NULL} (default) or {@see self::ON_MISS_FAIL}. Only applies once a non-null `parent_slug` fails to resolve.
     *
     * @throws \InvalidArgumentException If any required string is empty, or $onMiss is unrecognised.
     */
    public function __construct(
        private readonly array $slugToTermId,
        private readonly ?\Closure $refResolve = null,
        private readonly string $taxonomyField = 'taxonomy_name',
        private readonly string $migration = WpTermsToTaxonomy::MIGRATION_ID,
        private readonly string $sourceType = 'wp_term',
        private readonly string $destinationEntityType = 'taxonomy_term',
        private readonly string $onMiss = self::ON_MISS_NULL,
    ) {
        if ($taxonomyField === '') {
            throw new \InvalidArgumentException('WordPressTermParentResolve::$taxonomyField must be a non-empty string.');
        }
        if ($migration === '') {
            throw new \InvalidArgumentException('WordPressTermParentResolve::$migration must be a non-empty string.');
        }
        if ($sourceType === '') {
            throw new \InvalidArgumentException('WordPressTermParentResolve::$sourceType must be a non-empty string.');
        }
        if ($destinationEntityType === '') {
            throw new \InvalidArgumentException('WordPressTermParentResolve::$destinationEntityType must be a non-empty string.');
        }
        if ($onMiss !== self::ON_MISS_NULL && $onMiss !== self::ON_MISS_FAIL) {
            throw new \InvalidArgumentException(\sprintf(
                'WordPressTermParentResolve::$onMiss must be %s or %s, got %s.',
                var_export(self::ON_MISS_NULL, true),
                var_export(self::ON_MISS_FAIL, true),
                var_export($onMiss, true),
            ));
        }
    }

    public function id(): string
    {
        return self::PLUGIN_ID;
    }

    public function stability(): string
    {
        return 'stable';
    }

    /**
     * @throws ProcessException When `parent_slug` is set but unresolvable and `$onMiss === 'fail'`.
     */
    public function transform(mixed $value, ProcessContext $context): mixed
    {
        $parentSlug = $value ?? $context->sourceRecord->field('parent_slug');
        if (!is_string($parentSlug) || $parentSlug === '') {
            // No parent — a top-level term. Not a miss.
            return null;
        }

        $taxonomy = $context->sourceRecord->field($this->taxonomyField);
        if (!is_string($taxonomy) || $taxonomy === '') {
            return $this->handleMiss($context, 'WordPressTermParentResolve: source record has no taxonomy_name to compose the parent key.');
        }

        $wpTermId = $this->slugToTermId[$taxonomy . ':' . $parentSlug] ?? null;
        if ($wpTermId === null) {
            return $this->handleMiss($context, \sprintf(
                'WordPressTermParentResolve: no wp:term_id in the slug index for %s:%s.',
                $taxonomy,
                $parentSlug,
            ));
        }

        $lookup = $context->lookup;
        $writeResult = $lookup($this->migration, new SourceId(
            sourceType: $this->sourceType,
            keys: ['id' => (string) $wpTermId],
        ));

        if (!$writeResult instanceof WriteResult) {
            return $this->handleMiss($context, \sprintf(
                'WordPressTermParentResolve: no id-map row in migration %s for wp:term_id %s.',
                var_export($this->migration, true),
                var_export($wpTermId, true),
            ));
        }

        $uuid = $writeResult->destinationUuid;
        if ($this->refResolve === null) {
            return $uuid;
        }

        $storageId = ($this->refResolve)($this->destinationEntityType, $uuid);
        if ($storageId === null) {
            return $this->handleMiss($context, \sprintf(
                'WordPressTermParentResolve: refResolve returned no storage id for entity type %s, uuid %s.',
                var_export($this->destinationEntityType, true),
                var_export($uuid, true),
            ));
        }

        return $storageId;
    }

    private function handleMiss(ProcessContext $context, string $message): mixed
    {
        if ($this->onMiss === self::ON_MISS_FAIL) {
            throw new ProcessException(
                processCode: 'WORDPRESS_TERM_PARENT_MISS',
                sourceField: $context->destinationField,
                migrationId: $context->migrationId,
                message: $message,
            );
        }

        return null;
    }
}
