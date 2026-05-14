<?php

declare(strict_types=1);

namespace Waaseyaa\Migrate\Source\WordPress\Media;

/**
 * The operation performed by a {@see MediaCopier::copy()} call.
 *
 * @api
 */
enum MediaCopyOperation: string
{
    /**
     * Target already existed with the expected size — no I/O performed.
     */
    case Skipped = 'skipped';

    /**
     * Local file was copied into a freshly-created target.
     */
    case Copied = 'copied';

    /**
     * Target already existed but its size differed from the source/expected;
     * it was replaced.
     */
    case Replaced = 'replaced';

    /**
     * Target was streamed from an HTTP source into a freshly-created target.
     */
    case Fetched = 'fetched';
}
