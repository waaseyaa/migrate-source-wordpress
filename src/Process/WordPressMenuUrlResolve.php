<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Process;

use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Preserve custom menu URLs and resolve `post_type` objects through the posts id-map.
 *
 * WordPress taxonomy menu items are intentionally not handled here: their public
 * URL depends on the destination taxonomy route contract, which is distinct from
 * the post system-path contract. They continue to produce `null` until an
 * application supplies a taxonomy-aware process map.
 *
 * @api
 */
final readonly class WordPressMenuUrlResolve implements ProcessPluginInterface
{
    public const string PLUGIN_ID = 'wordpress_menu_url_resolve';

    /**
     * @param \Closure(string $entityType, string $uuid): (int|string|null) $uuidToId
     */
    public function __construct(
        private \Closure $uuidToId,
        private string $postsMigrationId,
        private string $destinationEntityType = 'node',
        private string $systemPathPrefix = '/node/',
    ) {
    }

    public function id(): string
    {
        return self::PLUGIN_ID;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        $url = $context->sourceRecord->field('url');
        if (is_string($url) && $url !== '') {
            return $url;
        }

        if ($context->sourceRecord->field('item_type') !== 'post_type') {
            return null;
        }

        $objectId = $context->sourceRecord->field('object_id');
        if (!is_int($objectId) && !is_string($objectId)) {
            throw $this->lookupMiss($context, 'Post object menu item has no scalar object_id.');
        }

        $lookup = $context->lookup;
        $result = $lookup($this->postsMigrationId, new SourceId('wp_post', ['id' => (string) $objectId]));
        if (!$result instanceof WriteResult) {
            throw $this->lookupMiss($context, sprintf(
                'No posts id-map row for WordPress menu object id %s.',
                var_export($objectId, true),
            ));
        }

        $destinationId = ($this->uuidToId)($this->destinationEntityType, $result->destinationUuid);
        if (!is_int($destinationId) && (!is_string($destinationId) || $destinationId === '')) {
            throw $this->lookupMiss($context, sprintf(
                'No destination id for WordPress menu object id %s (uuid %s).',
                var_export($objectId, true),
                var_export($result->destinationUuid, true),
            ));
        }

        return $this->systemPathPrefix . $destinationId;
    }

    private function lookupMiss(ProcessContext $context, string $message): ProcessException
    {
        return new ProcessException(
            processCode: ProcessException::CODE_LOOKUP_MISS,
            sourceField: 'object_id',
            migrationId: $context->migrationId,
            message: $message,
        );
    }
}
