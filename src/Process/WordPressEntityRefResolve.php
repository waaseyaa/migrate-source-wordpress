<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;

/**
 * Convert a destination UUID (typically produced by a chained
 * {@see \Waaseyaa\Migration\Plugin\Process\LookupProcessor}) into the
 * destination's storage identifier.
 *
 * `LookupProcessor` returns `WriteResult::$destinationUuid` — a string. Many
 * destination reference fields (`Node.uid`, `Term.parent_id`, ...) are
 * integer foreign keys, not UUID columns. This plugin closes that gap via an
 * application-supplied resolver closure: the app is the only party that
 * knows its own entity storage identifier column, so the seam is a closure
 * rather than a `waaseyaa/entity-storage` dependency baked into this
 * connector package.
 *
 * Typical resolver implementation, using the canonical
 * `Waaseyaa\EntityStorage\EntityRepository::findBy()` lookup (see
 * `docs/customization.md` "Resolving references through the id-map"):
 *
 * ```php
 * $resolver = function (string $entityType, string $uuid) use ($repositories): int|string|null {
 *     $matches = $repositories[$entityType]->findBy(['uuid' => $uuid]);
 *     return $matches[0]?->get('id');
 * };
 * ```
 *
 * Chain this AFTER a `LookupProcessor` step:
 * `['author_login', new WordPressAuthorIdResolve(...), new LookupProcessor(...), new WordPressEntityRefResolve($resolver, 'account')]`.
 *
 * @api
 *
 * @spec G-019 — id-map reference resolution
 */
final class WordPressEntityRefResolve implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_entity_ref_resolve';

    public const string ON_MISS_NULL = 'null';
    public const string ON_MISS_FAIL = 'fail';

    /**
     * @param \Closure(string, string): (int|string|null) $resolver Resolves `(destinationEntityType, destinationUuid)` to a storage identifier, or `null` if unresolvable.
     * @param string $destinationEntityType Destination entity type id passed to `$resolver` verbatim. Non-empty.
     * @param string $onMiss One of {@see self::ON_MISS_NULL} (default) or {@see self::ON_MISS_FAIL}.
     *
     * @throws \InvalidArgumentException If $destinationEntityType is empty or $onMiss is unrecognised.
     */
    public function __construct(
        private readonly \Closure $resolver,
        private readonly string $destinationEntityType,
        private readonly string $onMiss = self::ON_MISS_NULL,
    ) {
        if ($destinationEntityType === '') {
            throw new \InvalidArgumentException('WordPressEntityRefResolve::$destinationEntityType must be a non-empty string.');
        }
        if ($onMiss !== self::ON_MISS_NULL && $onMiss !== self::ON_MISS_FAIL) {
            throw new \InvalidArgumentException(\sprintf(
                'WordPressEntityRefResolve::$onMiss must be %s or %s, got %s.',
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
     * @throws ProcessException When the chained value is missing/unresolvable and `$onMiss === 'fail'`.
     */
    public function transform(mixed $value, ProcessContext $context): mixed
    {
        if (!is_string($value) || $value === '') {
            return $this->handleMiss($context, 'WordPressEntityRefResolve: no destination uuid to resolve.');
        }

        $resolved = ($this->resolver)($this->destinationEntityType, $value);
        if ($resolved === null) {
            return $this->handleMiss($context, \sprintf(
                'WordPressEntityRefResolve: resolver returned no storage id for entity type %s, uuid %s.',
                var_export($this->destinationEntityType, true),
                var_export($value, true),
            ));
        }

        return $resolved;
    }

    private function handleMiss(ProcessContext $context, string $message): mixed
    {
        if ($this->onMiss === self::ON_MISS_FAIL) {
            throw new ProcessException(
                processCode: 'WORDPRESS_ENTITY_REF_MISS',
                sourceField: $context->destinationField,
                migrationId: $context->migrationId,
                message: $message,
            );
        }

        return null;
    }
}
