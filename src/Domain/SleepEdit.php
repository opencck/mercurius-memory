<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * An edit sleep made by itself under the owner's standing permission.
 *
 * spec: brief/memory.md#постоянное-разрешение
 */
final class SleepEdit {
    public const COPY = 'copy';
    public const MERGE = 'merge';
    public const CONFLICT = 'conflict';
    public const KINDS = [self::COPY, self::MERGE, self::CONFLICT];

    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        /** the fact that stayed; null when it is gone */
        public readonly ?int $kept,
        /** for a merge: the version of the kept fact the edit made */
        public readonly ?int $keptVersion,
        /** the fact sleep ended; null when it is gone */
        public readonly ?int $ended,
        public readonly bool $undone,
    ) {}
}
