<?php

declare(strict_types=1);

namespace Mercurius\Memory\Domain;

/**
 * A question memory must always answer with its fact, set by the owner.
 *
 * spec: brief/memory.md#пробы-владельца
 */
final class Probe {
    public function __construct(
        public readonly int $id,
        public readonly string $question,
        public readonly int $factId,
        /** the owner was told it is missing; told again only after it was found again */
        public readonly bool $missing,
    ) {}
}
